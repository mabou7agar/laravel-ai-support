<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface ExecutionHandlerContract
{
    public function action(): string;

    public function handle(
        RoutingDecision $decision,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse;
}
