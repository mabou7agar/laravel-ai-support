<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\EmbeddingService;

class VectorSearchService
{
    protected VectorDriverManager $driverManager;
    protected EmbeddingService $embeddingService;

    public function __construct(
        VectorDriverManager $driverManager,
        EmbeddingService $embeddingService
    ) {
        $this->driverManager = $driverManager;
        $this->embeddingService = $embeddingService;
    }

    /**
     * Search for similar vectors
     */
    public function search(
        string $modelClass,
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = [],
        ?string $userId = null
    ): Collection {
        try {
            // Generate query embedding
            $queryEmbedding = $this->embeddingService->embed($query, $userId);

            // Get collection name
            $collectionName = $this->getCollectionName($modelClass);

            // Search in vector database
            $driver = $this->driverManager->driver();
            $results = $driver->search(
                $collectionName,
                $queryEmbedding,
                $limit,
                $threshold,
                $filters
            );

            // Hydrate models from results
            return $this->hydrateModels($modelClass, $results);
        } catch (\Exception $e) {
            Log::error('Vector search failed', [
                'model' => $modelClass,
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Find similar items to a given model
     */
    public function findSimilar(
        object $model,
        int $limit = 10,
        float $threshold = 0.3,
        array $filters = []
    ): Collection {
        try {
            $modelClass = get_class($model);
            $collectionName = $this->getCollectionName($modelClass);

            // Get the model's vector from database
            $driver = $this->driverManager->driver();
            $vectorData = $driver->get($collectionName, (string) $model->id);

            if (!$vectorData || empty($vectorData['vector'])) {
                Log::warning('Model vector not found', [
                    'model' => $modelClass,
                    'id' => $model->id,
                ]);
                return collect();
            }

            // Search for similar vectors
            $results = $driver->search(
                $collectionName,
                $vectorData['vector'],
                $limit + 1, // +1 to exclude self
                $threshold,
                $filters
            );

            // Filter out the original model
            $results = array_filter($results, function ($result) use ($model) {
                return $result['id'] != $model->id;
            });

            // Limit results
            $results = array_slice($results, 0, $limit);

            // Hydrate models
            return $this->hydrateModels($modelClass, $results);
        } catch (\Exception $e) {
            Log::error('Find similar failed', [
                'model' => get_class($model),
                'id' => $model->id,
                'error' => $e->getMessage(),
            ]);
            return collect();
        }
    }

    /**
     * Index a model's vector
     */
    public function index(object $model, ?string $userId = null): bool
    {
        try {
            $modelClass = get_class($model);
            $collectionName = $this->getCollectionName($modelClass);

            // Get indexable content from model
            $content = $this->getIndexableContent($model);
            
            if (empty($content)) {
                Log::warning('No indexable content found', [
                    'model' => $modelClass,
                    'id' => $model->id,
                ]);
                return false;
            }

            // Generate embedding
            $embedding = $this->embeddingService->embed($content, $userId);

            // Prepare metadata
            $metadata = $this->getMetadata($model);

            // Upsert to vector database
            $driver = $this->driverManager->driver();
            $success = $driver->upsert($collectionName, [[
                'id' => (string) $model->id,
                'vector' => $embedding,
                'metadata' => $metadata,
            ]]);

            if ($success) {
                Log::info('Model indexed successfully', [
                    'model' => $modelClass,
                    'id' => $model->id,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Model indexing failed', [
                'model' => get_class($model),
                'id' => $model->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Index multiple models (batch)
     */
    public function indexBatch(Collection $models, ?string $userId = null): int
    {
        if ($models->isEmpty()) {
            return 0;
        }

        $modelClass = get_class($models->first());
        $collectionName = $this->getCollectionName($modelClass);

        try {
            // Prepare content for batch embedding
            $contents = [];
            $validModels = [];
            
            foreach ($models as $model) {
                $content = $this->getIndexableContent($model);
                if (!empty($content)) {
                    $contents[] = $content;
                    $validModels[] = $model;
                }
            }

            if (empty($contents)) {
                return 0;
            }

            // Generate embeddings in batch
            $embeddings = $this->embeddingService->embedBatch($contents, $userId);

            // Prepare vectors for upsert
            $vectors = [];
            foreach ($validModels as $index => $model) {
                $vectors[] = [
                    'id' => (string) $model->id,
                    'vector' => $embeddings[$index],
                    'metadata' => $this->getMetadata($model),
                ];
            }

            // Upsert to vector database
            $driver = $this->driverManager->driver();
            $success = $driver->upsert($collectionName, $vectors);

            $count = $success ? count($vectors) : 0;

            Log::info('Batch indexing completed', [
                'model' => $modelClass,
                'count' => $count,
            ]);

            return $count;
        } catch (\Exception $e) {
            Log::error('Batch indexing failed', [
                'model' => $modelClass,
                'count' => $models->count(),
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Delete model from index
     */
    public function deleteFromIndex(object $model): bool
    {
        try {
            $modelClass = get_class($model);
            $collectionName = $this->getCollectionName($modelClass);

            $driver = $this->driverManager->driver();
            $success = $driver->delete($collectionName, [(string) $model->id]);

            if ($success) {
                Log::info('Model removed from index', [
                    'model' => $modelClass,
                    'id' => $model->id,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Delete from index failed', [
                'model' => get_class($model),
                'id' => $model->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create collection for model
     */
    public function createCollection(string $modelClass): bool
    {
        try {
            $collectionName = $this->getCollectionName($modelClass);
            $dimensions = $this->embeddingService->getDimensions();

            $driver = $this->driverManager->driver();
            
            if ($driver->collectionExists($collectionName)) {
                Log::info('Collection already exists', ['collection' => $collectionName]);
                return true;
            }

            $success = $driver->createCollection($collectionName, $dimensions);

            if ($success) {
                Log::info('Collection created', [
                    'collection' => $collectionName,
                    'dimensions' => $dimensions,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Create collection failed', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get collection name for model
     */
    protected function getCollectionName(string $modelClass): string
    {
        $tableName = (new $modelClass)->getTable();
        return config('ai-engine.vector.collection_prefix', 'vec_') . $tableName;
    }

    /**
     * Get indexable content from model
     */
    protected function getIndexableContent(object $model): string
    {
        // Check if model has custom method
        if (method_exists($model, 'getVectorContent')) {
            return $model->getVectorContent();
        }

        // Check if model has vectorizable fields defined
        if (property_exists($model, 'vectorizable')) {
            $fields = $model->vectorizable;
            $content = [];
            
            foreach ($fields as $field) {
                if (isset($model->$field)) {
                    $content[] = $model->$field;
                }
            }
            
            return implode(' ', $content);
        }

        // Default: use common text fields
        $commonFields = ['title', 'name', 'content', 'description', 'body', 'text'];
        $content = [];
        
        foreach ($commonFields as $field) {
            if (isset($model->$field)) {
                $content[] = $model->$field;
            }
        }

        return implode(' ', $content);
    }

    /**
     * Get metadata from model
     */
    protected function getMetadata(object $model): array
    {
        $metadata = [
            'model_class' => get_class($model),
            'model_id' => $model->id,
            'created_at' => $model->created_at?->toIso8601String(),
            'updated_at' => $model->updated_at?->toIso8601String(),
        ];

        // Add custom metadata if method exists
        if (method_exists($model, 'getVectorMetadata')) {
            $metadata = array_merge($metadata, $model->getVectorMetadata());
        }

        return $metadata;
    }

    /**
     * Hydrate models from search results
     */
    protected function hydrateModels(string $modelClass, array $results): Collection
    {
        if (empty($results)) {
            return collect();
        }

        // Extract IDs
        $ids = array_column($results, 'id');

        // Fetch models from database
        $models = $modelClass::whereIn('id', $ids)->get()->keyBy('id');

        // Attach scores and return in order
        $hydrated = collect();
        foreach ($results as $result) {
            $model = $models->get($result['id']);
            if ($model) {
                $model->vector_score = $result['score'];
                $model->vector_metadata = $result['metadata'];
                $hydrated->push($model);
            }
        }

        return $hydrated;
    }

    /**
     * Get the count of indexed records for a model
     *
     * @param string $modelClass
     * @return int
     */
    public function getIndexedCount(string $modelClass): int
    {
        try {
            $collection = $this->getCollectionName($modelClass);
            $driver = $this->driverManager->driver();
            
            // Get count from vector database
            return $driver->count($collection);
        } catch (\Exception $e) {
            \Log::warning('Failed to get indexed count', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
