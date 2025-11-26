<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Session Ended Event - Fired when an AI session ends
 */
class AISessionEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public ?string $userId,
        public int $totalMessages,
        public int $totalTokens,
        public float $duration,
        public array $metadata = []
    ) {}
}
