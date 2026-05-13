<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\HybridRetrievalResult;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Traits\Vectorizable;
use LaravelAIEngine\Traits\VectorizableWithMedia;

class HybridGraphVectorSearchService
{
    public function __construct(
        protected VectorSearchService $vectorSearch,
        protected Neo4jRetrievalService $graphRetrieval
    ) {}

    public function enabled(): bool
    {
        return (bool) config('ai-engine.rag.hybrid.enabled', false);
    }

    /**
     * @param array<int, string> $searchQueries
     * @param array<int, string> $collections
     */
    public function retrieveRelevantContext(
        array $searchQueries,
        array $collections,
        int $maxResults = 5,
        float $threshold = 0.3,
        array $options = [],
        int|string|null $userId = null
    ): Collection {
        if (!$this->enabled()) {
            return collect();
        }

        $validCollections = $this->validCollections($collections);
        if ($validCollections === []) {
            return collect();
        }

        $strategy = (string) ($options['hybrid_strategy'] ?? config('ai-engine.rag.hybrid.strategy', 'vector_then_graph'));
        $vectorLimit = (int) ($options['vector_limit'] ?? config('ai-engine.rag.hybrid.vector_limit', max($maxResults * 2, $maxResults)));
        $graphLimit = (int) ($options['graph_limit'] ?? config('ai-engine.rag.hybrid.graph_limit', max($maxResults * 2, $maxResults)));

        $vectorResults = collect();
        $graphResults = collect();

        if (in_array($strategy, ['vector_then_graph', 'reciprocal_rank'], true)) {
            $vectorResults = $this->searchVector($searchQueries, $validCollections, $vectorLimit, $threshold, $userId);
        }

        if ($this->graphRetrieval->enabled()) {
            $graphOptions = $options;
            if ($vectorResults->isNotEmpty()) {
                $graphOptions['last_entity_list'] = $this->buildEntityListSeed($vectorResults);
            }

            $graphResults = $this->graphRetrieval->retrieveRelevantContext(
                $searchQueries,
                $validCollections,
                $graphLimit,
                $graphOptions,
                $userId
            );
        }

        if ($strategy === 'graph_then_vector' && $vectorResults->isEmpty()) {
            $vectorResults = $this->searchVector($searchQueries, $validCollections, $vectorLimit, $threshold, $userId);
        }

        $merged = $this->mergeResults($vectorResults, $graphResults, $maxResults, $strategy);

        Log::channel('ai-engine')->info('Hybrid graph/vector retrieval completed', [
            'strategy' => $strategy,
            'queries' => count($searchQueries),
            'collections' => count($validCollections),
            'vector_results' => $vectorResults->count(),
            'graph_results' => $graphResults->count(),
            'merged_results' => $merged->count(),
        ]);

        return $merged;
    }

    /**
     * @param array<int, string> $searchQueries
     * @param array<int, string> $collections
     */
    protected function searchVector(
        array $searchQueries,
        array $collections,
        int $limit,
        float $threshold,
        int|string|null $userId
    ): Collection {
        $results = collect();

        foreach ($searchQueries as $query) {
            foreach ($collections as $collection) {
                $hits = $this->vectorSearch->search($collection, (string) $query, $limit, $threshold, [], $userId);
                $results = $results->merge($hits);
            }
        }

        return $results
            ->filter(fn ($item): bool => is_object($item))
            ->unique(fn ($item): string => $this->resultKey($item))
            ->sortByDesc(fn ($item): float => (float) ($item->vector_score ?? 0.0))
            ->values();
    }

    protected function mergeResults(Collection $vectorResults, Collection $graphResults, int $limit, string $strategy): Collection
    {
        $combined = [];

        $this->collectRanked($combined, $vectorResults, 'vector', $strategy);
        $this->collectRanked($combined, $graphResults, 'graph', $strategy);

        return collect($combined)
            ->map(fn (array $entry): HybridRetrievalResult => new HybridRetrievalResult(
                item: $entry['item'],
                score: round((float) $entry['score'], 6),
                sources: array_values(array_unique($entry['sources'])),
                metadata: [
                    'hybrid_strategy' => $strategy,
                    'vector_score' => $entry['vector_score'] ?? null,
                    'graph_score' => $entry['graph_score'] ?? null,
                    'reciprocal_rank_score' => $entry['rrf_score'] ?? null,
                ]
            ))
            ->sortByDesc(fn (HybridRetrievalResult $result): float => $result->score)
            ->take(max(1, $limit))
            ->map(fn (HybridRetrievalResult $result): object => $result->toContextObject())
            ->values();
    }

