<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AIPromptFeedbackEvent;

class RAGDecisionFeedbackService
{
    protected ?bool $feedbackTableAvailable = null;

    public function __construct(protected RAGDecisionPolicy $policy)
    {
    }

    public function recordParseFailure(string $message, string $rawResponse, array $runtime = []): void
    {
        if (!$this->policy->decisionAdaptiveFeedbackEnabled()) {
            return;
        }

        $state = $this->state();
        $state['total_decisions']++;
        $state['parse_failures']++;
        $state['fallback_count']++;
        $state['last_message_excerpt'] = $this->excerpt($message);
        $state['last_parse_failure_excerpt'] = $this->excerpt($rawResponse, 220);
        $this->addIssue($state, 'strict_json_required');

        $this->persist($state);
        $this->persistEvent(array_merge($this->baseEvent($runtime), [
            'event_type' => 'decision_parse_failure',
            'request_text' => $message,
            'message_excerpt' => $this->excerpt($message),
            'raw_response_excerpt' => $this->excerpt($rawResponse, 700),
            'decision_source' => 'fallback',
            'outcome' => 'parse_failure',
            'metadata' => array_merge(
                (array) ($runtime['metadata'] ?? []),
                ['issues' => ['strict_json_required']]
            ),
        ]));
    }

    public function recordParsedDecision(array $decision, string $message, array $context, array $runtime = []): void
    {
        if (!$this->policy->decisionAdaptiveFeedbackEnabled()) {
            return;
        }

        $state = $this->state();
        $state['total_decisions']++;

        $tool = (string) ($decision['tool'] ?? 'unknown');
        $state['tool_counts'][$tool] = (($state['tool_counts'][$tool] ?? 0) + 1);

        $relistRisk = false;
        if ($this->isLikelyRelistRisk($decision, $message, $context)) {
            $relistRisk = true;
            $state['relist_risk_count']++;
            $this->addIssue($state, 'follow_up_relisting_risk');
        }

        $this->persist($state);
        $this->persistEvent(array_merge($this->baseEvent($runtime), [
            'event_type' => 'decision_parsed',
            'request_text' => $message,
            'message_excerpt' => $this->excerpt($message),
            'decision_tool' => $tool,
            'decision_source' => (string) ($decision['decision_source'] ?? 'ai'),
            'reasoning' => (string) ($decision['reasoning'] ?? ''),
            'decision_parameters' => (array) ($decision['parameters'] ?? []),
            'tool_calls' => [
                [
                    'tool' => $tool,
                    'parameters' => (array) ($decision['parameters'] ?? []),
                ],
            ],
            'relist_risk' => $relistRisk,
            'metadata' => array_merge(
                (array) ($runtime['metadata'] ?? []),
                ['issues' => $relistRisk ? ['follow_up_relisting_risk'] : []]
            ),
        ]));
    }

    public function recordFallbackDecision(array $decision, string $message, array $runtime = []): void
    {
        if (!$this->policy->decisionAdaptiveFeedbackEnabled()) {
            return;
        }

        $state = $this->state();
        $state['fallback_count']++;

        $tool = (string) ($decision['tool'] ?? 'unknown');
        $state['tool_counts'][$tool] = (($state['tool_counts'][$tool] ?? 0) + 1);
        $state['last_fallback_message_excerpt'] = $this->excerpt($message);

        $this->persist($state);
        $this->persistEvent(array_merge($this->baseEvent($runtime), [
            'event_type' => 'decision_fallback',
            'request_text' => $message,
            'message_excerpt' => $this->excerpt($message),
            'decision_tool' => $tool,
            'decision_source' => (string) ($decision['decision_source'] ?? 'fallback'),
            'reasoning' => (string) ($decision['reasoning'] ?? ''),
            'decision_parameters' => (array) ($decision['parameters'] ?? []),
            'tool_calls' => [
                [
                    'tool' => $tool,
                    'parameters' => (array) ($decision['parameters'] ?? []),
                ],
            ],
            'outcome' => 'fallback',
            'metadata' => (array) ($runtime['metadata'] ?? []),
        ]));
    }

