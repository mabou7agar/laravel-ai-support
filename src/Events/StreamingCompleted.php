<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamingCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $fullContent,
        public int $chunkCount
    ) {}
}

