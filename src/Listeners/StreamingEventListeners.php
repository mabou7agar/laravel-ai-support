<?php

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIResponseChunk;
use LaravelAIEngine\Events\AIResponseComplete;
use LaravelAIEngine\Events\AIActionTriggered;
use LaravelAIEngine\Events\AIStreamingError;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Events\AISessionEnded;
use LaravelAIEngine\Events\AIFailoverTriggered;
use LaravelAIEngine\Events\AIProviderHealthChanged;
use LaravelAIEngine\Services\Analytics\AnalyticsManager;
use Illuminate\Support\Facades\Log;

/**
 * Analytics Tracking Listener - Tracks streaming events for analytics
 */
class AnalyticsTrackingListener
{
    public function __construct(
        protected AnalyticsManager $analyticsManager
    ) {}

    /**
     * Handle AI response chunk events
     */
    public function handleResponseChunk(AIResponseChunk $event): void
    {
        $this->analyticsManager->trackStreaming([
            'event_type' => 'chunk',
            'session_id' => $event->sessionId,
            'chunk_index' => $event->chunkIndex,
            'chunk_size' => strlen($event->chunk),
            'metadata' => $event->metadata,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle AI response complete events
     */
    public function handleResponseComplete(AIResponseComplete $event): void
    {
        $this->analyticsManager->trackStreaming([
            'event_type' => 'complete',
            'session_id' => $event->sessionId,
            'response_length' => strlen($event->fullResponse),
            'actions_count' => count($event->actions),
            'metadata' => $event->metadata,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle AI action triggered events
     */
    public function handleActionTriggered(AIActionTriggered $event): void
    {
        $this->analyticsManager->trackAction([
            'session_id' => $event->sessionId,
            'action_id' => $event->actionId,
            'action_type' => $event->actionType,
            'payload_size' => count($event->payload),
            'metadata' => $event->metadata,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle AI streaming error events
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        $this->analyticsManager->trackError([
            'error_type' => 'streaming_error',
            'session_id' => $event->sessionId,
            'error_message' => $event->errorMessage,
            'error_code' => $event->errorCode,
            'context' => $event->context,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle AI session started events
     */
    public function handleSessionStarted(AISessionStarted $event): void
    {
        $this->analyticsManager->trackStreaming([
            'event_type' => 'session_started',
            'session_id' => $event->sessionId,
            'engine' => $event->engine,
            'model' => $event->model,
            'options' => $event->options,
            'timestamp' => now(),
        ]);
    }

    /**
     * Handle AI session ended events
     */
    public function handleSessionEnded(AISessionEnded $event): void
    {
        $this->analyticsManager->trackStreaming([
            'event_type' => 'session_ended',
            'session_id' => $event->sessionId,
            'duration' => $event->duration,
            'stats' => $event->stats,
            'timestamp' => now(),
        ]);
    }
}

/**
 * Logging Listener - Logs streaming events for debugging and monitoring
 */
class StreamingLoggingListener
{
    /**
     * Handle AI response chunk events
     */
    public function handleResponseChunk(AIResponseChunk $event): void
    {
        Log::debug('AI Response Chunk', [
            'session_id' => $event->sessionId,
            'chunk_index' => $event->chunkIndex,
            'chunk_size' => strlen($event->chunk),
            'metadata' => $event->metadata,
        ]);
    }

    /**
     * Handle AI response complete events
     */
    public function handleResponseComplete(AIResponseComplete $event): void
    {
        Log::info('AI Response Complete', [
            'session_id' => $event->sessionId,
            'response_length' => strlen($event->fullResponse),
            'actions_count' => count($event->actions),
        ]);
    }

    /**
     * Handle AI action triggered events
     */
    public function handleActionTriggered(AIActionTriggered $event): void
    {
        Log::info('AI Action Triggered', [
            'session_id' => $event->sessionId,
            'action_id' => $event->actionId,
            'action_type' => $event->actionType,
        ]);
    }

    /**
     * Handle AI streaming error events
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        Log::error('AI Streaming Error', [
            'session_id' => $event->sessionId,
            'error_message' => $event->errorMessage,
            'error_code' => $event->errorCode,
            'context' => $event->context,
        ]);
    }

    /**
     * Handle AI session started events
     */
    public function handleSessionStarted(AISessionStarted $event): void
    {
        Log::info('AI Session Started', [
            'session_id' => $event->sessionId,
            'engine' => $event->engine,
            'model' => $event->model,
        ]);
    }

    /**
     * Handle AI session ended events
     */
    public function handleSessionEnded(AISessionEnded $event): void
    {
        Log::info('AI Session Ended', [
            'session_id' => $event->sessionId,
            'duration' => $event->duration,
            'stats' => $event->stats,
        ]);
    }

    /**
     * Handle AI failover triggered events
     */
    public function handleFailoverTriggered(AIFailoverTriggered $event): void
    {
        Log::warning('AI Provider Failover', [
            'from_provider' => $event->fromProvider,
            'to_provider' => $event->toProvider,
            'reason' => $event->reason,
            'context' => $event->context,
        ]);
    }

    /**
     * Handle AI provider health changed events
     */
    public function handleProviderHealthChanged(AIProviderHealthChanged $event): void
    {
        Log::info('AI Provider Health Changed', [
            'provider' => $event->provider,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus,
            'health_data' => $event->healthData,
        ]);
    }
}

/**
 * Notification Listener - Sends notifications for critical events
 */
class StreamingNotificationListener
{
    /**
     * Handle AI streaming error events
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        // Send notification for critical streaming errors
        if (in_array($event->errorCode, ['CRITICAL_ERROR', 'PROVIDER_DOWN', 'RATE_LIMIT_EXCEEDED'])) {
            // Implementation would depend on notification system
            Log::critical('Critical AI Streaming Error - Notification Sent', [
                'session_id' => $event->sessionId,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
            ]);
        }
    }

    /**
     * Handle AI failover triggered events
     */
    public function handleFailoverTriggered(AIFailoverTriggered $event): void
    {
        // Send notification for provider failovers
        Log::alert('AI Provider Failover - Notification Sent', [
            'from_provider' => $event->fromProvider,
            'to_provider' => $event->toProvider,
            'reason' => $event->reason,
        ]);
    }

    /**
     * Handle AI provider health changed events
     */
    public function handleProviderHealthChanged(AIProviderHealthChanged $event): void
    {
        // Send notification when provider goes unhealthy
        if ($event->newStatus === 'unhealthy' && $event->oldStatus === 'healthy') {
            Log::alert('AI Provider Unhealthy - Notification Sent', [
                'provider' => $event->provider,
                'health_data' => $event->healthData,
            ]);
        }
    }
}

/**
 * Cache Warming Listener - Warms caches based on streaming patterns
 */
class CacheWarmingListener
{
    /**
     * Handle AI session started events
     */
    public function handleSessionStarted(AISessionStarted $event): void
    {
        // Warm cache for frequently used models/engines
        $cacheKey = "ai_model_cache:{$event->engine}:{$event->model}";
        
        // Implementation would warm relevant caches
        Log::debug('Cache Warming Triggered', [
            'session_id' => $event->sessionId,
            'cache_key' => $cacheKey,
        ]);
    }

    /**
     * Handle AI response complete events
     */
    public function handleResponseComplete(AIResponseComplete $event): void
    {
        // Cache successful responses for similar requests
        $cacheKey = "ai_response_cache:{$event->sessionId}";
        
        Log::debug('Response Cached', [
            'session_id' => $event->sessionId,
            'cache_key' => $cacheKey,
        ]);
    }
}
