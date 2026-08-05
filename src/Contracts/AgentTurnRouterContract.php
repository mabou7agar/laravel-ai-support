<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentTurnDecisionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface AgentTurnRouterContract
{
    /** @param array<string, mixed> $options */
    public function route(
        string $message,
        UnifiedActionContext $context,
        array $options = [],
    ): AgentTurnDecisionDTO;
}
