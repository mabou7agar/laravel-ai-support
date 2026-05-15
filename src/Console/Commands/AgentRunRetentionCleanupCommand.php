<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentRunRetentionService;

class AgentRunRetentionCleanupCommand extends Command
{
    protected $signature = 'ai:agent-runs:retention-cleanup
                            {--dry-run : Report matching records without changing them}
                            {--json : Print JSON report}
                            {--run-days= : Override run retention days}
                            {--step-days= : Override step retention days}
                            {--trace-days= : Override trace retention days}
                            {--artifact-days= : Override artifact retention days}';

    protected $description = 'Apply AI agent run retention and privacy cleanup rules';

    public function handle(AgentRunRetentionService $retention): int
    {
        $report = $retention->cleanup((bool) $this->option('dry-run'), $this->overrides());

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Deleted {$report['runs_deleted']} run(s), {$report['steps_deleted']} step(s), {$report['artifacts_deleted']} artifact(s); redacted {$report['traces_redacted']} trace payload(s).");

        return self::SUCCESS;
    }

    private function overrides(): array
    {
        return collect([
            'run_days' => $this->option('run-days'),
            'step_days' => $this->option('step-days'),
            'trace_days' => $this->option('trace-days'),
            'artifact_days' => $this->option('artifact-days'),
        ])->filter(static fn ($value): bool => $value !== null && $value !== '')
            ->map(static fn ($value): int => (int) $value)
            ->all();
    }
}
