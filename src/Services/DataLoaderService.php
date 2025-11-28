<?php

namespace LaravelAIEngine\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Data Loader Service
 * 
 * Efficiently loads models with relationships for indexing
 * Prevents N+1 queries and handles large datasets
 */
class DataLoaderService
{
    /**
     * Load models for indexing with relationships
     * 
     * @param string $modelClass
     * @param array $relationships
     * @param int $batchSize
     * @param array|null $ids Specific IDs to load
     * @return \Generator
     */
    public function loadModelsForIndexing(
        string $modelClass,
        array $relationships = [],
        int $batchSize = 100,
        ?array $ids = null
    ): \Generator {
        $query = $modelClass::query();
        
        // Filter by specific IDs if provided
        if ($ids !== null && !empty($ids)) {
            $query->whereIn('id', $ids);
        }
        
        // Eager load relationships to prevent N+1
        if (!empty($relationships)) {
            $query->with($relationships);
        }
        
        // Chunk the results for memory efficiency
        $query->chunk($batchSize, function (Collection $models) {
            yield $models;
        });
    }
    
    /**
     * Load a single model with relationships
     * 
     * @param Model $model
     * @param array $relationships
     * @return Model
     */
    public function loadModelWithRelationships(Model $model, array $relationships): Model
    {
        if (empty($relationships)) {
            return $model;
        }
        
        $model->loadMissing($relationships);
        
        return $model;
    }
    
    /**
     * Get total count for progress tracking
     * 
     * @param string $modelClass
     * @param array|null $ids
     * @return int
     */
    public function getTotalCount(string $modelClass, ?array $ids = null): int
    {
        $query = $modelClass::query();
        
        if ($ids !== null && !empty($ids)) {
            $query->whereIn('id', $ids);
        }
        
        return $query->count();
    }
    
    /**
     * Load models in batches with callback
     * 
     * @param string $modelClass
     * @param callable $callback
     * @param array $relationships
     * @param int $batchSize
     * @param array|null $ids
     * @return array Statistics
     */
    public function loadInBatches(
        string $modelClass,
        callable $callback,
        array $relationships = [],
        int $batchSize = 100,
        ?array $ids = null
    ): array {
        $processed = 0;
        $failed = 0;
        $startTime = microtime(true);
        
        foreach ($this->loadModelsForIndexing($modelClass, $relationships, $batchSize, $ids) as $models) {
            foreach ($models as $model) {
                try {
                    $callback($model);
                    $processed++;
                } catch (\Exception $e) {
                    $failed++;
                    \Log::error('Failed to process model', [
                        'model' => get_class($model),
                        'id' => $model->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        $duration = microtime(true) - $startTime;
        
        return [
            'processed' => $processed,
            'failed' => $failed,
            'duration' => round($duration, 2),
            'per_second' => $duration > 0 ? round($processed / $duration, 2) : 0,
        ];
    }
    
    /**
     * Optimize query for large datasets
     * 
     * @param string $modelClass
     * @param array $relationships
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function optimizeQuery(string $modelClass, array $relationships = [])
    {
        $query = $modelClass::query();
        
        // Eager load relationships
        if (!empty($relationships)) {
            $query->with($relationships);
        }
        
        // Select only necessary columns if model has vectorizable fields
        $model = new $modelClass;
        if (property_exists($model, 'vectorizable') && !empty($model->vectorizable)) {
            $columns = array_merge(['id'], $model->vectorizable);
            
            // Add foreign keys for relationships
            foreach ($relationships as $relation) {
                if (method_exists($model, $relation)) {
                    try {
                        $relationInstance = $model->$relation();
                        if (method_exists($relationInstance, 'getForeignKeyName')) {
                            $columns[] = $relationInstance->getForeignKeyName();
                        }
                    } catch (\Exception $e) {
                        // Skip if relationship can't be resolved
                    }
                }
            }
            
            $query->select(array_unique($columns));
        }
        
        return $query;
    }
    
    /**
     * Load models with memory-efficient cursor
     * For very large datasets
     * 
     * @param string $modelClass
     * @param array $relationships
     * @param array|null $ids
     * @return \Generator
     */
    public function loadWithCursor(
        string $modelClass,
        array $relationships = [],
        ?array $ids = null
    ): \Generator {
        $query = $modelClass::query();
        
        if ($ids !== null && !empty($ids)) {
            $query->whereIn('id', $ids);
        }
        
        if (!empty($relationships)) {
            $query->with($relationships);
        }
        
        foreach ($query->cursor() as $model) {
            yield $model;
        }
    }
    
    /**
     * Estimate memory usage for batch
     * 
     * @param string $modelClass
     * @param int $batchSize
     * @return array
     */
    public function estimateMemoryUsage(string $modelClass, int $batchSize): array
    {
        // Rough estimation: ~10KB per model instance
        $estimatedPerModel = 10 * 1024; // 10KB
        $estimatedBatch = $batchSize * $estimatedPerModel;
        
        return [
            'per_model_bytes' => $estimatedPerModel,
            'per_model_kb' => round($estimatedPerModel / 1024, 2),
            'batch_bytes' => $estimatedBatch,
            'batch_mb' => round($estimatedBatch / 1024 / 1024, 2),
            'recommended_batch_size' => $this->getRecommendedBatchSize(),
        ];
    }
    
    /**
     * Get recommended batch size based on available memory
     * 
     * @return int
     */
    protected function getRecommendedBatchSize(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return 500; // No limit, use larger batch
        }
        
        // Convert to bytes
        $bytes = $this->convertToBytes($memoryLimit);
        
        // Use 10% of available memory for batch
        $availableForBatch = $bytes * 0.1;
        
        // Estimate 10KB per model
        $batchSize = (int) ($availableForBatch / (10 * 1024));
        
        // Clamp between 50 and 1000
        return max(50, min(1000, $batchSize));
    }
    
    /**
     * Convert memory limit string to bytes
     * 
     * @param string $value
     * @return int
     */
    protected function convertToBytes(string $value): int
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int) $value;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
}
