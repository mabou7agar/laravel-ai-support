<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG\Retrievers;

use LaravelAIEngine\Contracts\RAGRetrieverContract;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService;

class HybridRAGRetriever implements RAGRetrieverContract
{
    public function __construct(private readonly ?HybridGraphVectorSearchService $hybridRetrieval = null) {}

    public function name(): string
    {
        return 'hybrid';
    }

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        if ($this->hybridRetrieval === null || !$this->hybridRetrieval->enabled()) {
            return [];
        }

        return array_map(
            static fn (mixed $result): RAGSource => RAGSource::fromMixed($result, 'hybrid'),
            $this->hybridRetrieval->retrieveRelevantContext(
                $queries,
                $collections,
                (int) ($options['limit'] ?? 5),
                (float) ($options['threshold'] ?? 0.3),
                $options,
                $userId
            )->all()
        );
    }
}
