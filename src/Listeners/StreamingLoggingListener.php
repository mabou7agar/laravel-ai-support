<?php

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIStreamingError;
use LaravelAIEngine\Events\AIFailoverTriggered;
use LaravelAIEngine\Events\AIProviderHealthChanged;
use Illuminate\Support\Facades\Log;

/**
 * Streaming Logging Listener
 * 
 * Logs streaming-related events for debugging and monitoring
 */
class StreamingLoggingListener
{
    /**
     * Handle streaming error event
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        Log::error('AI Streaming Error', [
            'session_id' => $event->sessionId,
            'engine' => $event->engine,
            'error_message' => $event->errorMessage,
            'error_code' => $event->errorCode,
            'exception' => $event->exception?->getMessage(),
            'metadata' => $event->metadata,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle failover triggered event
     */
    public function handleFailoverTriggered(AIFailoverTriggered $event): void
    {
        Log::warning('AI Failover Triggered', [
            'request_id' => $event->requestId,
            'primary_engine' => $event->primaryEngine,
            'fallback_engine' => $event->fallbackEngine,
            'reason' => $event->reason,
            'metadata' => $event->metadata,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Handle provider health changed event
     */
    public function handleProviderHealthChanged(AIProviderHealthChanged $event): void
    {
        $logLevel = $event->currentStatus === 'healthy' ? 'info' : 'warning';
        
        Log::log($logLevel, 'AI Provider Health Changed', [
            'engine' => $event->engine,
            'previous_status' => $event->previousStatus,
            'current_status' => $event->currentStatus,
            'reason' => $event->reason,
            'metadata' => $event->metadata,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
