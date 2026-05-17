<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\ConversationMemoryResult;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;

class ConversationMemoryRetriever
{
    public function __construct(
        protected ConversationMemoryRepository $repository,
        protected ConversationMemoryPolicy $policy,
        protected ?ConversationMemorySemanticIndex $semanticIndex = null,
    ) {
    }

    /**
     * @return array<int, ConversationMemoryResult>
     */
    public function retrieve(ConversationMemoryQuery $query): array
    {
        if (! $this->policy->enabled()) {
            return [];
        }

        $limit = max(1, min($query->limit, $this->policy->maxMemoriesPerTurn()));
        $results = $this->semanticResults($query);
        $seen = [];
        foreach ($results as $result) {
            if ($result->item->memoryId !== null) {
                $seen[$result->item->memoryId] = true;
            }
        }

        $lexical = array_values(array_filter(
            $this->repository->search($query),
            fn (ConversationMemoryResult $result): bool => $result->score >= $this->policy->minScore()
        ));

        foreach ($lexical as $result) {
            if ($result->item->memoryId !== null && isset($seen[$result->item->memoryId])) {
                continue;
            }

            $results[] = $result;
        }

        usort($results, static function (ConversationMemoryResult $a, ConversationMemoryResult $b): int {
            return $b->score <=> $a->score;
        });

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<int, ConversationMemoryResult>
     */
    protected function semanticResults(ConversationMemoryQuery $query): array
    {
        if (!$this->policy->semanticEnabled()) {
            return [];
        }

        $scores = $this->semanticIndex()->search($query);
        if ($scores === []) {
            return [];
        }

        $items = $this->repository->findScopedByMemoryIds(array_keys($scores), $query);
        $results = [];
        foreach ($scores as $memoryId => $score) {
            if (!isset($items[$memoryId]) || $score < $this->policy->minScore()) {
                continue;
            }

            $results[] = new ConversationMemoryResult(
                item: $items[$memoryId],
                score: $score,
                reason: 'semantic_index'
            );
        }

        return $results;
    }

    protected function semanticIndex(): ConversationMemorySemanticIndex
    {
        return $this->semanticIndex ??= app(ConversationMemorySemanticIndex::class);
    }
}