    /**
     * @param array<string, array<string, mixed>> $combined
     */
    protected function collectRanked(array &$combined, Collection $results, string $source, string $strategy): void
    {
        $rank = 0;
        $rrfK = max(1, (int) config('ai-engine.rag.hybrid.rrf_k', 60));

        foreach ($results->values() as $item) {
            if (!is_object($item)) {
                continue;
            }

            $rank++;
            $key = $this->resultKey($item);
            $rawScore = (float) ($item->vector_score ?? 0.0);
            $score = $strategy === 'reciprocal_rank'
                ? (1.0 / ($rrfK + $rank))
                : $this->weightedScore($rawScore, $source);

            if (!isset($combined[$key])) {
                $combined[$key] = [
                    'item' => $item,
                    'score' => 0.0,
                    'sources' => [],
                ];
            }

            $combined[$key]['score'] += $score;
            $combined[$key]['sources'][] = $source;
            $combined[$key][$source . '_score'] = max((float) ($combined[$key][$source . '_score'] ?? 0.0), $rawScore);

            if ($rawScore > (float) ($combined[$key]['item']->vector_score ?? 0.0)) {
                $combined[$key]['item'] = $item;
            }

            if ($strategy === 'reciprocal_rank') {
                $combined[$key]['rrf_score'] = (float) ($combined[$key]['rrf_score'] ?? 0.0) + $score;
            }
        }
    }

    protected function weightedScore(float $score, string $source): float
    {
        $weight = $source === 'graph'
            ? (float) config('ai-engine.rag.hybrid.graph_weight', 0.4)
            : (float) config('ai-engine.rag.hybrid.vector_weight', 0.6);

        return max(0.0, $score) * $weight;
    }

    protected function buildEntityListSeed(Collection $vectorResults): array
    {
        $refs = $vectorResults
            ->map(function ($item): ?array {
                $metadata = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];
                $entityRef = is_array($metadata['entity_ref'] ?? null) ? $metadata['entity_ref'] : [];
                $entityKey = $metadata['graph_node_id'] ?? $metadata['entity_key'] ?? $entityRef['entity_key'] ?? null;

                if (!$entityKey && !empty($entityRef['model_class']) && array_key_exists('model_id', $entityRef)) {
                    $entityKey = implode(':', [
                        $entityRef['source_node'] ?? 'local',
                        $entityRef['model_class'],
                        (string) $entityRef['model_id'],
                    ]);
                }

                if (!$entityKey) {
                    return null;
                }

                return [
                    'entity_key' => (string) $entityKey,
                    'model_class' => $metadata['model_class'] ?? $entityRef['model_class'] ?? null,
                    'model_id' => $metadata['model_id'] ?? $entityRef['model_id'] ?? null,
                    'source_node' => $metadata['source_node'] ?? $entityRef['source_node'] ?? null,
                ];
            })
            ->filter()
            ->unique('entity_key')
            ->values()
            ->all();

        return ['entity_refs' => $refs];
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    protected function validCollections(array $collections): array
    {
        return array_values(array_filter($collections, static function ($collection): bool {
            if (!is_string($collection) || !class_exists($collection)) {
                return false;
            }

            if (!is_subclass_of($collection, Model::class)) {
                return false;
            }

            $uses = class_uses_recursive($collection);

            return in_array(Vectorizable::class, $uses, true)
                || in_array(VectorizableWithMedia::class, $uses, true);
        }));
    }

    protected function resultKey(object $item): string
    {
        $metadata = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];
        $entityKey = (string) ($item->entity_key ?? $metadata['graph_node_id'] ?? $metadata['entity_key'] ?? '');
        if ($entityKey !== '') {
            return $entityKey;
        }

        return (string) ($metadata['model_class'] ?? get_class($item)) . ':' . (string) ($metadata['model_id'] ?? $item->id ?? spl_object_id($item));
    }
}
