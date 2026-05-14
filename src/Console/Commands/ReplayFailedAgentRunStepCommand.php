<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentRunRecoveryService;

class ReplayFailedAgentRunStepCommand extends Command
{
    protected $signature = 'ai-engine:agent-runs:replay-step
                            {step : Failed step id or uuid}
                            {--reason= : Optional replay reason}
                            {--json : Print JSON report}';

    protected $description = 'Create a pending replay step from a failed AI agent run step';

    public function handle(AgentRunRecoveryService $recovery): int
    {
        $reason = trim((string) ($this->option('reason') ?? ''));
        $metadata = ['replayed_by_command' => true];
        if ($reason !== '') {
            $metadata['replay_reason'] = $reason;
        }

        $step = $recovery->replayFailedStep($this->argument('step'), [
            'metadata' => $metadata,
        ]);

        $report = [
            'replay_step_id' => $step->id,
            'replay_step_uuid' => $step->uuid,
            'run_id' => $step->run_id,
            'status' => $step->status,
            'step_key' => $step->step_key,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Created replay step {$step->uuid} for run {$step->run_id}.");

        return self::SUCCESS;
    }
}
