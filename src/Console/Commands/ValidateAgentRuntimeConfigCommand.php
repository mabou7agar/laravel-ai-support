<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator;

class ValidateAgentRuntimeConfigCommand extends Command
{
    protected $signature = 'ai:validate-runtime-config
                            {--json : Print JSON report}';

    protected $description = 'Validate agent runtime and tool configuration';

    public function handle(AgentRuntimeConfigValidator $validator): int
    {
        $report = $validator->validate();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['passed'] ? self::SUCCESS : self::FAILURE;
        }

        if ($report['issues'] === []) {
            $this->info('Agent runtime config is valid.');

            return self::SUCCESS;
        }

        $this->table(['Severity', 'Code', 'Subject', 'Message'], array_map(
            static fn (array $issue): array => [
                $issue['severity'],
                $issue['code'],
                $issue['subject'],
                $issue['message'],
            ],
            $report['issues']
        ));

        return $report['passed'] ? self::SUCCESS : self::FAILURE;
    }
}
