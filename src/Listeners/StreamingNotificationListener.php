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

            // Send notifications based on configuration
            $channels = config('ai-engine.notifications.channels', []);
            
            foreach ($channels as $channel => $config) {
                if (!($config['enabled'] ?? false)) {
                    continue;
                }
                
                switch ($channel) {
                    case 'slack':
                        $this->sendSlackNotification($title, $message, $level, $config);
                        break;
                        
                    case 'email':
                        $this->sendEmailNotification($title, $message, $level, $config);
                        break;
                        
                    case 'webhook':
                        $this->sendWebhookNotification($title, $message, $level, $metadata, $config);
                        break;
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to send AI notification', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send Slack notification
     */
    protected function sendSlackNotification(string $title, string $message, string $level, array $config): void
    {
        try {
            $webhook = $config['webhook_url'] ?? null;
            if (!$webhook) {
                return;
            }

            $color = match($level) {
                'critical', 'error' => 'danger',
                'warning' => 'warning',
                default => 'good'
            };

            $payload = [
                'attachments' => [[
                    'color' => $color,
                    'title' => $title,
                    'text' => $message,
                    'footer' => 'Laravel AI Engine',
                    'ts' => now()->timestamp,
                ]]
            ];

            \Http::post($webhook, $payload);
        } catch (\Exception $e) {
            Log::debug('Failed to send Slack notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send email notification
     */
    protected function sendEmailNotification(string $title, string $message, string $level, array $config): void
    {
        try {
            $recipients = $config['recipients'] ?? [];
            if (empty($recipients)) {
                return;
            }

            foreach ($recipients as $email) {
                \Mail::raw($message, function ($mail) use ($email, $title, $level) {
                    $mail->to($email)
                         ->subject("[{$level}] {$title}");
                });
            }
        } catch (\Exception $e) {
            Log::debug('Failed to send email notification', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send webhook notification
     */
    protected function sendWebhookNotification(string $title, string $message, string $level, array $metadata, array $config): void
    {
        try {
            $url = $config['url'] ?? null;
            if (!$url) {
                return;
            }

            $payload = [
                'title' => $title,
                'message' => $message,
                'level' => $level,
                'metadata' => $metadata,
                'timestamp' => now()->toIso8601String(),
            ];

            \Http::post($url, $payload);
        } catch (\Exception $e) {
            Log::debug('Failed to send webhook notification', ['error' => $e->getMessage()]);
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
