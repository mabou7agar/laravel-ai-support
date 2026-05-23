<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class AiNativeToolExecutor
{
    public function __construct(
        private readonly AgentTaskStateService $taskState,
        private readonly AiNativeStateStore $stateStore,
        private readonly ?AgentExecutionPolicyService $policy = null
    ) {}

    public function execute(AgentTool $tool, array $params, UnifiedActionContext $context): ActionResult
    {
        $policy = $this->policy();
        if (!$policy->canUseTool($tool->getName(), ['context' => $context])) {
            return ActionResult::failure($policy->blockedMessage('tool', $tool->getName()));
        }

        return $tool->execute($params, $context);
    }

    public function recordResult(array &$state, string $toolName, array $params, ActionResult $result, bool $writeTool = false): void
    {
        $state['tool_results'][] = [
            'tool' => $toolName,
            'params' => $this->stateStore->redactedArray($params),
            'result' => $this->stateStore->redactedArray($result->toArray()),
        ];

        $this->taskState->recordToolResult($state, $toolName, $params, $result, $writeTool);
    }

    public function withConfirmationForValidation(AgentTool $tool, array $params): array
    {
        if (array_key_exists('confirmed', $tool->getParameters())) {
            $params['confirmed'] = true;
        }

        return $params;
    }

    private function policy(): AgentExecutionPolicyService
    {
        return $this->policy ?? app(AgentExecutionPolicyService::class);
    }
}
