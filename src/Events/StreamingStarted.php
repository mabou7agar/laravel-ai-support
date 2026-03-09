<?php

namespace LaravelAIEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamingStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $sessionId,
        public array $options = []
    ) {}
}

