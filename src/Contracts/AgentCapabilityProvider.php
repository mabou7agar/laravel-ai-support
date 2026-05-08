<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentCapabilityDocument;

interface AgentCapabilityProvider
{
    /**
     * @return iterable<int, AgentCapabilityDocument|array<string, mixed>>
     */
    public function capabilities(): iterable;
}
