<?php

namespace LaravelAIEngine\Traits;

use Illuminate\Support\Collection;
use LaravelAIEngine\Services\Vector\VectorSearchService;

trait HasVectorSearch
{
    /**
     * Perform a vector search
     */
    public static function vectorSearch(
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = [],
        ?string $userId = null
    ): Collection {
        $service = app(VectorSearchService::class);
        
        return $service->search(
            static::class,
            $query,
            $limit,
            $threshold,
            $filters,
            $userId
        );
    }

    /**
     * Find similar models to this instance
     */
    public function findSimilar(
        int $limit = 10,
        float $threshold = 0.3,
        array $filters = []
    ): Collection {
        $service = app(VectorSearchService::class);
        
        return $service->findSimilar($this, $limit, $threshold, $filters);
    }

    /**
     * Index this model in the vector database
     */
    public function indexVector(?string $userId = null): bool
    {
        $service = app(VectorSearchService::class);
        
        return $service->index($this, $userId);
    }

    /**
     * Remove this model from the vector index
     */
    public function deleteFromVectorIndex(): bool
    {
        $service = app(VectorSearchService::class);
        
        return $service->deleteFromIndex($this);
    }

    /**
     * Create vector collection for this model
     */
    public static function createVectorCollection(): bool
    {
        $service = app(VectorSearchService::class);
        
        return $service->createCollection(static::class);
    }

    /**
     * Index multiple models in batch
     */
    public static function indexVectorBatch(Collection $models, ?string $userId = null): int
    {
        $service = app(VectorSearchService::class);
        
        return $service->indexBatch($models, $userId);
    }

    /**
     * Boot the trait
     */
    protected static function bootHasVectorSearch(): void
    {
        // Auto-index on model save if enabled
        if (config('ai-engine.vector.auto_index', false)) {
            static::saved(function ($model) {
                $model->indexVector();
            });
        }

        // Auto-delete from index on model delete if enabled
        if (config('ai-engine.vector.auto_delete', true)) {
            static::deleted(function ($model) {
                $model->deleteFromVectorIndex();
            });
        }
    }
}
