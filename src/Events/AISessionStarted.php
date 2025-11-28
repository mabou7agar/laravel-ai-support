<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Session Started Event - Fired when a new AI session begins
 */
class AISessionStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public ?string $userId,
        public string $engine,
        public string $model,
        public array $metadata = []
    ) {}
}
