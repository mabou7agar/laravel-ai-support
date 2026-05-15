<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Diagnostics;

class TestEverythingRunner
{
    /**
     * @param  array<int, array{name:string, command:string, workdir:string}>  $stages
     * @return array<int, array{name:string, command:string, workdir:string, status:string, exit_code:int, duration_ms:float, output:string}>
     */
    public function runStages(array $stages, bool $stopOnFailure = false): array
    {
        $results = [];

        foreach ($stages as $stage) {
            $result = $this->runStage($stage);
            $results[] = $result;

            if ($stopOnFailure && $result['status'] === 'failed') {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array{name:string, command:string, workdir:string}  $stage
     * @return array{name:string, command:string, workdir:string, status:string, exit_code:int, duration_ms:float, output:string}
     */
    protected function runStage(array $stage): array
    {
        $startedAt = microtime(true);
        $output = '';
        $exitCode = 1;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $this->normalizeCommand($stage['command']),
            $descriptors,
            $pipes,
            $stage['workdir']
        );

        if (! is_resource($process)) {
            return [
                'name' => $stage['name'],
                'command' => $this->redactSecrets($stage['command']),
                'workdir' => $stage['workdir'],
                'status' => 'failed',
                'exit_code' => 1,
                'duration_ms' => (microtime(true) - $startedAt) * 1000,
                'output' => $this->redactSecrets('Unable to start process.'),
            ];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $output = trim((string) $stdout."\n".(string) $stderr);

        return [
            'name' => $stage['name'],
            'command' => $this->redactSecrets($stage['command']),
            'workdir' => $stage['workdir'],
            'status' => $exitCode === 0 ? 'passed' : 'failed',
            'exit_code' => $exitCode,
            'duration_ms' => (microtime(true) - $startedAt) * 1000,
            'output' => $this->redactSecrets($output),
        ];
    }

    protected function normalizeCommand(string $command): string
    {
        return (string) preg_replace(
            '/^\s*php(?=\s|$)/',
            escapeshellarg(PHP_BINARY),
            $command,
            1
        );
    }

    protected function redactSecrets(string $value): string
    {
        return (string) preg_replace_callback(
            '/\b([A-Z0-9_]*(?:API[_-]?KEY|PASSWORD|SECRET|TOKEN|CREDENTIAL)[A-Z0-9_]*)=([^\s;]+)/i',
            static fn (array $matches): string => $matches[1].'=[redacted]',
            $value
        );
    }
}
