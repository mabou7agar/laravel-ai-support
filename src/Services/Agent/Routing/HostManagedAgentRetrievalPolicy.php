<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing;

use LaravelAIEngine\Contracts\AgentRetrievalPolicyContract;
use LaravelAIEngine\DTOs\AgentRetrievalDecisionDTO;
use LaravelAIEngine\DTOs\AgentTurnDecisionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;

final class HostManagedAgentRetrievalPolicy implements AgentRetrievalPolicyContract
{
    public function decide(
        AgentTurnDecisionDTO $turn,
        UnifiedActionContext $context,
        array $options = [],
    ): AgentRetrievalDecisionDTO {
        return AgentRetrievalDecisionDTO::hostManaged();
    }
}
