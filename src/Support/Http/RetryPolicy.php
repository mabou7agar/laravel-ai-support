<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException as LaravelRequestException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared, bounded retry policy for provider HTTP calls.
 *
 * Retries only transient failures — HTTP 429, retryable 5xx and connection
 * errors — with exponential backoff plus jitter, honouring Retry-After. Other
 * 4xx responses (bad request, auth, permission, not found) are never retried.
 *
 * Sleeping happens inside the request path (Octane / FPM workers), so both the
 * per-attempt delay and the total time slept for one call are capped. When a
 * provider asks for a Retry-After longer than the per-attempt cap, the policy
 * gives up immediately so engine failover can take over instead of blocking.
 *
 * Config: ai-engine.http.retry.{enabled,max_attempts,base_delay_ms,max_delay_ms,max_total_delay_ms}
 */
class RetryPolicy
{
    public const MIDDLEWARE_NAME = 'ai_engine_retry';

    /** 5xx statuses that signal a permanent condition and must not be retried. */
    private const NON_RETRYABLE_5XX = [501, 505, 506, 508, 510, 511];

    /** @var callable(int):void */
    private $sleeper;

    /** @var callable(int):int */
    private $jitter;

    public function __construct(
        private bool $enabled = true,
        private int $maxAttempts = 3,
        private int $baseDelayMs = 250,
        private int $maxDelayMs = 2000,
        private int $maxTotalDelayMs = 4000,
        ?callable $sleeper = null,
        ?callable $jitter = null,
    ) {
        $this->maxAttempts = max(1, $this->maxAttempts);
        $this->baseDelayMs = max(0, $this->baseDelayMs);
        $this->maxDelayMs = max(0, $this->maxDelayMs);
        $this->maxTotalDelayMs = max(0, $this->maxTotalDelayMs);
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            if ($milliseconds > 0) {
                usleep($milliseconds * 1000);
            }
        };
        // Equal jitter: a random delay in [delay/2, delay].
        $this->jitter = $jitter ?? static fn (int $delay): int => $delay <= 1
            ? $delay
            : random_int(intdiv($delay, 2), $delay);
    }

    public static function fromConfig(): self
    {
        $config = (array) config('ai-engine.http.retry', []);

        return new self(
            enabled: (bool) ($config['enabled'] ?? true),
            maxAttempts: (int) ($config['max_attempts'] ?? 3),
            baseDelayMs: (int) ($config['base_delay_ms'] ?? 250),
            maxDelayMs: (int) ($config['max_delay_ms'] ?? 2000),
            maxTotalDelayMs: (int) ($config['max_total_delay_ms'] ?? 4000),
        );
    }

    /**
     * Resolve the policy from the container (so tests can swap the sleeper),
     * falling back to config when no container binding exists.
     */
    public static function resolve(): self
    {
        try {
            if (function_exists('app') && app()->bound(self::class)) {
                return app(self::class);
            }
        } catch (\Throwable) {
            // fall through to config
        }

        return self::fromConfig();
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->maxAttempts > 1;
    }

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function shouldRetryStatus(int $status): bool
    {
        if ($status === 429) {
            return true;
        }

        return $status >= 500 && $status <= 599 && !in_array($status, self::NON_RETRYABLE_5XX, true);
    }

    public function isRetryableException(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectException || $exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof GuzzleRequestException) {
            $response = $exception->getResponse();

            return $response !== null && $this->shouldRetryStatus($response->getStatusCode());
        }

        if ($exception instanceof LaravelRequestException) {
            return $this->shouldRetryStatus($exception->response->status());
        }

        return false;
    }

    /**
     * Delay (ms) before the next attempt, or null when the call must not be
     * retried again (attempts exhausted, Retry-After beyond the cap, or the
     * total sleep budget would be exceeded).
     */
    public function nextDelayMs(int $attempt, int $sleptMs = 0, ?string $retryAfter = null): ?int
    {
        if (!$this->isEnabled() || $attempt >= $this->maxAttempts) {
            return null;
        }

        $requested = $this->parseRetryAfterMs($retryAfter);
        if ($requested !== null) {
            if ($requested > $this->maxDelayMs) {
                return null;
            }
            $delay = $requested;
        } else {
            $exponential = $this->baseDelayMs * (2 ** max(0, $attempt - 1));
            $delay = (int) ($this->jitter)((int) min($exponential, $this->maxDelayMs));
        }

        $delay = max(0, $delay);
        if ($sleptMs + $delay > $this->maxTotalDelayMs) {
            return null;
        }

        return $delay;
    }

    public function parseRetryAfterMs(?string $header): ?int
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return (int) max(0, round((float) $header * 1000));
        }

        $timestamp = strtotime($header);
        if ($timestamp === false) {
            return null;
        }

        return max(0, ($timestamp - time()) * 1000);
    }

    public function sleep(int $milliseconds): void
    {
        ($this->sleeper)($milliseconds);
    }

    /**
     * Guzzle middleware applying this policy. Works for sync and async
     * handlers and for streamed responses (the status is known before the body).
     */
    public function guzzleMiddleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                if (!$this->isEnabled()) {
                    return $handler($request, $options);
                }

                $attempt = 1;
                $slept = 0;

                $send = function () use (&$send, &$attempt, &$slept, $handler, $request, $options): PromiseInterface {
                    if ($attempt > 1 && $request->getBody()->isSeekable()) {
                        $request->getBody()->rewind();
                    }

                    return $handler($request, $options)->then(
                        function ($response) use (&$send, &$attempt, &$slept) {
                            if (!$response instanceof ResponseInterface
                                || !$this->shouldRetryStatus($response->getStatusCode())) {
                                return $response;
                            }

                            $delay = $this->nextDelayMs($attempt, $slept, $response->getHeaderLine('Retry-After'));
                            if ($delay === null) {
                                return $response;
                            }

                            $this->sleep($delay);
                            $slept += $delay;
                            $attempt++;

                            return $send();
                        },
                        function ($reason) use (&$send, &$attempt, &$slept) {
                            if (!$reason instanceof \Throwable || !$this->isRetryableException($reason)) {
                                return Create::rejectionFor($reason);
                            }

                            $retryAfter = $reason instanceof GuzzleRequestException && $reason->getResponse() !== null
                                ? $reason->getResponse()->getHeaderLine('Retry-After')
                                : null;
                            $delay = $this->nextDelayMs($attempt, $slept, $retryAfter);
                            if ($delay === null) {
                                return Create::rejectionFor($reason);
                            }

                            $this->sleep($delay);
                            $slept += $delay;
                            $attempt++;

                            return $send();
                        }
                    );
                };

                return $send();
            };
        };
    }

    /**
     * A handler stack carrying the retry middleware (idempotent).
     */
    public function handlerStack(?callable $handler = null): HandlerStack
    {
        $stack = HandlerStack::create($handler);
        $stack->push($this->guzzleMiddleware(), self::MIDDLEWARE_NAME);

        return $stack;
    }

    /**
     * Build a Guzzle client whose requests go through this policy.
     */
    public function guzzleClient(array $options = []): Client
    {
        $handler = $options['handler'] ?? null;
        if ($handler instanceof HandlerStack) {
            $handler->remove(self::MIDDLEWARE_NAME);
            $handler->push($this->guzzleMiddleware(), self::MIDDLEWARE_NAME);
            $options['handler'] = $handler;
        } else {
            $options['handler'] = $this->handlerStack(is_callable($handler) ? $handler : null);
        }

        return new Client($options);
    }

    /**
     * Run a Laravel HTTP client call under this policy.
     *
     * The callback performs one attempt and returns an
     * Illuminate\Http\Client\Response (or throws). Used instead of Guzzle
     * middleware for the Laravel client so Http::fake() sequences exercise it.
     *
     * @template T
     * @param callable():T $send
     * @return T
     */
    public function send(callable $send): mixed
    {
        $attempt = 1;
        $slept = 0;

        while (true) {
            try {
                $response = $send();
            } catch (\Throwable $exception) {
                if (!$this->isRetryableException($exception)) {
                    throw $exception;
                }

                $retryAfter = $exception instanceof LaravelRequestException
                    ? $exception->response->header('Retry-After')
                    : null;
                $delay = $this->nextDelayMs($attempt, $slept, $retryAfter);
                if ($delay === null) {
                    throw $exception;
                }

                $this->sleep($delay);
                $slept += $delay;
                $attempt++;

                continue;
            }

            $status = is_object($response) && method_exists($response, 'status') ? (int) $response->status() : null;
            if ($status === null || !$this->shouldRetryStatus($status)) {
                return $response;
            }

            $delay = $this->nextDelayMs($attempt, $slept, (string) $response->header('Retry-After'));
            if ($delay === null) {
                return $response;
            }

            $this->sleep($delay);
            $slept += $delay;
            $attempt++;
        }
    }
}
