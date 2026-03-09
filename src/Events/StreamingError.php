<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamingError
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public \Throwable $exception
    ) {}
}

