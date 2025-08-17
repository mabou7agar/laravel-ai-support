<?php

namespace LaravelAIEngine\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;

class SendWebhookNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public array $backoff = [10, 30, 60, 120, 300]; // Exponential backoff

    public function __construct(
        public string $url,
        public array $payload,
        public string $event,
        public array $headers = []
    ) {}

    public function handle(): void
    {
        $client = new Client([
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
        ]);

        $defaultHeaders = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'LaravelAIEngine/1.0',
            'X-Webhook-Event' => $this->event,
            'X-Webhook-Delivery' => uniqid('delivery_'),
            'X-Webhook-Timestamp' => now()->toISOString(),
        ];

        $headers = array_merge($defaultHeaders, $this->headers);

        try {
            $response = $client->post($this->url, [
                'json' => $this->payload,
                'headers' => $headers,
            ]);

            // Log successful delivery
            logger()->info('Webhook delivered successfully', [
                'url' => $this->url,
                'event' => $this->event,
                'status_code' => $response->getStatusCode(),
                'attempt' => $this->attempts(),
            ]);

        } catch (RequestException $e) {
            $statusCode = $e->getResponse()?->getStatusCode();
            
            logger()->warning('Webhook delivery failed', [
                'url' => $this->url,
                'event' => $this->event,
                'status_code' => $statusCode,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Don't retry for client errors (4xx), but retry for server errors (5xx)
            if ($statusCode && $statusCode >= 400 && $statusCode < 500) {
                $this->fail($e);
                return;
            }

            throw $e; // Will trigger retry for 5xx errors and network issues
        }
    }

    public function failed(Exception $exception): void
    {
        logger()->error('Webhook delivery permanently failed', [
            'url' => $this->url,
            'event' => $this->event,
            'error' => $exception->getMessage(),
            'total_attempts' => $this->attempts(),
            'payload' => $this->payload,
        ]);
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return $this->backoff;
    }
}
