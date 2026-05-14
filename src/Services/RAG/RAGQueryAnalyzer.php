<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

class RAGQueryAnalyzer
{
    public function analyze(string $query, array $options = []): array
    {
        $queries = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) ($options['search_queries'] ?? [$query])
        )));

        return [
            'query' => $query,
            'queries' => $queries === [] ? [$query] : $queries,
            'intent' => $options['intent'] ?? 'retrieve_context',
            'metadata' => ['source' => 'rag_query_analyzer'],
        ];
    }
}
