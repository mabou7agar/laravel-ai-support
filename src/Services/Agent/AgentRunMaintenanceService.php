<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Carbon;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunStepRepository;

class AgentRunMaintenanceService
{
    public function __construct(
        private readonly ?AgentRunStepRepository $steps = null,
        private readonly ?AgentRunEventStreamService $events = null
    ) {
    }

    public function recoverStuck(int $minutes, bool $dryRun = false): array
    {
        $cutoff = now()->subMinutes(max(1, $minutes));
        $query = AIAgentRun::query()
            ->whereIn('status', [
                AIAgentRun::STATUS_PENDING,
                AIAgentRun::STATUS_RUNNING,
            ])
            ->where(function ($query) use ($cutoff) {
                $query->where('updated_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNotNull('started_at')
                            ->where('started_at', '<=', $cutoff);
                    });
            });

        $runs = $query->get();
        if (!$dryRun) {
            foreach ($runs as $run) {
                $this->failStuckRun($run);
            }
        }

        return [
            'matched' => $runs->count(),
            'updated' => $dryRun ? 0 : $runs->count(),
            'run_ids' => $runs->pluck('id')->all(),
            'cutoff' => $cutoff->toIso8601String(),
        ];
    }

    private function failStuckRun(AIAgentRun $run): void
    {
        $reason = 'Recovered as failed because the run was stuck.';
        $now = now();

        $recoveryStep = $this->stepRepository()->create($run, [
            'step_key' => 'recovery',
            'type' => 'recovery',
            'action' => 'auto_fail',
            'source' => 'maintenance',
            'status' => AIAgentRun::STATUS_FAILED,
            'error' => $reason,
            'started_at' => $now,
            'failed_at' => $now,
            'completed_at' => $now,
            'metadata' => [
                'recovery' => [
                    'reason' => $reason,
                    'recovered_at' => $now->toISOString(),
                    'previous_status' => $run->status,
                ],
            ],
        ]);

        $metadata = $run->metadata ?? [];
        $metadata['recovery'] = [
            'reason' => $reason,
            'recovered_at' => $now->toISOString(),
            'previous_status' => $run->status,
            'recovery_step_id' => $recoveryStep->id,
        ];

        $finalResponse = [
            'message' => $reason,
            'success' => false,
            'metadata' => [
                'auto_failed' => true,
                'recovery_reason' => $reason,
            ],
        ];

        $run->update([
            'status' => AIAgentRun::STATUS_FAILED,
            'failure_reason' => $reason,
            'failed_at' => $now,
            'final_response' => $finalResponse,
            'metadata' => $metadata,
        ]);

        $this->eventStream()->emit(
            AgentRunEventStreamService::RUN_FAILED,
            $run->refresh(),
            $recoveryStep,
            [
                'error' => $reason,
                'auto_failed' => true,
            ]
        );
    }

    private function stepRepository(): AgentRunStepRepository
    {
        return $this->steps ?? app(AgentRunStepRepository::class);
    }

    private function eventStream(): AgentRunEventStreamService
    {
        return $this->events ?? app(AgentRunEventStreamService::class);
    }

    public function cleanupExpired(int $days, bool $dryRun = false): array
    {
        $cutoff = now()->subDays(max(0, $days));
        $query = AIAgentRun::query()
            ->where('status', AIAgentRun::STATUS_EXPIRED)
            ->where(function ($query) use ($cutoff) {
                $query->where('updated_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNotNull('expired_at')
                            ->where('expired_at', '<=', $cutoff);
                    });
            });

        $runs = $query->get();
        $runIds = $runs->pluck('id')->all();

        if (!$dryRun && $runIds !== []) {
            AIAgentRun::query()->whereIn('id', $runIds)->delete();
        }

        return [
            'matched' => count($runIds),
            'deleted' => $dryRun ? 0 : count($runIds),
            'run_ids' => $runIds,
            'cutoff' => $cutoff instanceof Carbon ? $cutoff->toIso8601String() : (string) $cutoff,
        ];
    }
}
