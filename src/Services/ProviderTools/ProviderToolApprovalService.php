<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;

class ProviderToolApprovalService
{
    public function __construct(
        private readonly ProviderToolApprovalRepository $approvalRepository,
        private readonly ProviderToolPolicyService $policy,
        private readonly ProviderToolAuditService $audit
    ) {}

    public function requestApproval(
        AIProviderToolRun $run,
        array $tool,
        ?string $requestedBy = null,
        array $metadata = []
    ): AIProviderToolApproval {
        $toolName = $this->policy->toolName($tool);
        $existing = $this->approvalRepository->pendingForRunAndTool((int) $run->id, $toolName);
        if ($existing !== null) {
            return $existing;
        }

        $approval = $this->approvalRepository->create([
            'approval_key' => (string) Str::uuid(),
            'agent_run_step_id' => $metadata['agent_run_step_id'] ?? $run->agent_run_step_id,
            'tool_run_id' => $run->id,
            'provider' => $run->provider,
            'tool_name' => $toolName,
            'risk_level' => $this->policy->riskLevel($tool),
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'tool_payload' => $tool,
            'metadata' => $metadata,
            'requested_at' => now(),
            'expires_at' => $this->expiresAt(),
        ]);

        $this->audit->record('provider_tool_approval.requested', $run, $approval, [
            'tool_name' => $toolName,
            'risk_level' => $approval->risk_level,
        ], $metadata, $requestedBy);
        $this->emitApprovalEvent(AgentRunEventStreamService::APPROVAL_REQUIRED, $run, [
            'approval_key' => $approval->approval_key,
            'tool_name' => $toolName,
            'risk_level' => $approval->risk_level,
        ]);

        return $approval;
    }

    public function approve(string $approvalKey, ?string $actorId = null, ?string $reason = null, array $metadata = []): AIProviderToolApproval
    {
        return $this->resolve($approvalKey, 'approved', $actorId, $reason, $metadata);
    }

    public function reject(string $approvalKey, ?string $actorId = null, ?string $reason = null, array $metadata = []): AIProviderToolApproval
    {
        return $this->resolve($approvalKey, 'rejected', $actorId, $reason, $metadata);
    }

    public function updateResumePayload(string $approvalKey, array $payload, ?string $actorId = null): AIProviderToolApproval
    {
        $approval = $this->approvalRepository->findByKeyOrFail($approvalKey);
        $this->assertNotExpired($approval);

        $metadata = array_merge($approval->metadata ?? [], [
            'resume_payload' => $payload,
            'resume_payload_updated_by' => $actorId,
            'resume_payload_updated_at' => now()->toISOString(),
        ]);

        $approval = $this->approvalRepository->update($approval, [
            'metadata' => $metadata,
        ]);

        $this->audit->record('provider_tool_approval.resume_payload_updated', $approval->run, $approval, [
            'tool_name' => $approval->tool_name,
            'resume_payload_keys' => array_keys($payload),
        ], $metadata, $actorId);

        return $approval;
    }

    public function expire(string $approvalKey, ?string $actorId = null, ?string $reason = null): AIProviderToolApproval
    {
        $approval = $this->approvalRepository->findByKeyOrFail($approvalKey);
        if ($approval->status !== 'pending') {
            return $approval;
        }

        $approval = $this->approvalRepository->update($approval, [
            'status' => 'expired',
            'resolved_by' => $actorId,
            'reason' => $reason ?: 'Approval expired.',
            'resolved_at' => now(),
        ]);

        $this->audit->record('provider_tool_approval.expired', $approval->run, $approval, [
            'tool_name' => $approval->tool_name,
            'reason' => $approval->reason,
        ], $approval->metadata ?? [], $actorId);

        return $approval;
    }

    private function resolve(
        string $approvalKey,
        string $status,
        ?string $actorId,
        ?string $reason,
        array $metadata
    ): AIProviderToolApproval {
        $approval = $this->approvalRepository->findByKey($approvalKey);
        if ($approval === null) {
            throw new \InvalidArgumentException("Provider tool approval {$approvalKey} was not found.");
        }

        $this->assertNotExpired($approval);

        $approval = $this->approvalRepository->update($approval, [
            'status' => $status,
            'resolved_by' => $actorId,
            'reason' => $reason,
            'metadata' => array_merge($approval->metadata ?? [], $metadata),
            'resolved_at' => now(),
        ]);

        $this->audit->record("provider_tool_approval.{$status}", $approval->run, $approval, [
            'tool_name' => $approval->tool_name,
            'reason' => $reason,
        ], $metadata, $actorId);
        if ($approval->run instanceof AIProviderToolRun) {
            $this->emitApprovalEvent(AgentRunEventStreamService::APPROVAL_RESOLVED, $approval->run, [
                'approval_key' => $approval->approval_key,
                'tool_name' => $approval->tool_name,
                'status' => $status,
                'reason' => $reason,
            ]);
        }

        return $approval;
    }

    private function assertNotExpired(AIProviderToolApproval $approval): void
    {
        if ($approval->status !== 'pending' || $approval->expires_at === null || $approval->expires_at->isFuture()) {
            return;
        }

        $this->expire($approval->approval_key);

        throw new \RuntimeException("Provider tool approval [{$approval->approval_key}] has expired.");
    }

    private function expiresAt(): mixed
    {
        $minutes = (int) config('ai-engine.provider_tools.approvals.expires_after_minutes', 0);

        return $minutes > 0 ? now()->addMinutes($minutes) : null;
    }

    private function emitApprovalEvent(string $event, AIProviderToolRun $run, array $payload): void
    {
        app(AgentRunEventStreamService::class)->emit(
            $event,
            $run->agent_run_id,
            $run->agent_run_step_id,
            array_merge($payload, ['provider_tool_run_id' => $run->uuid]),
            ['trace_id' => $run->metadata['trace_id'] ?? null]
        );
    }
}
