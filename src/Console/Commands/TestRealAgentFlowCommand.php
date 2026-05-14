<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;

class TestRealAgentFlowCommand extends Command
{
    protected $signature = 'ai-engine:test-real-agent
                            {--message=* : Ordered test messages (repeat option)}
                            {--script=followup : Built-in script when no messages provided (followup|minimal|invoice-create)}
                            {--assert= : Validate a named scenario against responses (invoice-create)}
                            {--full-response : Include full response text in JSON output}
                            {--session= : Session ID}
                            {--user=1 : User ID}
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
        $messages = $this->resolveMessages();
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

        return match ((string) $this->option('script')) {
            'minimal' => [
                'hello',
                'show me recent updates',
            ],
            'invoice-create' => [
                'create invoice',
                'Mohamed Abou Hagar',
                'mohamed@example.test',
                'actually change customer name to Mohamed Hagar before confirmation',
                'yes create the customer',
                '2 Macbook Pro and 3 iPhone',
                'remove 1 iPhone and add 1 iPad',
                'confirm',
                'yes',
            ],
            default => [
                'list invoices',
                'what is the status of the first one?',
                'should i follow up on it?',
            ],
        };
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
            'invoice-create' => $this->assertInvoiceCreateScenario($results),
            '', 'none' => null,
            default => [
                'scenario' => $scenario,
                'passed' => false,
                'failures' => ["Unknown assertion scenario [{$scenario}]."],
            ],
        };
    }

    protected function assertInvoiceCreateScenario(array $results): array
    {
        $texts = array_map(
            fn (array $row): string => mb_strtolower((string) ($row['response_text'] ?? $row['response_excerpt'] ?? '')),
            $results
        );

        $checks = [
            'asks_for_customer' => fn (): bool => $this->containsAny($texts[0] ?? '', ['customer name', 'name or email', 'customer']),
            'handles_missing_customer' => fn (): bool => $this->containsAny($texts[1] ?? '', [
                'not found',
                'couldn\'t find',
                'create a new customer',
                'missing customer',
                'customer email',
                'email',
            ]),
            'keeps_edited_customer_name' => fn (): bool => str_contains($texts[3] ?? '', 'mohamed hagar'),
            'does_not_report_missing_create_customer_tool' => fn (): bool => !$this->containsAny(implode("\n", $texts), [
                'tool \'create_customer\' not found',
                'unable to create a new customer',
                'system limitation',
                'customer_id": 0',
                'placeholder',
            ]),
            'asks_or_accepts_products' => fn (): bool => $this->containsAny($texts[5] ?? '', ['macbook pro', 'iphone', 'product']),
            'supports_item_edit' => fn (): bool => str_contains($texts[6] ?? '', 'ipad')
                && str_contains($texts[6] ?? '', 'iphone')
                && $this->containsAny($texts[6] ?? '', ['2 iphone', '2 iphones', '2 units', 'quantity: 2']),
            'final_review_asks_for_confirmation' => fn (): bool => $this->containsAny($texts[7] ?? '', [
                'please review',
                'confirm to proceed',
                'please confirm',
                'ready to proceed',
                'create this invoice',
                'type:',
                'yes',
            ]),
            'final_confirmation_completes' => fn (): bool => (($results[8]['is_complete'] ?? false) === true)
                || $this->containsAny($texts[8] ?? '', ['successfully completed', 'invoice', 'created']),
        ];

        $failures = [];
        foreach ($checks as $name => $check) {
            if (!$check()) {
                $failures[] = $name;
            }
        }

        return [
            'scenario' => 'invoice-create',
            'passed' => $failures === [],
            'failures' => $failures,
        ];
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
