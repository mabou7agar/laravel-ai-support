<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class TestAgentToolCommand extends Command
{
    protected $signature = 'ai:tools:test
                            {tool : Registered tool name}
                            {--payload= : JSON object payload passed to the tool}
                            {--session= : Session id for the test context}
                            {--user= : User id for the test context}
                            {--json : Output result as JSON}';

    protected $description = 'Execute a registered agent tool with a JSON payload for local testing';

    public function handle(ToolRegistry $tools): int
    {
        $toolName = (string) $this->argument('tool');
        $payload = $this->payload();
        if ($payload === null) {
            return $this->errorResult('Payload must be a valid JSON object.');
        }

        $tool = $tools->get($toolName);
        if ($tool === null) {
            return $this->errorResult("Tool [{$toolName}] is not registered.", [
                'available_tools' => array_keys($tools->all()),
            ]);
        }

        $context = new UnifiedActionContext(
            sessionId: (string) ($this->option('session') ?: 'tool-test-' . uniqid()),
            userId: $this->option('user') ?: null
        );

        $result = $tool->execute($payload, $context);
        $response = $result->toArray() + [
            'tool' => $tool->toArray(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $result->success
                ? $this->info($result->message ?? 'Tool executed successfully.')
                : $this->error($result->error ?? $result->message ?? 'Tool failed.');

            $this->line(json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return $result->success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payload(): ?array
    {
        $payload = trim((string) ($this->option('payload') ?? ''));
        if ($payload === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) && array_is_list($decoded) === false ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function errorResult(string $error, array $extra = []): int
    {
        $payload = ['success' => false, 'error' => $error] + $extra;

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->error($error);
            if ($extra !== []) {
                $this->line(json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        return self::FAILURE;
    }
}
