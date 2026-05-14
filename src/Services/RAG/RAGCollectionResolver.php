<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

class RAGCollectionResolver
{
    public function resolve(array $options = []): array
    {
        $collections = $options['collections']
            ?? $options['rag_collections']
            ?? $options['available_collections']
            ?? [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $collection): string => is_array($collection)
                ? (string) ($collection['class'] ?? $collection['name'] ?? '')
                : (string) $collection,
            (array) $collections
        ))));
    }
}
