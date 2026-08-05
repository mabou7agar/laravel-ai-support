<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentRetrievalDecisionDTO;
use LaravelAIEngine\DTOs\AgentTurnDecisionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface AgentRetrievalPolicyContract
{
    /** @param array<string, mixed> $options */
    public function decide(
        AgentTurnDecisionDTO $turn,
        UnifiedActionContext $context,
        array $options = [],
    ): AgentRetrievalDecisionDTO;
}
