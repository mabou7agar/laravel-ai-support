<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;

class AgentRunApprovalService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunStepRepository $steps,
        private readonly ProviderToolRunRepository $providerRuns,
        private readonly ProviderToolApprovalService $approvals,
        private readonly ProviderToolAuditService $audit
    ) {
    }

    public function requestStepApproval(
        AIAgentRunStep $step,
        array $decision,
        ?string $requestedBy = null,
        array $metadata = []
    ): AIProviderToolApproval {
        $providerRun = $this->providerRuns->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $step->run_id,
            'agent_run_step_id' => $step->id,
            'provider' => 'agent',
            'engine' => 'agent',
            'ai_model' => 'orchestrator',
            'status' => 'awaiting_approval',
            'user_id' => $step->run?->user_id,
            'tool_names' => [$this->toolName($decision)],
            'request_payload' => [
                'decision' => $decision,
                'step' => [
                    'uuid' => $step->uuid,
                    'type' => $step->type,
                    'action' => $step->action,
                ],
            ],
            'metadata' => array_merge($metadata, [
                'agent_run_id' => $step->run_id,
                'agent_run_step_id' => $step->id,
                'approval_scope' => 'agent_step',
            ]),
            'awaiting_approval_at' => now(),
        ]);

        $approval = $this->approvals->requestApproval($providerRun, [
            'type' => $this->toolName($decision),
            'approval_policy' => 'always',
            'requires_approval' => true,
            'risk_level' => $decision['risk_level'] ?? 'medium',
            'decision' => $decision,
        ], $requestedBy, array_merge($metadata, [
            'agent_run_id' => $step->run_id,
            'agent_run_step_id' => $step->id,
            'approval_scope' => 'agent_step',
        ]));

        $this->steps->transition($step, AIAgentRun::STATUS_WAITING_APPROVAL, [
            'provider_tool_run_id' => $providerRun->id,
            'approvals' => array_values(array_unique(array_merge($step->approvals ?? [], [$approval->approval_key]))),
            'metadata' => array_merge($step->metadata ?? [], [
                'approval_scope' => 'agent_step',
            ]),
        ]);

        $this->runs->transition($step->run, AIAgentRun::STATUS_WAITING_APPROVAL, [
            'waiting_at' => now(),
        ]);

        return $approval;
    }

    public function approveStep(string $approvalKey, ?string $actorId = null, ?string $reason = null, array $metadata = []): AIAgentRunStep
    {
        $approval = $this->approvals->approve($approvalKey, $actorId, $reason, $metadata);

        return $this->resumeApprovedStep($approval, $actorId, $metadata);
    }

    public function rejectStep(string $approvalKey, ?string $actorId = null, ?string $reason = null, array $metadata = []): AIAgentRunStep
    {
        $approval = $this->approvals->reject($approvalKey, $actorId, $reason, $metadata);
        $step = $this->steps->findOrFail((int) $approval->agent_run_step_id);

        $step = $this->steps->transition($step, AIAgentRun::STATUS_FAILED, [
            'error' => $reason ?: 'Agent step approval was rejected.',
            'failed_at' => now(),
            'metadata' => array_merge($step->metadata ?? [], [
                'approval_rejected_by' => $actorId,
            ]),
        ]);

        $this->runs->transition($step->run, AIAgentRun::STATUS_FAILED, [
            'failure_reason' => $step->error,
            'failed_at' => now(),
        ]);

        $this->audit->record('agent_step_approval.rejected', $approval->run, $approval, [
            'agent_run_step_id' => $step->id,
        ], array_merge($metadata, ['agent_run_step_id' => $step->id]), $actorId);

        return $step;
    }

    private function resumeApprovedStep(AIProviderToolApproval $approval, ?string $actorId, array $metadata): AIAgentRunStep
    {
        $step = $this->steps->findOrFail((int) $approval->agent_run_step_id);

        $step = $this->steps->transition($step, AIAgentRun::STATUS_PENDING, [
            'metadata' => array_merge($step->metadata ?? [], [
                'approval_approved_by' => $actorId,
            ]),
        ]);

        $this->runs->transition($step->run, AIAgentRun::STATUS_RUNNING);

        $this->audit->record('agent_step_approval.approved', $approval->run, $approval, [
            'agent_run_step_id' => $step->id,
        ], array_merge($metadata, ['agent_run_step_id' => $step->id]), $actorId);

        return $step;
    }

    private function toolName(array $decision): string
    {
        $name = trim((string) ($decision['tool_name'] ?? $decision['action'] ?? 'agent_step'));

        return $name !== '' ? $name : 'agent_step';
    }
}
