<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolAuditRepository;

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

        $this->auditRepository->create([
            'uuid' => (string) Str::uuid(),
            'tool_run_id' => $run?->id,
            'approval_id' => $approval?->id,
            'event' => $event,
            'provider' => $run?->provider ?? $approval?->provider,
            'tool_name' => $approval?->tool_name ?? ($payload['tool_name'] ?? null),
            'actor_id' => $actorId,
            'payload' => $payload,
            'metadata' => $metadata,
        ]);
    }
}
