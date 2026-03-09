<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AIPromptFeedbackEvent;
use LaravelAIEngine\Models\AIPromptPolicyVersion;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGPromptPolicyService;

class DecisionPolicyEvaluateCommand extends Command
{
    protected $signature = 'ai-engine:decision-policy:evaluate
                            {--policy= : Policy key to evaluate}
                            {--hours= : Lookback window in hours}
                            {--min-samples= : Minimum decision samples for promotion}
                            {--promote : Promote the best canary to active if KPIs improve}
                            {--json : Output JSON payload}';

    protected $description = 'Evaluate decision prompt policy versions and optionally promote canary versions';

    public function handle(
        AutonomousRAGPromptPolicyService $policyService,
        AutonomousRAGPolicy $policyConfig
    ): int {
        if (!$policyService->storeAvailable()) {
            $this->warn('Decision policy store is disabled or unavailable.');
            return self::SUCCESS;
        }

        if (!Schema::hasTable($policyConfig->decisionFeedbackStoreTable())) {
            $this->warn('Decision feedback events table is missing. Run migrations first.');
            return self::SUCCESS;
        }

        $policyKey = trim((string) ($this->option('policy') ?: $policyConfig->decisionPolicyDefaultKey()));
        $windowHours = max(1, (int) ($this->option('hours') ?: $policyConfig->decisionPolicyEvaluationWindowHours()));
        $minSamples = max(1, (int) ($this->option('min-samples') ?: $policyConfig->decisionPolicyEvaluationMinSamples()));

        $policies = AIPromptPolicyVersion::query()
            ->policy($policyKey)
            ->orderByDesc('version')
            ->get();

        if ($policies->isEmpty()) {
            $payload = [
                'policy_key' => $policyKey,
                'window_hours' => $windowHours,
                'policies' => [],
                'message' => 'No policy versions found.',
            ];

            if ($this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            } else {
                $this->info('No policy versions found for key: ' . $policyKey);
            }

            return self::SUCCESS;
        }

        $rows = $policies->map(fn (AIPromptPolicyVersion $version) => $this->buildMetricsRow($version, $windowHours));
        $promotion = $this->option('promote') ? $this->tryPromoteCanary(
            $rows,
            $policyService,
            $minSamples,
            $policyConfig->decisionPolicyEvaluationMinScoreDelta()
        ) : null;

        $payload = [
            'policy_key' => $policyKey,
            'window_hours' => $windowHours,
            'min_samples' => $minSamples,
            'rows' => $rows->values()->all(),
            'promotion' => $promotion,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Decision Policy Evaluation');
        $this->table(
            ['ID', 'Version', 'Status', 'Scope', 'Decisions', 'Success %', 'Parse %', 'Fallback %', 'Avg Latency', 'Score'],
            $rows->map(fn (array $row) => [
                $row['id'],
                $row['version'],
                $row['status'],
                $row['scope_key'],
                $row['total_decisions'],
                $row['success_rate'],
                $row['parse_failure_rate'],
                $row['fallback_rate'],
                $row['avg_latency_ms'],
                $row['score'],
            ])->values()->all()
        );

        if ($promotion) {
            if (!empty($promotion['promoted'])) {
                $this->info('Promoted policy version ' . $promotion['promoted']['version'] . ' to active.');
            } elseif (!empty($promotion['reason'])) {
                $this->line('No promotion: ' . $promotion['reason']);
            }
        }

        return self::SUCCESS;
    }

    protected function buildMetricsRow(AIPromptPolicyVersion $policyVersion, int $windowHours): array
    {
        $events = AIPromptFeedbackEvent::query()
            ->channel('rag_decision')
            ->where('policy_key', $policyVersion->policy_key)
            ->where('policy_version', $policyVersion->version)
            ->where('created_at', '>=', now()->subHours($windowHours))
            ->get();

        $decisionEvents = $events->filter(fn (AIPromptFeedbackEvent $event) => in_array(
            $event->event_type,
            ['decision_parsed', 'decision_fallback', 'decision_parse_failure'],
            true
        ));

        $totalDecisions = $decisionEvents->count();
        $parseFailures = $events->where('event_type', 'decision_parse_failure')->count();
        $fallbackCount = $events->filter(fn (AIPromptFeedbackEvent $event) => in_array(
            $event->event_type,
            ['decision_fallback', 'decision_parse_failure'],
            true
        ))->count();

        $outcomeEvents = $events->where('event_type', 'execution_outcome');
        $successRate = $outcomeEvents->isNotEmpty()
            ? round($outcomeEvents->where('success', true)->count() / $outcomeEvents->count() * 100, 2)
            : 0.0;

        $parseFailureRate = $totalDecisions > 0
            ? round($parseFailures / $totalDecisions * 100, 2)
            : 0.0;

        $fallbackRate = $totalDecisions > 0
            ? round($fallbackCount / $totalDecisions * 100, 2)
            : 0.0;

        $avgLatency = round((float) ($decisionEvents->avg('latency_ms') ?? 0), 2);
        $score = round(
            $successRate - ($parseFailureRate * 0.35) - ($fallbackRate * 0.20) - ($avgLatency / 120),
            2
        );

        $metrics = [
            'total_decisions' => $totalDecisions,
            'parse_failures' => $parseFailures,
            'fallback_count' => $fallbackCount,
            'success_rate' => $successRate,
            'parse_failure_rate' => $parseFailureRate,
            'fallback_rate' => $fallbackRate,
            'avg_latency_ms' => $avgLatency,
            'score' => $score,
            'window_hours' => $windowHours,
        ];

        $policyVersion->metrics = $metrics;
        $policyVersion->save();

        return array_merge([
            'id' => $policyVersion->id,
            'version' => $policyVersion->version,
            'status' => $policyVersion->status,
            'scope_key' => $policyVersion->scope_key,
            'rollout_percentage' => $policyVersion->rollout_percentage,
        ], $metrics);
    }

    protected function tryPromoteCanary(
        Collection $rows,
        AutonomousRAGPromptPolicyService $policyService,
        int $minSamples,
        float $minScoreDelta
    ): array {
        $active = $rows->first(fn (array $row) => $row['status'] === 'active');
        $canaries = $rows
            ->filter(fn (array $row) => $row['status'] === 'canary' && $row['total_decisions'] >= $minSamples)
            ->sortByDesc('score')
            ->values();

        if ($canaries->isEmpty()) {
            return ['reason' => 'No canary version reached minimum samples for promotion.'];
        }

        $winner = $canaries->first();
        $activeScore = (float) ($active['score'] ?? 0.0);
        $scoreDelta = (float) $winner['score'] - $activeScore;

        if ($active && $scoreDelta < $minScoreDelta) {
            return [
                'reason' => sprintf(
                    'Canary score delta %.2f is below required %.2f.',
                    $scoreDelta,
                    $minScoreDelta
                ),
                'candidate' => $winner,
            ];
        }

        $promoted = $policyService->activate((int) $winner['id'], 'active');
        if (!$promoted) {
            return ['reason' => 'Promotion failed while activating selected canary.', 'candidate' => $winner];
        }

        return [
            'promoted' => [
                'id' => $promoted->id,
                'version' => $promoted->version,
                'status' => $promoted->status,
                'score' => $winner['score'],
                'score_delta' => $scoreDelta,
            ],
            'previous_active' => $active,
        ];
    }
}
