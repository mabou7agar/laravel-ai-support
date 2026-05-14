<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentRunMaintenanceService;

class RecoverStuckAgentRunsCommand extends Command
{
    protected $signature = 'ai-engine:agent-runs:recover-stuck
                            {--minutes= : Mark pending/running runs older than this many minutes as failed}
                            {--dry-run : Report matching runs without updating them}
                            {--json : Print JSON report}';

    protected $description = 'Recover stuck AI agent runs';

    public function handle(AgentRunMaintenanceService $maintenance): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('ai-agent.run_safety.stuck_after_minutes', 30));
        $report = $maintenance->recoverStuck($minutes, (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Matched {$report['matched']} stuck agent run(s); updated {$report['updated']}.");

        return self::SUCCESS;
    }
}
