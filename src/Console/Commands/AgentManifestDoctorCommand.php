<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentManifestDoctor;

class AgentManifestDoctorCommand extends Command
{
    protected $signature = 'ai-engine:manifest:doctor
                            {--json : Output diagnostics as JSON}';

    protected $description = 'Inspect agent manifest skills, actions, tools, and workflow references';

    public function handle(AgentManifestDoctor $doctor): int
    {
        $report = $doctor->inspect();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $summary = $report['summary'];
        $this->info("Skills: {$summary['skills']}");
        $this->line("Errors: {$summary['errors']}");
        $this->line("Warnings: {$summary['warnings']}");

        if ($report['issues'] === []) {
            $this->newLine();
            $this->info('Manifest doctor found no issues.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Severity', 'Code', 'Message'],
            array_map(static fn (array $issue): array => [
                $issue['severity'] ?? '',
                $issue['code'] ?? '',
                $issue['message'] ?? '',
            ], $report['issues'])
        );

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
