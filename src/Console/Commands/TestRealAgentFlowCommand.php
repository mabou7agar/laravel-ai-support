<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;

class TestRealAgentFlowCommand extends Command
{
    protected $signature = 'ai:test-real-agent
                            {--message=* : Ordered test messages (repeat option)}
                            {--script=followup : Built-in script when no messages provided (followup|minimal)}
                            {--script-file= : JSON file containing a messages array}
                            {--assert= : Validate a named scenario against responses}
                            {--full-response : Include full response text in JSON output}
                            {--session= : Session ID}
                            {--user= : User ID}
                            {--engine=openai : AI engine}
                            {--model=gpt-4o-mini : AI model}
                            {--rag-model=* : RAG model/collection class to scope retrieval (repeat option)}
                            {--local-only : Force local-only execution (no remote node routing)}
                            {--json : Output JSON summary}
                            {--report-feedback : Include adaptive decision feedback report}
                            {--stop-on-error : Stop at first processing error}';

    protected $description = 'Run a real end-to-end agent conversation test against live app data';

    public function handle(
        AgentRuntimeContract $runtime,
        RAGDecisionFeedbackService $feedbackService,
        RAGDecisionPolicy $policy
    ): int {
        $sessionId = $this->option('session') ?: 'real-agent-' . uniqid();
        $userId = $this->option('user');
        try {
            $messages = $this->resolveMessages();
        } catch (\InvalidArgumentException $e) {
            if ($this->option('json')) {
                $this->line(json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                ], JSON_PRETTY_PRINT));
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }
        $ragModels = $this->resolveRagModels();
        $localOnly = (bool) $this->option('local-only');
        $originalNodesEnabled = config('ai-engine.nodes.enabled');

        if ($localOnly) {
            config(['ai-engine.nodes.enabled' => false]);
        }

        try {
            if (empty($messages)) {
                $this->error('No test messages resolved.');
                return self::FAILURE;
            }

            $results = [];
            $toolCounts = [];
            $errors = 0;

            foreach ($messages as $index => $message) {
                $turn = $index + 1;
                $start = microtime(true);

                try {
                    $response = $runtime->process(
                        $message,
                        $sessionId,
                        $userId,
                        $this->runtimeOptions($localOnly, $ragModels)
                    );

                    $duration = (int) round((microtime(true) - $start) * 1000);
                    $toolUsed = $response->context?->metadata['tool_used'] ?? null;
                    if (is_string($toolUsed) && $toolUsed !== '') {
                        $toolCounts[$toolUsed] = ($toolCounts[$toolUsed] ?? 0) + 1;
                    }

                    $results[] = $this->buildResultRow($turn, $message, $response, $duration, $toolUsed);
                } catch (\Throwable $e) {
                    $errors++;
                    $duration = (int) round((microtime(true) - $start) * 1000);
                    $results[] = [
                        'turn' => $turn,
                        'message' => $message,
                        'success' => false,
                        'duration_ms' => $duration,
                        'error' => $e->getMessage(),
                    ];

                    if ($this->option('stop-on-error')) {
                        break;
                    }
                }
            }

            $summary = $this->buildSummary($sessionId, $userId, $results, $toolCounts, $errors, $ragModels);
            $assertions = $this->assertScenario((string) $this->option('assert'), $results);
            if ($assertions !== null) {
                $summary['assertions'] = $assertions;
            }

            if ($this->option('report-feedback')) {
                $summary['decision_feedback'] = $feedbackService->report($policy->decisionBusinessContext());
            }

            $outputResults = $this->option('full-response')
                ? $results
                : array_map(fn (array $row): array => array_diff_key($row, ['response_text' => true]), $results);

            if ($this->option('json')) {
                $this->line(json_encode([
                    'summary' => $summary,
                    'turns' => $outputResults,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displaySummary($summary, $outputResults);
            }

            return ((int) ($summary['failed_turns'] ?? 0)) > 0
                || (($assertions['passed'] ?? true) === false)
                    ? self::FAILURE
                    : self::SUCCESS;
        } finally {
            if ($localOnly) {
                config(['ai-engine.nodes.enabled' => $originalNodesEnabled]);
            }
        }
    }

    protected function resolveMessages(): array
    {
        $messages = array_values(array_filter((array) $this->option('message'), fn ($v) => is_string($v) && trim($v) !== ''));
        if (!empty($messages)) {
            return $messages;
        }

        $scriptFile = trim((string) ($this->option('script-file') ?? ''));
        if ($scriptFile !== '') {
            return $this->messagesFromScriptFile($scriptFile);
        }

        return match ((string) $this->option('script')) {
            'minimal' => [
                'hello',
                'show me recent updates',
            ],
            'followup' => [
                'list recent records',
                'what is the status of the first one?',
                'should i follow up on it?',
            ],
            default => throw new \InvalidArgumentException('Unknown built-in script [' . (string) $this->option('script') . ']. Use --message or --script-file for app-specific flows.'),
        };
    }

    protected function messagesFromScriptFile(string $path): array
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Script file [{$path}] was not found.");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \InvalidArgumentException("Script file [{$path}] could not be read.");
        }

        $decoded = json_decode($contents, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $messages = is_array($decoded) && array_is_list($decoded)
                ? $decoded
                : (is_array($decoded) ? ($decoded['messages'] ?? []) : []);
        } else {
            $messages = preg_split('/\R/', $contents) ?: [];
        }

        $messages = array_values(array_filter(
            array_map(static fn (mixed $message): string => trim((string) $message), (array) $messages),
            static fn (string $message): bool => $message !== ''
        ));

        if ($messages === []) {
            throw new \InvalidArgumentException("Script file [{$path}] does not contain any messages.");
        }

        return $messages;
    }

