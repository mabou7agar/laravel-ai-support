<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

class AgentRunStreamed
{
    public function __construct(
        public readonly array $event
    ) {}
}