    public function recordExecutionOutcome(array $decision, array $result, array $runtime = []): void
    {
        if (!$this->policy->decisionAdaptiveFeedbackEnabled()) {
            return;
        }

        $tool = (string) ($decision['tool'] ?? 'unknown');
        $success = (bool) ($result['success'] ?? false);

        $this->persistEvent(array_merge($this->baseEvent($runtime), [
            'event_type' => 'execution_outcome',
            'decision_tool' => $tool,
            'decision_source' => (string) ($decision['decision_source'] ?? 'ai'),
            'reasoning' => (string) ($decision['reasoning'] ?? ''),
            'decision_parameters' => (array) ($decision['parameters'] ?? []),
            'tool_calls' => [
                [
                    'tool' => $tool,
                    'parameters' => (array) ($decision['parameters'] ?? []),
                ],
            ],
            'success' => $success,
            'outcome' => $success ? 'success' : 'failure',
            'metadata' => array_merge(
                (array) ($runtime['metadata'] ?? []),
                ['result' => $this->trimResultPayload($result)]
            ),
        ]));
    }

    public function recordUserRating(string $sessionId, int $rating, array $runtime = []): void
    {
        if (!$this->policy->decisionAdaptiveFeedbackEnabled()) {
            return;
        }

        $this->persistEvent(array_merge($this->baseEvent(array_merge($runtime, [
            'session_id' => $sessionId,
        ])), [
            'event_type' => 'user_feedback',
            'user_rating' => max(1, min(5, $rating)),
            'outcome' => 'user_feedback',
            'metadata' => (array) ($runtime['metadata'] ?? []),
        ]));
    }

    public function adaptiveHints(array $businessContext = []): array
    {
        $hints = [];

        $domain = trim((string) ($businessContext['domain'] ?? ''));
        if ($domain !== '') {
            $hints[] = "Business domain: {$domain}. Prefer domain entities and vocabulary.";
        }

        $priorities = array_values(array_filter((array) ($businessContext['priorities'] ?? [])));
        if (!empty($priorities)) {
            $hints[] = 'Business priorities: ' . implode(', ', $priorities) . '.';
        }

        foreach (array_values(array_filter((array) ($businessContext['known_issues'] ?? []))) as $issue) {
            $hints[] = 'Known issue to avoid: ' . $issue . '.';
        }

        foreach (array_values(array_filter((array) ($businessContext['instructions'] ?? []))) as $instruction) {
            $hints[] = 'Instruction: ' . $instruction . '.';
        }

        $state = $this->snapshot();
        if (($state['parse_failures'] ?? 0) > 0) {
            $hints[] = 'Recent parse failures detected; return strict JSON object only, without prose.';
        }

        if (($state['relist_risk_count'] ?? 0) > 0) {
            $hints[] = 'Follow-up relisting risk detected; reuse selected/visible context before listing again.';
        }

        $dbQueryCount = (int) ($state['tool_counts']['db_query'] ?? 0);
        $vectorSearchCount = (int) ($state['tool_counts']['vector_search'] ?? 0);
        if ($dbQueryCount >= 5 && $vectorSearchCount === 0) {
            $hints[] = 'Recent decisions overused db_query; prefer vector_search for conceptual or semantic questions.';
        }

        $maxHints = $this->policy->decisionAdaptiveMaxHints();

        return array_slice(array_values(array_unique($hints)), 0, $maxHints);
    }

    public function snapshot(): array
    {
        $persistent = $this->persistentState();
        if ($persistent !== null) {
            return $persistent;
        }

        return $this->state();
    }

