<?php

declare(strict_types=1);

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class LogAIRequest
{
    /**
     * Handle AI request started event.
     */
    public function handleStarted(AIRequestStarted $event): void
    {
        if (!config('ai-engine.logging.enabled', true)) {
            return;
        }

        $logData = [
            'event' => 'ai_request_started',
            'request_id' => $event->requestId,
            'user_id' => $event->request->userId,
            'engine' => $event->request->engine->value,
            'model' => $event->request->model->value,
            'content_type' => $event->request->getContentType(),
            'prompt_length' => strlen($event->request->prompt),
            'timestamp' => now()->toISOString(),
            'metadata' => $event->metadata,
        ];

        // Log to Laravel log
        Log::channel(config('ai-engine.logging.channel', 'stack'))
            ->info('AI Request Started', $logData);

        // Store in cache for analytics (if enabled)
        if (config('ai-engine.analytics.enabled', false)) {
            $this->storeAnalyticsData('started', $logData);
        }
    }

    /**
     * Handle AI request completed event.
     */
    public function handleCompleted(AIRequestCompleted $event): void
    {
        if (!config('ai-engine.logging.enabled', true)) {
            return;
        }

        $logData = [
            'event' => 'ai_request_completed',
            'request_id' => $event->requestId,
            'user_id' => $event->request->userId,
            'engine' => $event->request->engine->value,
            'model' => $event->request->model->value,
            'success' => $event->response->success,
            'credits_used' => $event->response->getCreditsUsed(),
            'processing_time' => $event->executionTime,
            'content_length' => strlen($event->response->content),
            'error' => $event->response->error,
            'timestamp' => now()->toISOString(),
            'metadata' => $event->metadata,
        ];

        // Log to Laravel log
        $logLevel = $event->response->success ? 'info' : 'error';
        Log::channel(config('ai-engine.logging.channel', 'stack'))
            ->log($logLevel, 'AI Request Completed', $logData);

        // Store in cache for analytics (if enabled)
        if (config('ai-engine.analytics.enabled', false)) {
            $this->storeAnalyticsData('completed', $logData);
        }
    }

    /**
     * Store analytics data in cache.
     */
    protected function storeAnalyticsData(string $type, array $data): void
    {
        $cacheKey = "ai_engine_analytics_{$type}_" . now()->format('Y-m-d');
        $ttl = config('ai-engine.analytics.cache_ttl', 86400); // 24 hours

        $existingData = Cache::get($cacheKey, []);
        $existingData[] = $data;

        // Keep only the last 1000 entries per day to prevent memory issues
        if (count($existingData) > 1000) {
            $existingData = array_slice($existingData, -1000);
        }

        Cache::put($cacheKey, $existingData, $ttl);
    }
}
