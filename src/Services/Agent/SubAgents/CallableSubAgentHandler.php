<?php

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\Contracts\SubAgentHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class CallableSubAgentHandler implements SubAgentHandler
{
    public function __construct(
        protected mixed $handler
    ) {
    }

    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult {
        $result = call_user_func($this->handler, $task, $context, $previousResults, $options);

        return $this->normalizeResult($task, $result);
    }

    protected function normalizeResult(SubAgentTask $task, mixed $result): SubAgentResult
    {
        if ($result instanceof SubAgentResult) {
            return $result;
        }

        if ($result instanceof ActionResult) {
            if ($result->requiresUserInput()) {
                return SubAgentResult::needsUserInput($task->id, $task->agentId, $result->message, $result->data, $result->metadata);
            }

            return $result->success
                ? SubAgentResult::success($task->id, $task->agentId, $result->message, $result->data, $result->metadata)
                : SubAgentResult::failure($task->id, $task->agentId, $result->error ?? 'Sub-agent action failed.', $result->data, $result->metadata);
        }

        if ($result instanceof AgentResponse) {
            if ($result->needsUserInput) {
                return SubAgentResult::needsUserInput($task->id, $task->agentId, $result->message, $result->data, $result->metadata ?? []);
            }

            return $result->success
                ? SubAgentResult::success($task->id, $task->agentId, $result->message, $result->data, $result->metadata ?? [])
                : SubAgentResult::failure($task->id, $task->agentId, $result->message, $result->data, $result->metadata ?? []);
        }

        if (is_array($result)) {
            $success = (bool) ($result['success'] ?? true);

            if (!empty($result['needs_user_input'])) {
                return SubAgentResult::needsUserInput(
                    $task->id,
                    $task->agentId,
                    (string) ($result['message'] ?? 'More information is required.'),
                    $result['data'] ?? null,
                    is_array($result['metadata'] ?? null) ? $result['metadata'] : []
                );
            }

            return $success
                ? SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    isset($result['message']) ? (string) $result['message'] : null,
                    $result['data'] ?? $result,
                    is_array($result['metadata'] ?? null) ? $result['metadata'] : []
                )
                : SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    (string) ($result['error'] ?? $result['message'] ?? 'Sub-agent failed.'),
                    $result['data'] ?? null,
                    is_array($result['metadata'] ?? null) ? $result['metadata'] : []
                );
        }

        return SubAgentResult::success(
            $task->id,
            $task->agentId,
            is_string($result) ? $result : null,
            $result
        );
    }
}
