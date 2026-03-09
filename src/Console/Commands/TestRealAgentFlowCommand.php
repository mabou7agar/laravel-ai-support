<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;

class TestRealAgentFlowCommand extends Command
{
    protected $signature = 'ai-engine:test-real-agent
                            {--message=* : Ordered test messages (repeat option)}
                            {--script=followup : Built-in script when no messages provided (followup|minimal)}
                            {--session= : Session ID}
                            {--user=1 : User ID}
                            {--engine=openai : AI engine}
                            {--model=gpt-4o-mini : AI model}
                            {--local-only : Force local-only execution (no remote node routing)}
                            {--json : Output JSON summary}
                            {--report-feedback : Include adaptive decision feedback report}
                            {--stop-on-error : Stop at first processing error}';

    protected $description = 'Run a real end-to-end agent conversation test against live app data';

    public function handle(
        AgentOrchestrator $orchestrator,
        AutonomousRAGDecisionFeedbackService $feedbackService,
        AutonomousRAGPolicy $policy
    ): int {
        $sessionId = $this->option('session') ?: 'real-agent-' . uniqid();
        $userId = $this->option('user');
        $messages = $this->resolveMessages();
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
                    $response = $orchestrator->process(
                        $message,
                        $sessionId,
                        $userId,
                        [
                            'engine' => $this->option('engine'),
                            'model' => $this->option('model'),
                            'local_only' => $localOnly,
                        ]
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

            $summary = $this->buildSummary($sessionId, $userId, $results, $toolCounts, $errors);
            if ($this->option('report-feedback')) {
                $summary['decision_feedback'] = $feedbackService->report($policy->decisionBusinessContext());
            }

            if ($this->option('json')) {
                $this->line(json_encode([
                    'summary' => $summary,
                    'turns' => $results,
                ], JSON_PRETTY_PRINT));
            } else {
                $this->displaySummary($summary, $results);
            }

            return $errors > 0 ? self::FAILURE : self::SUCCESS;
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

        return match ((string) $this->option('script')) {
            'minimal' => [
                'hello',
                'show me recent updates',
            ],
            default => [
                'list invoices',
                'what is the status of the first one?',
                'should i follow up on it?',
            ],
        };
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
            'response_excerpt' => $this->excerpt($response->message),
        ];
    }

    protected function buildSummary(
        string $sessionId,
        $userId,
        array $results,
        array $toolCounts,
        int $errors
    ): array {
        $total = count($results);
        $success = count(array_filter($results, fn (array $row) => ($row['success'] ?? false) === true));
        $avgDuration = $total > 0
            ? (int) round(array_sum(array_map(fn (array $row) => (int) ($row['duration_ms'] ?? 0), $results)) / $total)
            : 0;

        return [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'engine' => $this->option('engine'),
            'model' => $this->option('model'),
            'local_only' => (bool) $this->option('local-only'),
            'total_turns' => $total,
            'successful_turns' => $success,
            'failed_turns' => $errors,
            'average_duration_ms' => $avgDuration,
            'tool_counts' => $toolCounts,
        ];
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
