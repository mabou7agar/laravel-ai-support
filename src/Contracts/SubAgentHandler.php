<?php

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;

interface SubAgentHandler
{
    /**
     * @param array<string, SubAgentResult> $previousResults
     */
    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult;
}
