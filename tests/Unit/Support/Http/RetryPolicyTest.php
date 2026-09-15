<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Support\Http\RetryPolicy;
use LaravelAIEngine\Tests\TestCase;

final class RetryPolicyTest extends TestCase
{
    /** @var array<int, int> */
    private array $slept = [];

    private function policy(int $maxAttempts = 3, int $maxDelayMs = 2000, int $maxTotalDelayMs = 4000): RetryPolicy
    {
        return new RetryPolicy(
            enabled: true,
            maxAttempts: $maxAttempts,
            baseDelayMs: 100,
            maxDelayMs: $maxDelayMs,
            maxTotalDelayMs: $maxTotalDelayMs,
            sleeper: function (int $ms): void {
                $this->slept[] = $ms;
            },
            jitter: static fn (int $delay): int => $delay,
        );
    }

    private function client(RetryPolicy $policy, MockHandler $mock): Client
    {
        return $policy->guzzleClient(['handler' => HandlerStack::create($mock)]);
    }

    public function test_retries_429_and_503_with_exponential_backoff(): void
    {
        $mock = new MockHandler([
            new Response(429),
            new Response(503),
            new Response(200, [], 'ok'),
        ]);

        $response = $this->client($this->policy(), $mock)->get('https://api.example.test/v1');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', (string) $response->getBody());
        self::assertSame([100, 200], $this->slept);
        self::assertSame(0, $mock->count());
    }

    public function test_retries_connection_errors(): void
    {
        $mock = new MockHandler([
            new ConnectException('Connection refused', new Request('POST', 'https://api.example.test')),
            new Response(200, [], 'ok'),
        ]);

        $response = $this->client($this->policy(), $mock)->post('https://api.example.test', ['json' => ['a' => 1]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([100], $this->slept);
    }

    public function test_honours_retry_after_seconds(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '1']),
            new Response(200),
        ]);

        $this->client($this->policy(), $mock)->get('https://api.example.test');

        self::assertSame([1000], $this->slept);
    }

    public function test_retry_after_beyond_the_cap_is_not_slept(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '30']),
            new Response(200),
        ]);

        try {
            $this->client($this->policy(), $mock)->get('https://api.example.test');
            self::fail('Expected the 429 to be returned without waiting 30s.');
        } catch (ClientException $e) {
            self::assertSame(429, $e->getResponse()->getStatusCode());
        }

        self::assertSame([], $this->slept);
        self::assertSame(1, $mock->count());
    }

    /**
     * @dataProvider nonRetryableStatuses
     */
    public function test_does_not_retry_client_errors(int $status): void
    {
        $mock = new MockHandler([new Response($status), new Response(200)]);

        try {
            $this->client($this->policy(), $mock)->get('https://api.example.test');
            self::fail('Expected a client error.');
        } catch (ClientException $e) {
            self::assertSame($status, $e->getResponse()->getStatusCode());
        }

        self::assertSame([], $this->slept);
        self::assertSame(1, $mock->count());
    }

    public static function nonRetryableStatuses(): array
    {
        return ['400' => [400], '401' => [401], '403' => [403], '404' => [404], '422' => [422]];
    }

    public function test_stops_after_max_attempts_and_total_delay_budget(): void
    {
        $mock = new MockHandler([new Response(503), new Response(503), new Response(503), new Response(200)]);
        $client = $this->policy(maxAttempts: 3)->guzzleClient([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        self::assertSame(503, $client->get('https://api.example.test')->getStatusCode());
        self::assertSame([100, 200], $this->slept);

        $this->slept = [];
        $mock = new MockHandler([new Response(503), new Response(503), new Response(200)]);
        $client = $this->policy(maxAttempts: 5, maxTotalDelayMs: 150)->guzzleClient([
            'handler' => HandlerStack::create($mock),
            'http_errors' => false,
        ]);

        self::assertSame(503, $client->get('https://api.example.test')->getStatusCode());
        self::assertSame([100], $this->slept);
    }

    public function test_default_config_is_bounded(): void
    {
        $policy = RetryPolicy::fromConfig();

        self::assertTrue($policy->isEnabled());
        self::assertSame(3, $policy->maxAttempts());
        self::assertLessThanOrEqual(2000, (int) config('ai-engine.http.retry.max_delay_ms'));

        config()->set('ai-engine.http.retry.enabled', false);
        self::assertFalse(RetryPolicy::fromConfig()->isEnabled());
    }

    public function test_provider_drivers_build_clients_with_the_retry_middleware(): void
    {
        $driver = new AnthropicEngineDriver(['api_key' => 'test-key', 'base_url' => 'https://api.anthropic.com']);
        $client = (new \ReflectionProperty(AnthropicEngineDriver::class, 'httpClient'))->getValue($driver);
        $handler = $client->getConfig('handler');

        self::assertInstanceOf(HandlerStack::class, $handler);
        self::assertStringContainsString(RetryPolicy::MIDDLEWARE_NAME, (string) $handler);
    }

    public function test_laravel_http_drivers_retry_transient_errors(): void
    {
        $this->app->instance(RetryPolicy::class, $this->policy());
        Http::fake([
            'openrouter.ai/*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push([
                    'id' => 'gen-1',
                    'model' => 'openai/gpt-4o-mini',
                    'choices' => [['message' => ['content' => 'recovered'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                ], 200),
        ]);

        $driver = new OpenRouterEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://openrouter.ai/api/v1',
        ]);

        $response = $driver->generate(new AIRequest('hi', EngineEnum::OpenRouter, 'openai/gpt-4o-mini'));

        self::assertTrue($response->isSuccessful(), (string) $response->getError());
        self::assertSame('recovered', $response->getContent());
        self::assertSame([100], $this->slept);
        Http::assertSentCount(2);
    }

    public function test_laravel_http_drivers_do_not_retry_auth_errors(): void
    {
        $this->app->instance(RetryPolicy::class, $this->policy());
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

        $driver = new OpenRouterEngineDriver([
            'api_key' => 'test-key',
            'base_url' => 'https://openrouter.ai/api/v1',
        ]);

        $response = $driver->generate(new AIRequest('hi', EngineEnum::OpenRouter, 'openai/gpt-4o-mini'));

        self::assertFalse($response->isSuccessful());
        self::assertSame([], $this->slept);
        Http::assertSentCount(1);
    }

    public function test_engine_proxy_retry_skips_permanent_failures_and_caps_sleep(): void
    {
        $this->app->instance(RetryPolicy::class, $this->policy());
        config()->set('ai-engine.http.retry.max_delay_ms', 500);

        $proxy = new class(app(\LaravelAIEngine\Services\UnifiedEngineManager::class)) extends EngineProxy {
            public function exposeSleep(int $attempt): void
            {
                $this->sleepForRetry($attempt, 'exponential');
            }

            public function exposePermanent(\Throwable $e): bool
            {
                return $this->isPermanentFailure($e);
            }
        };

        $proxy->exposeSleep(3); // would be 4000ms uncapped
        self::assertSame([500], $this->slept);

        $request = new Request('POST', 'https://api.example.test');
        self::assertTrue($proxy->exposePermanent(new ClientException('bad', $request, new Response(401))));
        self::assertFalse($proxy->exposePermanent(new ClientException('slow down', $request, new Response(429))));
        self::assertFalse($proxy->exposePermanent(new \RuntimeException('unknown')));
    }
}
