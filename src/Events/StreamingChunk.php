<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamingChunk
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $chunk,
        public int $chunkIndex
    ) {}
}

