<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class LangGraphRunMapper
{
    public function __construct(
        private readonly LangGraphInterruptMapper $interrupts
    ) {
    }

    public function interrupts(): LangGraphInterruptMapper
    {
        return $this->interrupts;
    }

    public function startPayload(string $message, string $sessionId, mixed $userId, array $options = []): array
    {
        return [
            'thread_id' => (string) ($options['langgraph_thread_id'] ?? $options['agent_run_uuid'] ?? $sessionId),
            'input' => [
                'message' => $message,
                'session_id' => $sessionId,
                'user_id' => $userId,
                'agent_run_id' => $options['agent_run_id'] ?? null,
                'agent_run_uuid' => $options['agent_run_uuid'] ?? null,
                'tenant_id' => $options['tenant_id'] ?? null,
                'workspace_id' => $options['workspace_id'] ?? null,
                'context' => $options,
            ],
            'tools' => $this->toolDescriptors($options),
            'rag_tools' => $this->ragToolDescriptors($options),
            'sub_agents' => $this->subAgentDescriptors($options),
            'metadata' => array_filter([
                'trace_id' => $options['trace_id'] ?? null,
                'runtime' => 'langgraph',
                'tenant_id' => $options['tenant_id'] ?? null,
                'workspace_id' => $options['workspace_id'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    public function resumePayload(string $message, string $sessionId, mixed $userId, array $options = []): array
    {
        $payload = (array) ($options['langgraph_resume_payload'] ?? []);

        return array_filter(array_merge($payload, [
            'message' => $message,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'agent_run_id' => $options['agent_run_id'] ?? null,
            'agent_run_uuid' => $options['agent_run_uuid'] ?? null,
            'agent_run_step_id' => $options['agent_run_step_id'] ?? null,
            'agent_run_step_uuid' => $options['agent_run_step_uuid'] ?? null,
            'tenant_id' => $options['tenant_id'] ?? null,
            'workspace_id' => $options['workspace_id'] ?? null,
            'trace_id' => $options['trace_id'] ?? null,
        ]), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function toResponse(array $run, UnifiedActionContext $context): AgentResponse
    {
        $status = strtolower((string) ($run['status'] ?? 'running'));

        $response = match ($status) {
            'completed', 'complete', 'success' => AgentResponse::success($this->message($run), $run, $context),
            'interrupted', 'interrupt', 'waiting', 'awaiting_approval', 'awaiting_input' => $this->interrupts->toResponse($run, $context),
            'failed', 'error' => AgentResponse::failure($this->message($run, 'LangGraph run failed.'), $run, $context),
            'cancelled', 'canceled' => AgentResponse::failure($this->message($run, 'LangGraph run was cancelled.'), $run, $context),
            default => AgentResponse::needsUserInput(
                message: $this->message($run, 'LangGraph run is still running.'),
                data: $run,
                context: $context,
                requiredInputs: [['name' => 'continue', 'type' => 'hidden']]
            ),
        };

        $response->metadata = array_merge($response->metadata ?? [], [
            'agent_runtime' => 'langgraph',
            'langgraph_run_id' => $run['id'] ?? $run['run_id'] ?? null,
            'langgraph_thread_id' => $run['thread_id'] ?? null,
            'langgraph_status' => $status,
        ]);

        return $response;
    }

    private function message(array $run, string $default = 'LangGraph run completed.'): string
    {
        foreach ([
            $run['output']['message'] ?? null,
            $run['output']['response'] ?? null,
            $run['output']['content'] ?? null,
            $run['message'] ?? null,
            $run['error'] ?? null,
        ] as $candidate) {
            $candidate = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $default;
    }

    private function toolDescriptors(array $options): array
    {
        return array_values(array_map(
            static fn (mixed $tool): array => is_array($tool) ? $tool : ['name' => (string) $tool],
            (array) ($options['tools'] ?? [])
        ));
    }

    private function ragToolDescriptors(array $options): array
    {
        $collections = $options['rag_collections'] ?? $options['collections'] ?? [];
        if ($collections === []) {
            return [];
        }

        return [[
            'name' => 'laravel_rag',
            'type' => 'remote_callback',
            'collections' => array_values((array) $collections),
        ]];
    }

    private function subAgentDescriptors(array $options): array
    {
        return array_values(array_map(
            static fn (mixed $agent): array => is_array($agent) ? $agent : ['agent_id' => (string) $agent],
            (array) ($options['sub_agents'] ?? [])
        ));
    }
}
