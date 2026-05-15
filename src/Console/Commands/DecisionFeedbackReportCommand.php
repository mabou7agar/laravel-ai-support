<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;

class DecisionFeedbackReportCommand extends Command
{
    protected $signature = 'ai:decision-feedback:report
                            {--json : Output report as JSON}
                            {--reset : Clear adaptive feedback state after report}';

    protected $description = 'Show adaptive decision-prompt feedback report and generated hints';

    public function handle(
        RAGDecisionFeedbackService $feedbackService,
        RAGDecisionPolicy $policy
    ): int {
        $businessContext = $policy->decisionBusinessContext();
        $report = $feedbackService->report($businessContext);
        $shouldReset = (bool) $this->option('reset');

        if ($this->option('json')) {
            if ($shouldReset) {
                $feedbackService->reset();
            }

            $this->line(json_encode([
                'report' => $report,
                'reset' => $shouldReset,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info('Decision Feedback Report');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Window (hours)', $report['window_hours']],
                    ['Total decisions', $report['total_decisions']],
                    ['Parse failures', $report['parse_failures']],
                    ['Fallback count', $report['fallback_count']],
                    ['Relist risk count', $report['relist_risk_count']],
                    ['Parse failure rate', $report['parse_failure_rate'] . '%'],
                    ['Fallback rate', $report['fallback_rate'] . '%'],
                    ['Relist risk rate', $report['relist_risk_rate'] . '%'],
                ]
            );

            $toolCounts = (array) ($report['tool_counts'] ?? []);
            if (!empty($toolCounts)) {
                $this->line('Tool counts:');
                $this->table(
                    ['Tool', 'Count'],
                    collect($toolCounts)->map(
                        fn ($count, $tool) => [$tool, (int) $count]
                    )->values()->toArray()
                );
            }

            $issues = (array) ($report['recent_issues'] ?? []);
            if (!empty($issues)) {
                $this->line('Recent issues:');
                foreach ($issues as $issue) {
                    $this->line('- ' . $issue);
                }
            }

            $hints = (array) ($report['adaptive_hints'] ?? []);
            if (!empty($hints)) {
                $this->line('Adaptive hints:');
                foreach ($hints as $hint) {
                    $this->line('- ' . $hint);
                }
            } else {
                $this->line('Adaptive hints: (none)');
            }

            if ($shouldReset) {
                $feedbackService->reset();
                $this->line('Adaptive feedback state cleared.');
            }
        }

        return self::SUCCESS;
    }
}
