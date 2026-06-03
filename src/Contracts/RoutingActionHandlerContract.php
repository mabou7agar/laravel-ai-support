<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface RoutingActionHandlerContract
{
    /**
     * The RoutingDecisionAction const this handler serves.
     */
    public function action(): string;

    public function handle(
        RoutingDecision $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = [],
        ?callable $reroute = null
    ): AgentResponse;
}