    public function report(array $businessContext = []): array
    {
        $snapshot = $this->snapshot();
        $total = (int) ($snapshot['total_decisions'] ?? 0);
        $parseFailures = (int) ($snapshot['parse_failures'] ?? 0);
        $fallbackCount = (int) ($snapshot['fallback_count'] ?? 0);
        $relistRiskCount = (int) ($snapshot['relist_risk_count'] ?? 0);

        return [
            'window_hours' => $this->policy->decisionFeedbackWindowHours(),
            'total_decisions' => $total,
            'parse_failures' => $parseFailures,
            'fallback_count' => $fallbackCount,
            'relist_risk_count' => $relistRiskCount,
            'parse_failure_rate' => $total > 0 ? round(($parseFailures / $total) * 100, 2) : 0.0,
            'fallback_rate' => $total > 0 ? round(($fallbackCount / $total) * 100, 2) : 0.0,
            'relist_risk_rate' => $total > 0 ? round(($relistRiskCount / $total) * 100, 2) : 0.0,
            'tool_counts' => $snapshot['tool_counts'] ?? [],
            'recent_issues' => $snapshot['recent_issues'] ?? [],
            'adaptive_hints' => $this->adaptiveHints($businessContext),
        ];
    }

    public function reset(): void
    {
        Cache::forget($this->policy->decisionFeedbackCacheKey());

        if (!$this->feedbackStoreAvailable()) {
            return;
        }

        AIPromptFeedbackEvent::query()
            ->channel('rag_decision')
            ->where('created_at', '>=', now()->subHours($this->policy->decisionFeedbackWindowHours()))
            ->delete();
    }

    protected function persistentState(): ?array
    {
        if (!$this->feedbackStoreAvailable()) {
            return null;
        }

        $events = AIPromptFeedbackEvent::query()
            ->channel('rag_decision')
            ->where('created_at', '>=', now()->subHours($this->policy->decisionFeedbackWindowHours()))
            ->orderByDesc('id')
            ->get();

        if ($events->isEmpty()) {
            return [
                'total_decisions' => 0,
                'parse_failures' => 0,
                'fallback_count' => 0,
                'relist_risk_count' => 0,
                'tool_counts' => [],
                'recent_issues' => [],
            ];
        }

        $decisionEvents = $events->filter(fn (AIPromptFeedbackEvent $event) => in_array(
            $event->event_type,
            ['decision_parsed', 'decision_fallback', 'decision_parse_failure'],
            true
        ));

        $toolCounts = $events
            ->filter(fn (AIPromptFeedbackEvent $event) => !empty($event->decision_tool))
            ->countBy(fn (AIPromptFeedbackEvent $event) => (string) $event->decision_tool)
            ->all();

        $issues = [];
        foreach ($events as $event) {
            if ($event->event_type === 'decision_parse_failure') {
                $issues[] = 'strict_json_required';
            }

            if ((bool) $event->relist_risk) {
                $issues[] = 'follow_up_relisting_risk';
            }

            foreach ((array) data_get($event->metadata, 'issues', []) as $issue) {
                if (is_string($issue) && trim($issue) !== '') {
                    $issues[] = trim($issue);
                }
            }
        }

        return [
            'total_decisions' => $decisionEvents->count(),
            'parse_failures' => $events->where('event_type', 'decision_parse_failure')->count(),
            'fallback_count' => $events->filter(fn (AIPromptFeedbackEvent $event) => in_array(
                $event->event_type,
                ['decision_fallback', 'decision_parse_failure'],
                true
            ))->count(),
            'relist_risk_count' => $events->where('relist_risk', true)->count(),
            'tool_counts' => $toolCounts,
            'recent_issues' => array_slice(array_values(array_unique($issues)), -10),
        ];
    }

