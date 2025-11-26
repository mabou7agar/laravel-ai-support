<?php

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIStreamingError;
use LaravelAIEngine\Events\AIFailoverTriggered;
use LaravelAIEngine\Events\AIProviderHealthChanged;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Streaming Notification Listener
 * 
 * Sends notifications for critical streaming events
 */
class StreamingNotificationListener
{
    /**
     * Handle streaming error event
     */
    public function handleStreamingError(AIStreamingError $event): void
    {
        // Only notify for critical errors
        if ($this->isCriticalError($event->errorCode)) {
            $this->sendNotification(
                'AI Streaming Critical Error',
                "Session {$event->sessionId} encountered a critical error on {$event->engine}: {$event->errorMessage}",
                'error',
                $event->metadata
            );
        }
    }

    /**
     * Handle failover triggered event
     */
    public function handleFailoverTriggered(AIFailoverTriggered $event): void
    {
        $this->sendNotification(
            'AI Failover Triggered',
            "Request {$event->requestId} failed over from {$event->primaryEngine} to {$event->fallbackEngine}. Reason: {$event->reason}",
            'warning',
            $event->metadata
        );
    }

    /**
     * Handle provider health changed event
     */
    public function handleProviderHealthChanged(AIProviderHealthChanged $event): void
    {
        // Only notify when health degrades
        if ($this->isHealthDegraded($event->previousStatus, $event->currentStatus)) {
            $this->sendNotification(
                'AI Provider Health Degraded',
                "Engine {$event->engine} health changed from {$event->previousStatus} to {$event->currentStatus}. Reason: {$event->reason}",
                'warning',
                $event->metadata
            );
        }
    }

    /**
     * Send notification
     */
    protected function sendNotification(string $title, string $message, string $level, array $metadata = []): void
    {
        try {
            // Log the notification
            Log::channel('ai-notifications')->log($level, $title, [
                'message' => $message,
                'metadata' => $metadata,
                'timestamp' => now()->toIso8601String(),
            ]);

            // TODO: Implement actual notification sending
            // This could be email, Slack, SMS, etc.
            // Example:
            // Notification::route('slack', config('ai-engine.notifications.slack_webhook'))
            //     ->notify(new AIAlertNotification($title, $message, $level));
            
        } catch (\Exception $e) {
            Log::error('Failed to send AI notification', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if error is critical
     */
    protected function isCriticalError(int $errorCode): bool
    {
        // Define critical error codes
        $criticalCodes = [500, 502, 503, 504];
        return in_array($errorCode, $criticalCodes);
    }

    /**
     * Check if health has degraded
     */
    protected function isHealthDegraded(string $previousStatus, string $currentStatus): bool
    {
        $statusPriority = [
            'healthy' => 3,
            'degraded' => 2,
            'unhealthy' => 1,
            'down' => 0,
        ];

        $previousPriority = $statusPriority[$previousStatus] ?? 0;
        $currentPriority = $statusPriority[$currentStatus] ?? 0;

        return $currentPriority < $previousPriority;
    }
}
