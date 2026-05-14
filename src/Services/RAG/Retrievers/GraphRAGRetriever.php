<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG\Retrievers;

use LaravelAIEngine\Contracts\RAGRetrieverContract;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;

class GraphRAGRetriever implements RAGRetrieverContract
{
    public function __construct(private readonly ?Neo4jRetrievalService $graphRetrieval = null) {}

    public function name(): string
    {
        return 'graph';
    }

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        if ($this->graphRetrieval === null || !$this->graphRetrieval->enabled()) {
            return [];
        }

        return array_map(
            static fn (mixed $result): RAGSource => RAGSource::fromMixed($result, 'graph'),
            $this->graphRetrieval->retrieveRelevantContext(
                $queries,
                $collections,
                (int) ($options['limit'] ?? 5),
                $options,
                $userId
            )->all()
        );
    }
}