    protected function feedbackStoreAvailable(): bool
    {
        if (!$this->policy->decisionFeedbackStoreEnabled()) {
            return false;
        }

        if ($this->feedbackTableAvailable !== null) {
            return $this->feedbackTableAvailable;
        }

        try {
            $this->feedbackTableAvailable = Schema::hasTable($this->policy->decisionFeedbackStoreTable());
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Decision feedback store unavailable', [
                'error' => $e->getMessage(),
            ]);
            $this->feedbackTableAvailable = false;
        }

        return $this->feedbackTableAvailable;
    }

    protected function baseEvent(array $runtime): array
    {
        $policyMeta = (array) ($runtime['policy'] ?? []);

        return [
            'channel' => 'rag_decision',
            'policy_key' => $policyMeta['policy_key'] ?? $this->policy->decisionPolicyDefaultKey(),
            'policy_id' => $policyMeta['id'] ?? null,
            'policy_version' => $policyMeta['version'] ?? null,
            'policy_status' => $policyMeta['status'] ?? null,
            'session_id' => $this->nullString($runtime['session_id'] ?? null),
            'conversation_id' => $this->nullString($runtime['conversation_id'] ?? null),
            'user_id' => $this->nullString($runtime['user_id'] ?? null),
            'tenant_id' => $this->nullString($runtime['tenant_id'] ?? null),
            'app_id' => $this->nullString($runtime['app_id'] ?? null),
            'latency_ms' => isset($runtime['latency_ms']) ? (int) $runtime['latency_ms'] : null,
            'tokens_used' => isset($runtime['tokens_used']) ? (int) $runtime['tokens_used'] : null,
            'token_cost' => isset($runtime['token_cost']) ? (float) $runtime['token_cost'] : null,
            'user_rating' => isset($runtime['user_rating']) ? (int) $runtime['user_rating'] : null,
        ];
    }

    protected function persistEvent(array $payload): void
    {
        if (!$this->feedbackStoreAvailable()) {
            return;
        }

        try {
            AIPromptFeedbackEvent::query()->create($payload);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('Failed to persist decision feedback event', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function isLikelyRelistRisk(array $decision, string $message, array $context): bool
    {
        $tool = (string) ($decision['tool'] ?? '');
        if ($tool !== 'db_query') {
            return false;
        }

        $messageLower = strtolower($message);
        if (
            str_contains($messageLower, 'list') ||
            str_contains($messageLower, 'show all') ||
            str_contains($messageLower, 'get all')
        ) {
            return false;
        }

        if (empty($context['selected_entity']) && empty($context['last_entity_list'])) {
            return false;
        }

        $params = (array) ($decision['parameters'] ?? []);
        $filters = (array) ($params['filters'] ?? []);

        return empty($filters['id']) && empty($filters['uuid']) && empty($filters['slug']);
    }

    protected function state(): array
    {
        return Cache::get($this->policy->decisionFeedbackCacheKey(), [
            'total_decisions' => 0,
            'parse_failures' => 0,
            'fallback_count' => 0,
            'relist_risk_count' => 0,
            'tool_counts' => [],
            'recent_issues' => [],
        ]);
    }

    protected function persist(array $state): void
    {
        Cache::put(
            $this->policy->decisionFeedbackCacheKey(),
            $state,
            now()->addHours($this->policy->decisionFeedbackWindowHours())
        );
    }

    protected function addIssue(array &$state, string $issue): void
    {
        $state['recent_issues'][] = $issue;
        $state['recent_issues'] = array_slice(array_values(array_unique($state['recent_issues'])), -10);
    }

    protected function excerpt(string $text, int $limit = 140): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if (strlen($clean) <= $limit) {
            return $clean;
        }

        return substr($clean, 0, $limit) . '...';
    }

    protected function trimResultPayload(array $result): array
    {
        $payload = $result;

        if (isset($payload['response']) && is_string($payload['response'])) {
            $payload['response'] = $this->excerpt($payload['response'], 240);
        }

        if (isset($payload['metadata']) && is_array($payload['metadata'])) {
            $payload['metadata'] = array_slice($payload['metadata'], 0, 10, true);
        }

        return $payload;
    }

    protected function nullString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
