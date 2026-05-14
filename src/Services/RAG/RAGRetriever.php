<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Contracts\RAGRetrieverContract;

class RAGRetriever
{
    /**
     * @param array<int, RAGRetrieverContract> $retrievers
     */
    public function __construct(private readonly array $retrievers = []) {}

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        $enabled = array_flip((array) ($options['retrievers'] ?? []));
        $sources = [];

        foreach ($this->retrievers as $retriever) {
            if ($enabled !== [] && !isset($enabled[$retriever->name()])) {
                continue;
            }

            $sources = array_merge($sources, $retriever->retrieve($queries, $collections, $options, $userId));
        }

        return array_values($sources);
    }
}
