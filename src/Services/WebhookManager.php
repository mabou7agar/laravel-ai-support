<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Jobs\SendWebhookNotificationJob;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WebhookManager
{
    private Client $client;
    private array $config;

    public function __construct(Client $client = null)
    {
        $this->config = config('ai-engine.webhooks', []);
        
        $this->client = $client ?? new Client([
            'timeout' => $this->config['timeout'] ?? 10,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'LaravelAIEngine/1.0',
            ],
        ]);
    }

    /**
     * Send webhook notification for AI request started
     */
    public function notifyRequestStarted(AIRequestStarted $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'event' => 'ai_request_started',
            'timestamp' => now()->toISOString(),
            'request_id' => $event->requestId,
            'user_id' => $event->request->userId,
            'engine' => $event->request->engine->value,
            'model' => $event->request->model->value,
            'content_type' => $event->request->model->getContentType(),
            'estimated_credits' => $event->request->model->creditIndex(),
            'metadata' => $event->metadata,
        ];

        $this->sendWebhookQueued('ai_request_started', $payload);
    }

    /**
     * Send webhook notification for AI request completed
     */
    public function notifyRequestCompleted(AIRequestCompleted $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'event' => 'ai_request_completed',
            'timestamp' => now()->toISOString(),
            'request_id' => $event->requestId,
            'user_id' => $event->request->userId,
            'engine' => $event->request->engine->value,
            'model' => $event->request->model->value,
            'success' => $event->response->content !== null,
            'credits_used' => $event->response->creditsUsed,
            'processing_time' => $event->executionTime,
            'content_length' => strlen($event->response->content ?? ''),
            'error' => $event->response->error ?? null,
            'metadata' => $event->metadata,
        ];

        $this->sendWebhookQueued('ai_request_completed', $payload);
    }

    /**
     * Send webhook notification for AI request failed
     */
    public function notifyRequestFailed(string $requestId, string $userId, string $engine, string $error, array $metadata = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'event' => 'ai_request_failed',
            'timestamp' => now()->toISOString(),
            'request_id' => $requestId,
            'user_id' => $userId,
            'engine' => $engine,
            'error' => $error,
            'metadata' => $metadata,
        ];

        $this->sendWebhook('error', $payload);
    }

    /**
     * Send webhook notification for credit balance low
     */
    public function notifyLowCredits(string $userId, float $currentBalance, float $threshold, string $engine = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'event' => 'low_credits',
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'current_balance' => $currentBalance,
            'threshold' => $threshold,
            'engine' => $engine,
        ];

        $this->sendWebhook('low_credits', $payload);
    }

    /**
     * Send webhook notification for rate limit exceeded
     */
    public function notifyRateLimitExceeded(string $userId, string $engine, int $remainingTime): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $payload = [
            'event' => 'rate_limit_exceeded',
            'timestamp' => now()->toISOString(),
            'user_id' => $userId,
            'engine' => $engine,
            'retry_after' => $remainingTime,
        ];

        $this->sendWebhook('rate_limit', $payload);
    }

    /**
     * Send custom webhook notification
     */
    public function sendCustomWebhook(string $event, array $data, string $endpoint = null): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $payload = [
            'event' => $event,
            'timestamp' => now()->toISOString(),
            'data' => $data,
        ];

        return $this->sendWebhook($event, $payload, $endpoint);
    }

    /**
     * Register webhook endpoint for specific events
     */
    public function registerEndpoint(string $event, string $url, array $options = []): void
    {
        $endpoints = Cache::get('ai_engine_webhook_endpoints', []);
        
        $endpoints[$event][] = [
            'url' => $url,
            'secret' => $options['secret'] ?? null,
            'headers' => $options['headers'] ?? [],
            'retry_attempts' => $options['retry_attempts'] ?? 3,
            'timeout' => $options['timeout'] ?? 10,
            'created_at' => now()->toISOString(),
        ];

        Cache::put('ai_engine_webhook_endpoints', $endpoints, now()->addDays(30));
    }

    /**
     * Unregister webhook endpoint
     */
    public function unregisterEndpoint(string $event, string $url): void
    {
        $endpoints = Cache::get('ai_engine_webhook_endpoints', []);
        
        if (isset($endpoints[$event])) {
            $endpoints[$event] = array_filter($endpoints[$event], function ($endpoint) use ($url) {
                return $endpoint['url'] !== $url;
            });
            
            if (empty($endpoints[$event])) {
                unset($endpoints[$event]);
            }
        }

        Cache::put('ai_engine_webhook_endpoints', $endpoints, now()->addDays(30));
    }

    /**
     * Get registered endpoints for an event
     */
    public function getEndpoints(string $event): array
    {
        $endpoints = Cache::get('ai_engine_webhook_endpoints', []);
        return $endpoints[$event] ?? [];
    }

    /**
     * Test webhook endpoint
     */
    public function testEndpoint(string $url, string $secret = null): array
    {
        $payload = [
            'event' => 'webhook_test',
            'timestamp' => now()->toISOString(),
            'test_data' => [
                'message' => 'This is a test webhook from Laravel AI Engine',
                'version' => '1.0.0',
            ],
        ];

        try {
            $headers = ['Content-Type' => 'application/json'];
            
            if ($secret) {
                $headers['X-Webhook-Signature'] = $this->generateSignature($payload, $secret);
            }

            $response = $this->client->post($url, [
                'json' => $payload,
                'headers' => $headers,
                'timeout' => 10,
            ]);

            return [
                'success' => true,
                'status_code' => $response->getStatusCode(),
                'response_time' => $response->getHeader('X-Response-Time')[0] ?? null,
                'message' => 'Webhook test successful',
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status_code' => $e->getResponse() ? $e->getResponse()->getStatusCode() : null,
                'message' => 'Webhook test failed',
            ];
        }
    }

    /**
     * Get webhook delivery logs
     */
    public function getDeliveryLogs(string $event = null, int $limit = 100): array
    {
        $cacheKey = 'ai_engine_webhook_logs' . ($event ? "_{$event}" : '');
        $logs = Cache::get($cacheKey, []);
        
        return array_slice($logs, -$limit);
    }

    /**
     * Clear webhook delivery logs
     */
    public function clearLogs(string $event = null): void
    {
        if ($event) {
            Cache::forget("ai_engine_webhook_logs_{$event}");
        } else {
            // Clear all webhook logs
            $events = ['started', 'completed', 'error', 'low_credits', 'rate_limit'];
            foreach ($events as $eventType) {
                Cache::forget("ai_engine_webhook_logs_{$eventType}");
            }
        }
    }

    /**
     * Send webhook to configured endpoints
     */
    private function sendWebhook(string $event, array $payload, string $customEndpoint = null): bool
    {
        $endpoints = [];
        
        if ($customEndpoint) {
            $endpoints[] = ['url' => $customEndpoint];
        } else {
            // Get configured endpoints
            $configEndpoints = $this->getConfiguredEndpoints($event);
            $registeredEndpoints = $this->getEndpoints($event);
            
            $endpoints = array_merge($configEndpoints, $registeredEndpoints);
        }

        if (empty($endpoints)) {
            return false;
        }

        $success = true;
        
        foreach ($endpoints as $endpoint) {
            try {
                $this->deliverWebhook($endpoint, $payload, $event);
            } catch (\Exception $e) {
                $success = false;
                $this->logWebhookError($event, $endpoint['url'], $e->getMessage());
            }
        }

        return $success;
    }

    /**
     * Deliver webhook to specific endpoint
     */
    private function deliverWebhook(array $endpoint, array $payload, string $event): void
    {
        $url = $endpoint['url'];
        $secret = $endpoint['secret'] ?? $this->config['secret'] ?? null;
        $retryAttempts = $endpoint['retry_attempts'] ?? 3;
        $timeout = $endpoint['timeout'] ?? 10;
        
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'X-Webhook-Event' => $event,
            'X-Webhook-ID' => Str::uuid(),
            'X-Webhook-Timestamp' => now()->timestamp,
        ], $endpoint['headers'] ?? []);

        if ($secret) {
            $headers['X-Webhook-Signature'] = $this->generateSignature($payload, $secret);
        }

        $attempt = 0;
        $lastException = null;

        while ($attempt < $retryAttempts) {
            try {
                $startTime = microtime(true);
                
                $response = $this->client->post($url, [
                    'json' => $payload,
                    'headers' => $headers,
                    'timeout' => $timeout,
                ]);

                $responseTime = (microtime(true) - $startTime) * 1000;
                
                $this->logWebhookDelivery($event, $url, true, $response->getStatusCode(), $responseTime);
                return;
                
            } catch (RequestException $e) {
                $lastException = $e;
                $attempt++;
                
                if ($attempt < $retryAttempts) {
                    // Exponential backoff
                    sleep(pow(2, $attempt - 1));
                }
            }
        }

        // All attempts failed
        $this->logWebhookDelivery($event, $url, false, null, null, $lastException->getMessage());
        throw $lastException;
    }

    /**
     * Generate webhook signature
     */
    private function generateSignature(array $payload, string $secret): string
    {
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return 'sha256=' . hash_hmac('sha256', $jsonPayload, $secret);
    }

    /**
     * Get configured endpoints for an event
     */
    private function getConfiguredEndpoints(string $event): array
    {
        $endpoints = [];
        
        if (isset($this->config['endpoints'][$event])) {
            $url = $this->config['endpoints'][$event];
            $endpoints[] = ['url' => $url];
        }

        // Generic endpoint for all events
        if (isset($this->config['endpoints']['all'])) {
            $url = $this->config['endpoints']['all'];
            $endpoints[] = ['url' => $url];
        }

        return $endpoints;
    }

    /**
     * Log webhook delivery
     */
    private function logWebhookDelivery(string $event, string $url, bool $success, ?int $statusCode, ?float $responseTime, ?string $error = null): void
    {
        $log = [
            'timestamp' => now()->toISOString(),
            'event' => $event,
            'url' => $url,
            'success' => $success,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTime,
            'error' => $error,
        ];

        $cacheKey = "ai_engine_webhook_logs_{$event}";
        $logs = Cache::get($cacheKey, []);
        $logs[] = $log;
        
        // Keep only last 1000 logs per event
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        Cache::put($cacheKey, $logs, now()->addDays(7));

        // Also log to Laravel log
        if ($success) {
            Log::info('Webhook delivered successfully', $log);
        } else {
            Log::error('Webhook delivery failed', $log);
        }
    }

    /**
     * Log webhook error
     */
    private function logWebhookError(string $event, string $url, string $error): void
    {
        Log::error('Webhook delivery error', [
            'event' => $event,
            'url' => $url,
            'error' => $error,
        ]);
    }

    /**
     * Send webhook notification using queue for reliability
     */
    public function sendWebhookQueued(string $event, array $payload, ?string $queue = null): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $endpoints = $this->getEndpoints($event);
        
        foreach ($endpoints as $endpoint) {
            $headers = array_merge(
                $this->config['headers'] ?? [],
                $endpoint['headers'] ?? []
            );

            $job = new SendWebhookNotificationJob(
                url: $endpoint['url'],
                payload: $payload,
                event: $event,
                headers: $headers
            );

            if ($queue) {
                $job->onQueue($queue);
            }

            $job->dispatch();
        }
    }

    /**
     * Check if webhooks are enabled
     */
    private function isEnabled(): bool
    {
        return $this->config['enabled'] ?? false;
    }
}
