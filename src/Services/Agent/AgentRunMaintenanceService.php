<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Carbon;
use LaravelAIEngine\Models\AIAgentRun;

class AgentRunMaintenanceService
{
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
                $run->update([
                    'status' => AIAgentRun::STATUS_FAILED,
                    'failure_reason' => 'Recovered as failed because the run was stuck.',
                    'failed_at' => now(),
                ]);
            }
        }

        return [
            'matched' => $runs->count(),
            'updated' => $dryRun ? 0 : $runs->count(),
            'run_ids' => $runs->pluck('id')->all(),
            'cutoff' => $cutoff->toIso8601String(),
        ];
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
