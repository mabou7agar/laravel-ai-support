<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\Federation;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Abstraction over node-session continuation/routing so the core agent runtime
 * never depends on the concrete NodeSessionManager (which lives in / moves to the
 * federation package). When no implementation is bound, the core treats federation
 * as absent — a routed_to_node context never exists, so the node paths stay inert.
 */
interface NodeSessionContract
{
    public function shouldContinueSession(string $message, UnifiedActionContext $context): bool;

    public function continueSession(string $message, UnifiedActionContext $context, array $options): ?AgentResponse;

    public function routeToNode(string $requestedResource, string $message, UnifiedActionContext $context, array $options): AgentResponse;
}
