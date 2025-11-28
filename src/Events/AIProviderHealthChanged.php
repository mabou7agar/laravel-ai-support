<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Provider Health Changed Event - Fired when provider health status changes
 */
class AIProviderHealthChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $engine,
        public string $previousStatus,
        public string $currentStatus,
        public ?string $reason = null,
        public array $metadata = []
    ) {}
}
