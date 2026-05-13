<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use JsonSerializable;
use LaravelAIEngine\Models\AIProviderToolRun;

class ProviderToolRunResult implements JsonSerializable
{
    public function __construct(
        public readonly AIProviderToolRun $run,
        public readonly array $approvedApprovals = [],
        public readonly array $pendingApprovals = [],
        public readonly array $requiredTools = []
    ) {}

    public function canExecute(): bool
    {
        return $this->pendingApprovals === [];
    }

    public function jsonSerialize(): array
    {
        return [
            'run' => [
                'id' => $this->run->id,
                'uuid' => $this->run->uuid,
                'status' => $this->run->status,
                'provider' => $this->run->provider,
                'model' => $this->run->ai_model,
            ],
            'required_tools' => $this->requiredTools,
            'approved_approvals' => array_map($this->serializeApproval(...), $this->approvedApprovals),
            'pending_approvals' => array_map($this->serializeApproval(...), $this->pendingApprovals),
            'can_execute' => $this->canExecute(),
        ];
    }

    private function serializeApproval(mixed $approval): array
    {
        return [
            'id' => $approval->id,
            'approval_key' => $approval->approval_key,
            'tool_name' => $approval->tool_name,
            'risk_level' => $approval->risk_level,
            'status' => $approval->status,
        ];
    }
}
