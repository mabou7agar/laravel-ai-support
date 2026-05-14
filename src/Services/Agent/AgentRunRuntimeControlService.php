<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Bus;
use LaravelAIEngine\Jobs\ContinueAgentRunJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient;

class AgentRunRuntimeControlService
{
    public function __construct(
        private readonly AgentRunRepository $runs,
        private readonly AgentRunStepRepository $steps,
        private readonly AgentRunApprovalService $approvals,
        private readonly ProviderToolApprovalRepository $providerApprovals,
        private readonly LangGraphRuntimeClient $langGraph,
        private readonly AgentRunEventStreamService $events
    ) {}

    public function resume(int|string|AIAgentRun $run, array $payload = []): array
    {
        $record = $run instanceof AIAgentRun ? $run : $this->runs->findOrFail($run);
        $payload = $this->resolveApprovalPayload($payload);
        $langGraphRunId = $this->langGraphRunId($record, $payload);

        $options = array_merge($payload['options'] ?? [], [
            'agent_runtime' => $record->runtime === 'langgraph' || $langGraphRunId !== null ? 'langgraph' : $record->runtime,
            'langgraph_resume_run_id' => $langGraphRunId,
            'langgraph_resume_payload' => $payload,
            'fallback_to_laravel' => false,
            '_idempotency_key' => $payload['idempotency_key'] ?? 'agent-run-resume:' . $record->uuid . ':' . sha1(json_encode($payload)),
        ]);

        $job = new ContinueAgentRunJob($record->id, (string) ($payload['message'] ?? 'continue'), $options);

        if (($payload['queue'] ?? false) === true) {
            dispatch($job);

            return [
                'queued' => true,
                'run' => $record->refresh()->toArray(),
            ];
        }

        Bus::dispatchSync($job);

        return [
            'queued' => false,
            'run' => $record->refresh()->load('steps')->toArray(),
        ];
    }

    public function cancel(int|string|AIAgentRun $run, array $payload = []): array
    {
        $record = $run instanceof AIAgentRun ? $run : $this->runs->findOrFail($run);
        $langGraphRunId = $this->langGraphRunId($record, $payload);
        $remote = null;

        if ($langGraphRunId !== null) {
            $remote = $this->langGraph->cancelRun($langGraphRunId);
        }

        $step = $this->steps->create($record, [
            'step_key' => 'cancel',
            'type' => 'agent_cancellation',
            'status' => AIAgentRun::STATUS_CANCELLED,
            'action' => 'cancel',
            'source' => $record->runtime,
            'input' => $payload,
            'output' => $remote,
            'metadata' => array_filter([
                'langgraph_run_id' => $langGraphRunId,
                'reason' => $payload['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'completed_at' => now(),
        ]);

        $record = $this->runs->transition($record, AIAgentRun::STATUS_CANCELLED, [
            'current_step' => $step->step_key,
            'metadata' => array_merge($record->metadata ?? [], array_filter([
                'langgraph_run_id' => $langGraphRunId,
                'cancelled_by' => $payload['actor_id'] ?? null,
                'cancel_reason' => $payload['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            'cancelled_at' => now(),
        ]);

        $this->events->emit(AgentRunEventStreamService::RUN_CANCELLED, $record, $step, [
            'reason' => $payload['reason'] ?? null,
            'langgraph_run_id' => $langGraphRunId,
        ], ['trace_id' => $record->metadata['trace_id'] ?? null]);

        return [
            'run' => $record->refresh()->load('steps')->toArray(),
            'remote' => $remote,
        ];
    }

    private function resolveApprovalPayload(array $payload): array
    {
        $approvalKey = $payload['approval_key'] ?? null;
        if ($approvalKey === null || $approvalKey === '') {
            return $payload;
        }

        $approval = $this->providerApprovals->findByKeyOrFail((string) $approvalKey);
        if ($approval->status === 'pending') {
            $this->approvals->approveStep(
                (string) $approvalKey,
                $payload['actor_id'] ?? null,
                $payload['reason'] ?? null,
                ['resume_requested' => true]
            );
            $approval = $approval->refresh();
        }

        return array_merge($approval->metadata['resume_payload'] ?? [], $payload, [
            'approval_key' => $approval->approval_key,
            'approval_status' => $approval->status,
        ]);
    }

    private function langGraphRunId(AIAgentRun $run, array $payload = []): ?string
    {
        foreach ([
            $payload['langgraph_run_id'] ?? null,
            $payload['langgraph_resume_run_id'] ?? null,
            $run->metadata['langgraph_run_id'] ?? null,
            $run->final_response['metadata']['langgraph_run_id'] ?? null,
            $run->final_response['data']['langgraph_run_id'] ?? null,
        ] as $candidate) {
            $candidate = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
