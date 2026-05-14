<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG\Retrievers;

use LaravelAIEngine\Contracts\RAGRetrieverContract;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\Services\Vector\VectorSearchService;

class VectorRAGRetriever implements RAGRetrieverContract
{
    public function __construct(private readonly ?VectorSearchService $vectorSearch = null) {}

    public function name(): string
    {
        return 'vector';
    }

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        if ($this->vectorSearch === null) {
            return [];
        }

        $limit = (int) ($options['limit'] ?? 5);
        $threshold = (float) ($options['threshold'] ?? 0.3);
        $sources = [];

        foreach ($queries as $query) {
            foreach ($collections as $collection) {
                foreach ($this->vectorSearch->search($collection, (string) $query, $limit, $threshold, $this->scopeFilters($options), $userId) as $result) {
                    $sources[] = RAGSource::fromMixed($result, 'vector');
                }
            }
        }

        return $sources;
    }

    private function scopeFilters(array $options): array
    {
        return array_filter([
            'tenant_id' => (bool) config('vector-access-control.enable_tenant_scope', true)
                ? ($options['tenant_id'] ?? $options['tenant'] ?? null)
                : null,
            'workspace_id' => (bool) config('vector-access-control.enable_workspace_scope', true)
                ? ($options['workspace_id'] ?? $options['workspace'] ?? null)
                : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
