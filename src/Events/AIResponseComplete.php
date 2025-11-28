<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Response Complete Event - Fired when AI response is fully completed
 */
class AIResponseComplete
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public ?string $userId,
        public string $engine,
        public string $model,
        public string $content,
        public int $tokensUsed,
        public float $latency,
        public bool $success = true,
        public array $metadata = []
    ) {}
}
