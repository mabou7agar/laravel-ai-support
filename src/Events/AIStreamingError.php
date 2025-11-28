<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Streaming Error Event - Fired when streaming encounters an error
 */
class AIStreamingError
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $engine,
        public string $errorMessage,
        public int $errorCode,
        public ?string $userId = null,
        public ?\Throwable $exception = null,
        public array $metadata = []
    ) {}
}
