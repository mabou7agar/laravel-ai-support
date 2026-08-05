<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing;

use LaravelAIEngine\Contracts\AgentTurnRouterContract;
use LaravelAIEngine\DTOs\AgentTurnDecisionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;

final class PassthroughAgentTurnRouter implements AgentTurnRouterContract
{
    public function route(
        string $message,
        UnifiedActionContext $context,
        array $options = [],
    ): AgentTurnDecisionDTO {
        return AgentTurnDecisionDTO::passthrough();
    }
}
