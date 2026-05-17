<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

class AgentStructuredCollectionCompleted
{
    public function __construct(
        public readonly array $payload
    ) {
    }
}
