<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Action Triggered Event - Fired when an interactive action is triggered
 */
class AIActionTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $actionId,
        public string $actionType,
        public ?string $userId,
        public array $payload,
        public bool $success = true,
        public array $metadata = []
    ) {}
}
