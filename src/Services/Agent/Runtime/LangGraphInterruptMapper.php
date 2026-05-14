<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class LangGraphInterruptMapper
{
    public function toResponse(array $run, UnifiedActionContext $context): AgentResponse
    {
        $interrupt = $this->interrupt($run);
        $message = trim((string) ($interrupt['message'] ?? $interrupt['reason'] ?? 'LangGraph run requires input before it can continue.'));

        return AgentResponse::needsUserInput(
            message: $message,
            data: [
                'runtime' => 'langgraph',
                'langgraph_run_id' => $run['id'] ?? $run['run_id'] ?? null,
                'thread_id' => $run['thread_id'] ?? null,
                'interrupt' => $interrupt,
            ],
            context: $context,
            requiredInputs: $this->requiredInputs($interrupt)
        );
    }

    public function interrupt(array $run): array
    {
        foreach (['interrupt', 'interruption', 'approval', 'pending_input'] as $key) {
            if (is_array($run[$key] ?? null)) {
                return $run[$key];
            }
        }

        return [];
    }

    public function requiresApproval(array $run): bool
    {
        $interrupt = $this->interrupt($run);
        $type = strtolower((string) ($interrupt['type'] ?? $interrupt['kind'] ?? $run['status'] ?? ''));

        return ($interrupt['requires_approval'] ?? $interrupt['approval_required'] ?? false) === true
            || str_contains($type, 'approval')
            || array_key_exists('risk_level', $interrupt)
            || array_key_exists('tool_name', $interrupt);
    }

    public function approvalDecision(array $run): array
    {
        $interrupt = $this->interrupt($run);

        return array_filter([
            'action' => $interrupt['action'] ?? $interrupt['tool_name'] ?? 'langgraph_interrupt',
            'tool_name' => $interrupt['tool_name'] ?? $interrupt['action'] ?? 'langgraph_interrupt',
            'risk_level' => $interrupt['risk_level'] ?? 'medium',
            'message' => $interrupt['message'] ?? $interrupt['reason'] ?? null,
            'runtime' => 'langgraph',
            'langgraph_run_id' => $run['id'] ?? $run['run_id'] ?? null,
            'langgraph_thread_id' => $run['thread_id'] ?? null,
            'interrupt' => $interrupt,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function requiredInputs(array $interrupt): ?array
    {
        $inputs = $interrupt['required_inputs'] ?? $interrupt['inputs'] ?? null;

        return is_array($inputs) ? $inputs : null;
    }
}
