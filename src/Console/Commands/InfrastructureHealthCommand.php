<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class InfrastructureHealthCommand extends Command
{
    protected $signature = 'ai-engine:infra-health
                            {--json : Output report as JSON}
                            {--fail-on-unhealthy : Exit with failure status when checks are not ready}';

    protected $description = 'Run infrastructure readiness checks for AI Engine';

    public function handle(InfrastructureHealthService $service): int
    {
        $report = $service->evaluate();
        $ready = (bool) ($report['ready'] ?? false);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT));
        } else {
            $status = (string) ($report['status'] ?? 'unknown');
            $timestamp = (string) ($report['timestamp'] ?? '');
            $this->line(sprintf('Status: %s', $status));
            if ($timestamp !== '') {
                $this->line(sprintf('Checked at: %s', $timestamp));
            }

            $rows = [];
            foreach ((array) ($report['checks'] ?? []) as $name => $check) {
                $rows[] = [
                    $name,
                    ($check['required'] ?? false) ? 'yes' : 'no',
                    ($check['healthy'] ?? false) ? 'yes' : 'no',
                    (string) ($check['message'] ?? ''),
                ];
            }

            if ($rows !== []) {
                $this->table(['Check', 'Required', 'Healthy', 'Message'], $rows);
            }
        }

        if (!$ready && $this->option('fail-on-unhealthy')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
