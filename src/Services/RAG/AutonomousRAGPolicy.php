<?php

namespace LaravelAIEngine\Services\RAG;

class AutonomousRAGPolicy
{
    public function decisionModel(): string
    {
        if (function_exists('config')) {
            return (string) config(
                'ai-engine.vector.rag.analysis_model',
                config('ai-engine.default_model', 'gpt-4o-mini')
            );
        }

        return 'gpt-4o-mini';
    }

    public function itemsPerPage(): int
    {
        return 10;
    }

    public function queryStateTtlMinutes(): int
    {
        return 30;
    }

    public function conversationSummaryMessageLimit(): int
    {
        return 6;
    }

    public function conversationSummaryExcerptLimit(): int
    {
        return 200;
    }

    public function allowedAggregateOperations(): array
    {
        return ['sum', 'avg', 'min', 'max', 'count'];
    }

    public function decisionPromptTemplatePath(): ?string
    {
        $value = function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.template_path')
            : null;

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function decisionLanguageMode(): string
    {
        $value = 'hybrid';
        if (function_exists('config')) {
            try {
                $value = config('ai-engine.intelligent_rag.decision.language_mode', 'hybrid');
            } catch (\Throwable) {
                $value = 'hybrid';
            }
        }

        $mode = strtolower(trim((string) $value));

        return in_array($mode, ['ai_first', 'hybrid', 'strict'], true)
            ? $mode
            : 'hybrid';
    }

    public function decisionAdaptiveFeedbackEnabled(): bool
    {
        return (bool) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.enabled', true)
            : true);
    }

    public function decisionFeedbackStoreEnabled(): bool
    {
        return (bool) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.persistence.enabled', true)
            : true);
    }

    public function decisionFeedbackStoreTable(): string
    {
        return (string) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.persistence.table', 'ai_prompt_feedback_events')
            : 'ai_prompt_feedback_events');
    }

    public function decisionFeedbackCacheKey(): string
    {
        return (string) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.cache_key', 'ai_engine:rag_decision_feedback')
            : 'ai_engine:rag_decision_feedback');
    }

    public function decisionFeedbackWindowHours(): int
    {
        $hours = (int) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.window_hours', 48)
            : 48);

        return max(1, $hours);
    }

    public function decisionAdaptiveMaxHints(): int
    {
        $count = (int) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.adaptive_feedback.max_hints', 4)
            : 4);

        return max(1, $count);
    }

    public function decisionBusinessContext(): array
    {
        $context = function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.business_context', [])
            : [];

        if (!is_array($context)) {
            return [];
        }

        return [
            'domain' => is_string($context['domain'] ?? null) ? trim($context['domain']) : '',
            'priorities' => array_values(array_filter((array) ($context['priorities'] ?? []))),
            'known_issues' => array_values(array_filter((array) ($context['known_issues'] ?? []))),
            'instructions' => array_values(array_filter((array) ($context['instructions'] ?? []))),
        ];
    }

    public function decisionPolicyDefaultKey(): string
    {
        return (string) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.default_key', 'decision')
            : 'decision');
    }

    public function decisionPolicyStoreEnabled(): bool
    {
        return (bool) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.enabled', true)
            : true);
    }

    public function decisionPolicyAutoSeedEnabled(): bool
    {
        return (bool) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.auto_seed_default', true)
            : true);
    }

    public function decisionPolicyTable(): string
    {
        return (string) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.table', 'ai_prompt_policy_versions')
            : 'ai_prompt_policy_versions');
    }

    public function decisionPolicyEvaluationWindowHours(): int
    {
        $hours = (int) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.evaluation.window_hours', 168)
            : 168);

        return max(1, $hours);
    }

    public function decisionPolicyEvaluationMinSamples(): int
    {
        $minSamples = (int) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.evaluation.min_samples', 30)
            : 30);

        return max(1, $minSamples);
    }

    public function decisionPolicyEvaluationMinScoreDelta(): float
    {
        $delta = (float) (function_exists('config')
            ? config('ai-engine.intelligent_rag.decision.policy_store.evaluation.min_score_delta', 1.0)
            : 1.0);

        return max(0.0, $delta);
    }

    public function normalizeAggregateOperation(?string $operation): string
    {
        $normalized = strtolower((string) $operation);

        return in_array($normalized, $this->allowedAggregateOperations(), true) ? $normalized : 'sum';
    }
}
