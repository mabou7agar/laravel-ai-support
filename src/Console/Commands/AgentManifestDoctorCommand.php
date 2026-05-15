<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentManifestDoctor;

class AgentManifestDoctorCommand extends Command
{
    protected $signature = 'ai:manifest:doctor
                            {--json : Output diagnostics as JSON}';

    protected $description = 'Inspect agent manifest skills, actions, tools, and flow references';

    public function handle(AgentManifestDoctor $doctor): int
    {
        $report = $doctor->inspect();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        $summary = $report['summary'];
        $this->info("Skills: {$summary['skills']}");
        $this->line("Actions: {$summary['actions']}");
        $this->line("Tools: {$summary['tools']}");
        $this->line("Errors: {$summary['errors']}");
        $this->line("Warnings: {$summary['warnings']}");

        $this->newLine();
        $this->info('Developer commands');
        foreach ($report['developer_commands'] as $group => $commands) {
            $this->line($group.': '.implode(', ', $commands));
        }

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
