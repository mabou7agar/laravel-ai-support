<?php

declare(strict_types=1);

namespace LaravelAIEngine\Listeners;

use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Services\WebhookManager;

class SendWebhookNotification
{
    public function __construct(
        protected WebhookManager $webhookManager
    ) {}

    /**
     * Handle AI request started event.
     */
    public function handleStarted(AIRequestStarted $event): void
    {
        if (!config('ai-engine.webhooks.enabled', false)) {
            return;
        }

        $this->webhookManager->notifyRequestStarted($event);
    }

    /**
     * Handle AI request completed event.
     */
    public function handleCompleted(AIRequestCompleted $event): void
    {
        if (!config('ai-engine.webhooks.enabled', false)) {
            return;
        }

        $this->webhookManager->notifyRequestCompleted($event);
    }
}
