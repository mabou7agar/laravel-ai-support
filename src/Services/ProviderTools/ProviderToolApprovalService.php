<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;

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
            'tool_run_id' => $run->id,
            'provider' => $run->provider,
            'tool_name' => $toolName,
            'risk_level' => $this->policy->riskLevel($tool),
            'status' => 'pending',
            'requested_by' => $requestedBy,
            'tool_payload' => $tool,
            'metadata' => $metadata,
            'requested_at' => now(),
        ]);

        $this->audit->record('provider_tool_approval.requested', $run, $approval, [
            'tool_name' => $toolName,
            'risk_level' => $approval->risk_level,
        ], $metadata, $requestedBy);

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

        return $approval;
    }
}
