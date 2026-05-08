<?php

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface DeterministicAgentHandler
{
    /**
     * Return an AgentResponse when the handler can confidently answer or
     * execute without model routing. Return null to let the normal agent
     * orchestrator continue.
     */
    public function handle(string $message, UnifiedActionContext $context, array $options = []): ?AgentResponse;
}
