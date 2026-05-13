<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\ProviderToolRunResult;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;

class ProviderToolRunService
{
    public function __construct(
        private readonly ProviderToolRunRepository $runRepository,
        private readonly ProviderToolApprovalRepository $approvalRepository,
        private readonly ProviderToolPolicyService $policy,
        private readonly ProviderToolApprovalService $approvals,
        private readonly ProviderToolAuditService $audit
    ) {}

    public function prepare(string $provider, AIRequest $request, array $tools, array $requestPayload = []): ProviderToolRunResult
    {
        $tools = $this->policy->normalizeTools($tools);
        $metadata = $request->getMetadata();
        $run = $this->resolveRun($provider, $request, $tools, $requestPayload);
        $approved = [];
        $pending = [];
        $requiredTools = [];

        foreach ($tools as $tool) {
            if (!$this->policy->requiresApproval($tool)) {
                continue;
            }

            $toolName = $this->policy->toolName($tool);
            $requiredTools[] = $toolName;

            $approvedApproval = $this->approvalRepository->approvedForRunAndTool((int) $run->id, $toolName);
            if ($approvedApproval !== null) {
                $approved[] = $approvedApproval;
                continue;
            }

            $approvalKeys = (array) ($metadata['provider_tool_approval_keys'] ?? []);
            $approvedByKey = $this->approvalRepository->approvedByKeys((int) $run->id, $approvalKeys)
                ->firstWhere('tool_name', $toolName);

            if ($approvedByKey !== null) {
                $approved[] = $approvedByKey;
                continue;
            }

            $pending[] = $this->approvals->requestApproval(
                $run,
                $tool,
                $this->resolveActorId($metadata),
                ['request_metadata' => $metadata]
            );
        }

        if ($pending !== []) {
            $run = $this->runRepository->update($run, [
                'status' => 'awaiting_approval',
                'awaiting_approval_at' => now(),
            ]);
            $this->audit->record('provider_tool_run.awaiting_approval', $run, null, [
                'required_tools' => $requiredTools,
                'pending_approval_keys' => array_map(static fn ($approval): string => $approval->approval_key, $pending),
            ], $metadata, $this->resolveActorId($metadata));
        } else {
            $run = $this->runRepository->update($run, [
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
            ]);
            $this->audit->record('provider_tool_run.started', $run, null, [
                'tool_names' => array_column($tools, 'type'),
            ], $metadata, $this->resolveActorId($metadata));
        }

        return new ProviderToolRunResult($run, $approved, $pending, array_values(array_unique($requiredTools)));
    }

    public function complete(AIProviderToolRun $run, array $responsePayload = [], array $metadata = []): AIProviderToolRun
    {
        $run = $this->runRepository->update($run, [
            'status' => 'completed',
            'response_payload' => $responsePayload,
            'provider_request_id' => $responsePayload['id'] ?? $run->provider_request_id,
            'metadata' => array_merge($run->metadata ?? [], $metadata),
            'completed_at' => now(),
        ]);

        $this->audit->record('provider_tool_run.completed', $run, null, [
            'provider_request_id' => $run->provider_request_id,
        ], $metadata);

        return $run;
    }

    public function fail(AIProviderToolRun $run, string $error, array $metadata = []): AIProviderToolRun
    {
        $run = $this->runRepository->update($run, [
            'status' => 'failed',
            'error' => $error,
            'metadata' => array_merge($run->metadata ?? [], $metadata),
            'failed_at' => now(),
        ]);

        $this->audit->record('provider_tool_run.failed', $run, null, ['error' => $error], $metadata);

        return $run;
    }

    private function resolveRun(string $provider, AIRequest $request, array $tools, array $requestPayload): AIProviderToolRun
    {
        $metadata = $request->getMetadata();
        $existing = $this->runRepository->find($metadata['provider_tool_run_id'] ?? null);
        if ($existing !== null) {
            return $existing;
        }

        return $this->runRepository->create([
            'uuid' => (string) Str::uuid(),
            'provider' => $provider,
            'engine' => $request->getEngine()->value,
            'ai_model' => $request->getModel()->value,
            'status' => 'created',
            'request_id' => $metadata['request_id'] ?? null,
            'conversation_id' => $metadata['conversation_id'] ?? null,
            'user_id' => $this->resolveActorId($metadata),
            'tool_names' => array_values(array_unique(array_column($tools, 'type'))),
            'request_payload' => config('ai-engine.provider_tools.lifecycle.store_payloads', true) ? $requestPayload : [],
            'metadata' => $metadata,
        ]);
    }

    private function resolveActorId(array $metadata): ?string
    {
        foreach (['user_id', 'actor_id', 'requested_by'] as $key) {
            if (isset($metadata[$key]) && $metadata[$key] !== '') {
                return (string) $metadata[$key];
            }
        }

        return null;
    }
}
