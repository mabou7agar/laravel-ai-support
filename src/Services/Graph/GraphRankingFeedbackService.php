<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Facades\Cache;

class GraphRankingFeedbackService
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function recordOutcome(string $queryKind, array $metrics): void
    {
        if (!(bool) config('ai-engine.graph.ranking_feedback.enabled', true)) {
            return;
        }

        $state = $this->state($queryKind);
        $state['samples']++;
        $state['vector_dominant'] += !empty($metrics['vector_dominant']) ? 1 : 0;
        $state['lexical_dominant'] += !empty($metrics['lexical_dominant']) ? 1 : 0;
        $state['relation_helpful'] += !empty($metrics['relation_helpful']) ? 1 : 0;
        $state['selected_seed_helpful'] += !empty($metrics['selected_seed_helpful']) ? 1 : 0;
        $state['empty_results'] += !empty($metrics['empty_results']) ? 1 : 0;
        $state['cache_hits'] += !empty($metrics['cache_hit']) ? 1 : 0;

        Cache::put($this->cacheKey($queryKind), $state, now()->addSeconds($this->ttl()));
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    public function adaptPlan(string $queryKind, array $plan): array
    {
        if (!(bool) config('ai-engine.graph.ranking_feedback.enabled', true)) {
            return $plan;
        }

        $state = $this->state($queryKind);
        $samples = max(1, (int) ($state['samples'] ?? 0));
        if ($samples < max(3, (int) config('ai-engine.graph.ranking_feedback.min_samples', 5))) {
            return $plan;
        }

        $vectorRatio = ($state['vector_dominant'] ?? 0) / $samples;
        $lexicalRatio = ($state['lexical_dominant'] ?? 0) / $samples;
        $relationRatio = ($state['relation_helpful'] ?? 0) / $samples;
        $selectedRatio = ($state['selected_seed_helpful'] ?? 0) / $samples;

        $vectorWeight = (float) ($plan['vector_weight'] ?? 0.6);
        $lexicalWeight = (float) ($plan['lexical_weight'] ?? 0.4);

        if ($lexicalRatio > ($vectorRatio + 0.15)) {
            $lexicalWeight = min(0.85, $lexicalWeight + 0.1);
            $vectorWeight = max(0.15, 1 - $lexicalWeight);
        } elseif ($vectorRatio > ($lexicalRatio + 0.15)) {
            $vectorWeight = min(0.85, $vectorWeight + 0.1);
            $lexicalWeight = max(0.15, 1 - $vectorWeight);
        }

        $plan['vector_weight'] = round($vectorWeight, 4);
        $plan['lexical_weight'] = round($lexicalWeight, 4);
        $plan['relationship_bonus'] = round(min(0.2, max(0.0, (float) ($plan['relationship_bonus'] ?? 0.05) + (($relationRatio - 0.5) * 0.08))), 4);
        $plan['selected_seed_boost'] = round(min(0.15, max(0.0, (float) ($plan['selected_seed_boost'] ?? 0.05) + (($selectedRatio - 0.5) * 0.05))), 4);
        $plan['ranking_feedback'] = [
            'samples' => $samples,
            'vector_ratio' => round($vectorRatio, 4),
            'lexical_ratio' => round($lexicalRatio, 4),
            'relation_ratio' => round($relationRatio, 4),
            'selected_ratio' => round($selectedRatio, 4),
        ];

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $queryKind): array
    {
        return $this->state($queryKind);
    }

    /**
     * @return array<string, mixed>
     */
    protected function state(string $queryKind): array
    {
        $stored = Cache::get($this->cacheKey($queryKind), []);
        $stored = is_array($stored) ? $stored : [];

        return array_merge([
            'query_kind' => trim($queryKind) !== '' ? $queryKind : 'generic',
            'samples' => 0,
            'vector_dominant' => 0,
            'lexical_dominant' => 0,
            'relation_helpful' => 0,
            'selected_seed_helpful' => 0,
            'empty_results' => 0,
            'cache_hits' => 0,
        ], $stored);
    }

    protected function cacheKey(string $queryKind): string
    {
        return (string) config('ai-engine.graph.ranking_feedback.cache_key', 'ai_engine:graph_ranking_feedback')
            . ':'
            . (trim($queryKind) !== '' ? $queryKind : 'generic');
    }

    protected function ttl(): int
    {
        return max(3600, (int) config('ai-engine.graph.ranking_feedback.ttl', 604800));
    }
}