    protected function resolveRagModels(): array
    {
        return array_values(array_filter(
            array_map(static fn ($value): string => trim((string) $value), (array) $this->option('rag-model')),
            static fn (string $value): bool => $value !== ''
        ));
    }

    protected function runtimeOptions(bool $localOnly, array $ragModels): array
    {
        return array_filter([
            'engine' => $this->option('engine'),
            'model' => $this->option('model'),
            'local_only' => $localOnly,
            'rag_collections' => $ragModels !== [] ? $ragModels : null,
        ], static fn ($value): bool => $value !== null);
    }

    protected function buildResultRow(
        int $turn,
        string $message,
        AgentResponse $response,
        int $duration,
        ?string $toolUsed
    ): array {
        return [
            'turn' => $turn,
            'message' => $message,
            'success' => $response->success,
            'strategy' => $response->strategy ?? 'unknown',
            'tool_used' => $toolUsed,
            'needs_user_input' => $response->needsUserInput,
            'is_complete' => $response->isComplete,
            'duration_ms' => $duration,
            'response_text' => $response->message,
            'response_excerpt' => $this->excerpt($response->message),
        ];
    }

    protected function buildSummary(
        string $sessionId,
        $userId,
        array $results,
        array $toolCounts,
        int $errors,
        array $ragModels = []
    ): array {
        $total = count($results);
        $success = count(array_filter(
            $results,
            fn (array $row): bool => ($row['success'] ?? false) === true || ($row['needs_user_input'] ?? false) === true
        ));
        $failed = max(0, $total - $success);
        $avgDuration = $total > 0
            ? (int) round(array_sum(array_map(fn (array $row) => (int) ($row['duration_ms'] ?? 0), $results)) / $total)
            : 0;

        return [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'engine' => $this->option('engine'),
            'model' => $this->option('model'),
            'local_only' => (bool) $this->option('local-only'),
            'rag_collections' => $ragModels,
            'total_turns' => $total,
            'successful_turns' => $success,
            'failed_turns' => $failed,
            'error_turns' => $errors,
            'average_duration_ms' => $avgDuration,
            'tool_counts' => $toolCounts,
        ];
    }

    protected function assertScenario(string $scenario, array $results): ?array
    {
        return match ($scenario) {
            '', 'none' => null,
            default => [
                'scenario' => $scenario,
                'passed' => false,
                'failures' => ["Unknown assertion scenario [{$scenario}]."],
            ],
        };
    }

    protected function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, mb_strtolower((string) $needle))) {
                return true;
            }
        }

        return false;
    }

    protected function displaySummary(array $summary, array $results): void
    {
        $this->info('Real Agent Test Summary');
        $this->table(
            ['Session', 'User', 'Turns', 'Success', 'Failed', 'Avg(ms)'],
            [[
                $summary['session_id'],
                (string) $summary['user_id'],
                $summary['total_turns'],
                $summary['successful_turns'],
                $summary['failed_turns'],
                $summary['average_duration_ms'],
            ]]
        );

        $this->table(
            ['Turn', 'Success', 'Strategy', 'Tool', 'Needs Input', 'Complete', 'Duration(ms)', 'Message'],
            array_map(function (array $row) {
                return [
                    (string) ($row['turn'] ?? '?'),
                    (($row['success'] ?? false) ? 'yes' : 'no'),
                    (string) ($row['strategy'] ?? 'error'),
                    (string) ($row['tool_used'] ?? '-'),
                    (($row['needs_user_input'] ?? false) ? 'yes' : 'no'),
                    (($row['is_complete'] ?? false) ? 'yes' : 'no'),
                    (string) ($row['duration_ms'] ?? 0),
                    (string) ($row['response_excerpt'] ?? ($row['error'] ?? '')),
                ];
            }, $results)
        );

        if (!empty($summary['tool_counts'])) {
            $this->line('Tools used:');
            $this->table(
                ['Tool', 'Count'],
                collect($summary['tool_counts'])->map(
                    fn ($count, $tool) => [$tool, (int) $count]
                )->values()->toArray()
            );
        }

        if (isset($summary['decision_feedback'])) {
            $feedback = $summary['decision_feedback'];
            $this->line('Decision feedback snapshot:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total decisions', $feedback['total_decisions'] ?? 0],
                    ['Parse failures', $feedback['parse_failures'] ?? 0],
                    ['Fallback count', $feedback['fallback_count'] ?? 0],
                    ['Relist risk count', $feedback['relist_risk_count'] ?? 0],
                ]
            );
        }
    }

    protected function excerpt(string $value, int $limit = 120): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        if (strlen($clean) <= $limit) {
            return $clean;
        }

        return substr($clean, 0, $limit) . '...';
    }
}
