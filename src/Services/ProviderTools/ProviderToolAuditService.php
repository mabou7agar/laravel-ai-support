<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolAuditRepository;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;

class ProviderToolAuditService
{
    public function __construct(
        private readonly ProviderToolAuditRepository $auditRepository
    ) {}

    public function record(
        string $event,
        ?AIProviderToolRun $run = null,
        ?AIProviderToolApproval $approval = null,
        array $payload = [],
        array $metadata = [],
        ?string $actorId = null
    ): void {
        if ((bool) config('ai-engine.provider_tools.audit.enabled', true) !== true) {
            return;
        }

        $stepId = $metadata['agent_run_step_id'] ?? $run?->agent_run_step_id ?? $approval?->agent_run_step_id;

        $this->auditRepository->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $metadata['agent_run_id'] ?? $run?->agent_run_id ?? $this->resolveAgentRunId($stepId),
            'agent_run_step_id' => $stepId,
            'tool_run_id' => $run?->id,
            'approval_id' => $approval?->id,
            'event' => $event,
            'provider' => $run?->provider ?? $approval?->provider,
            'tool_name' => $approval?->tool_name ?? ($payload['tool_name'] ?? null),
            'runtime' => $metadata['runtime'] ?? $payload['runtime'] ?? null,
            'decision_source' => $metadata['decision_source'] ?? $payload['decision_source'] ?? null,
            'trace_id' => $metadata['trace_id'] ?? $payload['trace_id'] ?? null,
            'actor_id' => $actorId,
            'payload' => $this->policy()->redactSensitive($payload),
            'metadata' => $this->policy()->redactSensitive($metadata),
        ]);
    }

    private function resolveAgentRunId(mixed $stepId): mixed
    {
        if ($stepId === null || $stepId === '') {
            return null;
        }

        return AIAgentRunStep::query()
            ->where('id', $stepId)
            ->value('run_id');
    }

    private function policy(): AgentExecutionPolicyService
    {
        return app()->bound(AgentExecutionPolicyService::class)
            ? app(AgentExecutionPolicyService::class)
            : new AgentExecutionPolicyService();
    }
}
