<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\AgentOrchestrationInspector;
use LaravelAIEngine\Services\Diagnostics\TestEverythingRunner;

class TestAgentOrchestrationCommand extends Command
{
    protected $signature = 'ai:test-orchestration
                            {--phpunit=./vendor/bin/phpunit : PHPUnit binary relative to package root}
                            {--no-phpunit : Only inspect registered tools, skills, and sub-agents}
                            {--stop-on-failure : Stop PHPUnit stages after the first failure}
                            {--fail-on-warning : Treat orchestration warnings as failures}
                            {--max-complexity= : Override ai-agent.orchestration.max_complexity}
                            {--json : Print JSON report}';

    protected $description = 'Validate agent, tool, and sub-agent links and run focused orchestration tests';

    public function handle(AgentOrchestrationInspector $inspector, TestEverythingRunner $runner): int
    {
        $report = $inspector->inspect([
            'max_complexity' => $this->option('max-complexity') !== null
                ? (int) $this->option('max-complexity')
                : config('ai-agent.orchestration.max_complexity', 80),
        ]);

        $stageResults = [];
        if (!(bool) $this->option('no-phpunit')) {
            $stageResults = $runner->runStages($this->stages(), (bool) $this->option('stop-on-failure'));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'orchestration' => $report->toArray(),
                'test_stages' => $stageResults,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->printReport($report->toArray());

            if ($stageResults !== []) {
                $this->newLine();
                $this->table(['Stage', 'Status', 'Duration (ms)', 'Exit'], array_map(
                    fn (array $result): array => [
                        $result['name'],
                        $result['status'],
                        number_format((float) $result['duration_ms'], 2),
                        (string) $result['exit_code'],
                    ],
                    $stageResults
                ));
            }
        }

        $stageFailures = array_values(array_filter($stageResults, static fn (array $result): bool => $result['status'] !== 'passed'));
        foreach ($stageFailures as $failure) {
            $this->newLine();
            $this->error(sprintf('Failed stage: %s', $failure['name']));
            $this->line($failure['output'] !== '' ? $failure['output'] : '(no output captured)');
        }

        if (!$report->passed((bool) $this->option('fail-on-warning')) || $stageFailures !== []) {
            return self::FAILURE;
        }

        if (!(bool) $this->option('json')) {
            $this->info('Agent orchestration links and focused tests passed.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{name:string, command:string, workdir:string}>
     */
    protected function stages(): array
    {
        return [
            [
                'name' => 'agent_orchestration_core',
                'workdir' => $this->packagePath(),
                'command' => $this->buildShellCommand([
                    $this->phpunitBinary(),
                    'tests/Unit/Services/Agent',
                    'tests/Unit/Console/Commands/TestAgentOrchestrationCommandTest.php',
                ]),
            ],
        ];
    }

    protected function printReport(array $report): void
    {
        $metrics = $report['metrics'] ?? [];

        $this->info('Agent orchestration graph');
        $this->table(['Metric', 'Value'], array_map(
            static fn (string $key, int|float $value): array => [$key, (string) $value],
            array_keys($metrics),
            $metrics
        ));

        $issues = $report['issues'] ?? [];
        if ($issues === []) {
            $this->info('No missing links or complexity violations found.');
            return;
        }

        $this->warn('Issues');
        $this->table(['Severity', 'Code', 'Subject', 'Message'], array_map(
            static fn (array $issue): array => [
                $issue['severity'] ?? '',
                $issue['code'] ?? '',
                $issue['subject'] ?? '',
                $issue['message'] ?? '',
            ],
            $issues
        ));
    }

    protected function phpunitBinary(): string
    {
        return trim((string) $this->option('phpunit')) ?: './vendor/bin/phpunit';
    }

    protected function packagePath(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param array<int, string> $commandParts
     */
    protected function buildShellCommand(array $commandParts): string
    {
        $commandParts = $this->withPhpBinaryForPhpunit($commandParts);
        $command = implode(' ', array_map('escapeshellarg', $commandParts));

        return sprintf('bash -lc %s', escapeshellarg('set -e; '.$command));
    }

    /**
     * @param  array<int, string>  $commandParts
     * @return array<int, string>
     */
    protected function withPhpBinaryForPhpunit(array $commandParts): array
    {
        $binary = (string) ($commandParts[0] ?? '');

        if ($binary !== '' && preg_match('#(^|/)phpunit(\\.phar)?$#', $binary) === 1) {
            array_unshift($commandParts, PHP_BINARY);
        }

        return $commandParts;
    }
}
