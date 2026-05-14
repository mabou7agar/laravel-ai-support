<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentRunMaintenanceService;

class CleanupExpiredAgentRunsCommand extends Command
{
    protected $signature = 'ai-engine:agent-runs:cleanup-expired
                            {--days= : Delete expired runs older than this many days}
                            {--dry-run : Report matching runs without deleting them}
                            {--json : Print JSON report}';

    protected $description = 'Delete expired AI agent runs after the configured retention window';

    public function handle(AgentRunMaintenanceService $maintenance): int
    {
        $days = (int) ($this->option('days') ?: config('ai-agent.run_safety.expired_cleanup_days', 30));
        $report = $maintenance->cleanupExpired($days, (bool) $this->option('dry-run'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Matched {$report['matched']} expired agent run(s); deleted {$report['deleted']}.");

        return self::SUCCESS;
    }
}
