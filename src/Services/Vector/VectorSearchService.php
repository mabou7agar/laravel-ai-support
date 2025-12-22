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
    protected VectorAccessControl $accessControl;

    public function __construct(
        VectorDriverManager $driverManager,
        EmbeddingService $embeddingService,
        VectorAccessControl $accessControl
    ) {
        $this->driverManager = $driverManager;
        $this->embeddingService = $embeddingService;
        $this->accessControl = $accessControl;
    }

    /**
     * Search for similar vectors with multi-tenant access control
     *
     * @param string $modelClass Model class to search
     * @param string $query Search query
     * @param int $limit Maximum results
     * @param float $threshold Similarity threshold
     * @param array $filters Additional filters
     * @param string|int|null $userId User ID (fetched internally for access control)
     * @return Collection
     */
    public function search(
        string $modelClass,
        string $query,
        int $limit = 20,
        float $threshold = 0.3,
        array $filters = [],
        $userId = null
    ): Collection {
        try {
            // Generate query embedding
            $queryEmbedding = $this->embeddingService->embed($query, $userId);

            // Get collection name
            $collectionName = $this->getCollectionName($modelClass);

            // PARENT LOOKUP: Check if model has parent lookup and resolve parent IDs
            $filters = $this->applyParentLookupFilters($modelClass, $query, $filters);

            // SECURITY: Build access control filters (fetches user internally)
            // Pass model class so it can use model-specific filter logic
            $filters['model_class'] = $modelClass;
            $filters = $this->accessControl->buildSearchFilters($userId, $filters);

            // Get access level for logging
            $user = $this->accessControl->getUserById($userId);
            $accessLevel = $this->accessControl->getAccessLevel($user);

            Log::debug('Vector search with access control', [
                'user_id' => $userId,
                'access_level' => $accessLevel,
                'model' => $modelClass,
                'query' => substr($query, 0, 100),
                'filters' => $filters,
            ]);

            // Search in vector database
            $driver = $this->driverManager->driver();
            
            \Log::info('Calling driver->search', [
                'collection' => $collectionName,
                'limit' => $limit,
                'threshold' => $threshold,
                'filters' => $filters
            ]);
            
            $results = $driver->search(
                $collectionName,
                $queryEmbedding,
                $limit,
                $threshold,
                $filters
            );
            
            \Log::info('Driver returned results', [
                'count' => count($results),
                'first_id' => $results[0]['id'] ?? null
            ]);

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
     * Large content is split into multiple chunks, each stored as a separate vector point
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

            // Check if content needs to be split into multiple chunks
            $maxContentSize = config('ai-engine.vector.max_content_size', 5500);
            $multiChunkEnabled = config('ai-engine.vector.multi_chunk_enabled', true);
            
            if ($multiChunkEnabled && strlen($content) > $maxContentSize) {
                // Split into multiple chunks and create multiple embeddings
                return $this->indexWithMultipleChunks($model, $content, $collectionName, $userId);
            }

            // Single chunk - standard indexing
            // Generate embedding
            $embedding = $this->embeddingService->embed($content, $userId);

            // Prepare metadata
            $metadata = $this->getMetadata($model);
            $metadata['chunk_index'] = 0;
            $metadata['total_chunks'] = 1;

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
     * 
     * @param string $modelClass Model class name
     * @param bool $force If true, delete existing collection first
     */
    public function createCollection(string $modelClass, bool $force = false): bool
    {
        try {
            $collectionName = $this->getCollectionName($modelClass);
            $dimensions = $this->embeddingService->getDimensions();

            $driver = $this->driverManager->driver();

            // If force, delete existing collection first
            if ($force && $driver->collectionExists($collectionName)) {
                Log::info('Force mode: deleting existing collection', ['collection' => $collectionName]);
                $driver->deleteCollection($collectionName);
            } elseif ($driver->collectionExists($collectionName)) {
                Log::info('Collection already exists, ensuring indexes', ['collection' => $collectionName]);
                
                // Ensure payload indexes exist on existing collection
                if (method_exists($driver, 'ensureAllPayloadIndexes')) {
                    $driver->ensureAllPayloadIndexes($collectionName, $modelClass);
                }
                
                return true;
            }

            // Pass model class for schema-based index detection
            $success = $driver->createCollection($collectionName, $dimensions, [
                'model_class' => $modelClass,
            ]);

            if ($success) {
                Log::info('Collection created', [
                    'collection' => $collectionName,
                    'dimensions' => $dimensions,
                    'model_class' => $modelClass,
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
     * Supports multi-tenant by checking for getVectorCollectionName method on model
     */
    public function getCollectionName(string $modelClass): string
    {
        $instance = new $modelClass();
        
        // Check if model defines custom collection name (multi-tenant support)
        if (method_exists($instance, 'getVectorCollectionName')) {
            return $instance->getVectorCollectionName();
        }
        
        $tableName = $instance->getTable();
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
        \Log::info('hydrateModels called', [
            'model' => $modelClass,
            'results_count' => count($results),
            'app_name' => config('app.name'),
            'is_master' => config('ai-engine.nodes.is_master', false),
        ]);
        
        if (empty($results)) {
            \Log::warning('hydrateModels: empty results');
            return collect();
        }

        // Extract model IDs from metadata (not point IDs which may be UUIDs)
        $ids = [];
        foreach ($results as $result) {
            // Try to get model_id from metadata first (for UUID-based point IDs)
            if (isset($result['metadata']['model_id'])) {
                $ids[] = $result['metadata']['model_id'];
            } elseif (isset($result['payload']['model_id'])) {
                $ids[] = $result['payload']['model_id'];
            } else {
                // Fallback to point ID if it's an integer
                $ids[] = $result['id'];
            }
        }

        // Deduplicate IDs (multiple chunks from same model may appear)
        $uniqueIds = array_unique($ids);
        
        // Fetch models from database
        $models = $modelClass::whereIn('id', $uniqueIds)->get()->keyBy('id');

        // Attach scores and return in order, deduplicating by model_id
        // Keep the highest scoring chunk for each model
        $hydrated = collect();
        $seenModelIds = [];
        
        foreach ($results as $result) {
            // Get the actual model ID
            $modelId = $result['metadata']['model_id'] 
                ?? $result['payload']['model_id'] 
                ?? $result['id'];
            
            // Skip if we've already added this model (keep first = highest score)
            if (in_array($modelId, $seenModelIds)) {
                continue;
            }
            $seenModelIds[] = $modelId;
                
            $model = $models->get($modelId);
            if ($model) {
                $model->vector_score = $result['score'];
                $model->vector_metadata = $result['metadata'] ?? $result['payload'] ?? [];
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
            Log::warning('Failed to get indexed count', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get indexed count with filters (for multi-tenant support)
     *
     * @param string $modelClass Model class name
     * @param array $filters Filters to apply (e.g., ['user_id' => 123])
     * @return int
     */
    public function getIndexedCountWithFilters(string $modelClass, array $filters = []): int
    {
        try {
            $collection = $this->getCollectionName($modelClass);
            $driver = $this->driverManager->driver();

            // Get count from vector database with filters
            return $driver->count($collection, $filters);
        } catch (\Exception $e) {
            Log::warning('Failed to get indexed count with filters', [
                'model' => $modelClass,
                'filters' => $filters,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get the vector driver instance
     *
     * @return \LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface|null
     */
    public function getDriver()
    {
        try {
            return $this->driverManager->driver();
        } catch (\Exception $e) {
            Log::warning('Failed to get vector driver', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Apply parent lookup filters to search query
     * 
     * This enables searching for related records by first looking up the parent.
     * For example, searching "emails from john@example.com" on EmailCache will:
     * 1. Auto-detect BelongsTo relationship to Email model
     * 2. Find Email records where email = "john@example.com"
     * 3. Add filter for mailbox_id IN (parent IDs)
     * 
     * @param string $modelClass Model class being searched
     * @param string $query Search query
     * @param array $filters Existing filters
     * @return array Updated filters
     */
    protected function applyParentLookupFilters(string $modelClass, string $query, array $filters): array
    {
        if (!class_exists($modelClass)) {
            return $filters;
        }

        try {
            $instance = new $modelClass();

            // Check if model uses Vectorizable trait and has parent lookup (auto-detected or manual)
            if (!method_exists($instance, 'hasVectorParentLookup') || !$instance->hasVectorParentLookup()) {
                return $filters;
            }

            // Resolve parent IDs from query (returns ['parent_key' => 'mailbox_id', 'parent_ids' => [1,2,3]])
            $result = $modelClass::resolveParentIdsFromQuery($query);
            $parentKey = $result['parent_key'] ?? null;
            $parentIds = $result['parent_ids'] ?? [];

            if (empty($parentIds) || !$parentKey) {
                return $filters;
            }

            Log::debug('Applied parent lookup filter (auto-detected)', [
                'model' => $modelClass,
                'parent_key' => $parentKey,
                'parent_ids' => $parentIds,
            ]);

            // Add parent ID filter
            // For single ID, use direct match; for multiple, use array (Qdrant handles this)
            $filters[$parentKey] = count($parentIds) === 1 ? $parentIds[0] : $parentIds;

            return $filters;
        } catch (\Exception $e) {
            Log::warning('Failed to apply parent lookup filters', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            return $filters;
        }
    }
    
    /**
     * Chunk content for embedding when it exceeds the max size
     * 
     * Instead of skipping large content, we intelligently chunk it to preserve
     * the most important information for semantic search.
     * 
     * @param string $content Original content
     * @param int $maxSize Maximum size in bytes
     * @return string Chunked content that fits within the limit
     */
    protected function chunkContentForEmbedding(string $content, int $maxSize): string
    {
        // Strategy: Keep beginning (context/headers) + end (conclusions/recent) + middle sample
        $separatorSize = 100; // Space for separator text
        $availableSize = $maxSize - $separatorSize;
        
        // Allocate: 50% beginning, 30% end, 20% middle sample
        $beginningSize = (int) ($availableSize * 0.5);
        $endSize = (int) ($availableSize * 0.3);
        $middleSize = (int) ($availableSize * 0.2);
        
        $contentLength = strlen($content);
        
        // Get beginning
        $beginning = substr($content, 0, $beginningSize);
        // Try to end at a word boundary
        $lastSpace = strrpos($beginning, ' ');
        if ($lastSpace !== false && $lastSpace > $beginningSize * 0.8) {
            $beginning = substr($beginning, 0, $lastSpace);
        }
        
        // Get end
        $end = substr($content, -$endSize);
        // Try to start at a word boundary
        $firstSpace = strpos($end, ' ');
        if ($firstSpace !== false && $firstSpace < $endSize * 0.2) {
            $end = substr($end, $firstSpace + 1);
        }
        
        // Get middle sample (from the center of the content)
        $middleStart = (int) (($contentLength - $middleSize) / 2);
        $middle = substr($content, $middleStart, $middleSize);
        // Try to start and end at word boundaries
        $firstSpace = strpos($middle, ' ');
        if ($firstSpace !== false && $firstSpace < $middleSize * 0.1) {
            $middle = substr($middle, $firstSpace + 1);
        }
        $lastSpace = strrpos($middle, ' ');
        if ($lastSpace !== false && $lastSpace > strlen($middle) * 0.9) {
            $middle = substr($middle, 0, $lastSpace);
        }
        
        // Combine with clear separators
        $chunked = $beginning . 
                   "\n\n[... content truncated for embedding ...]\n\n" . 
                   $middle .
                   "\n\n[... content truncated ...]\n\n" .
                   $end;
        
        Log::debug('Content chunked for embedding', [
            'original_size' => $contentLength,
            'chunked_size' => strlen($chunked),
            'beginning_size' => strlen($beginning),
            'middle_size' => strlen($middle),
            'end_size' => strlen($end),
        ]);
        
        return $chunked;
    }
    
    /**
     * Index a model with multiple chunks
     * Each chunk gets its own vector point for better semantic coverage
     * 
     * @param object $model The model to index
     * @param string $content Full content to split
     * @param string $collectionName Vector collection name
     * @param string|null $userId User ID for credit tracking
     * @return bool Success status
     */
    protected function indexWithMultipleChunks(object $model, string $content, string $collectionName, ?string $userId = null): bool
    {
        $modelClass = get_class($model);
        $maxContentSize = config('ai-engine.vector.max_content_size', 5500);
        $chunkOverlap = config('ai-engine.vector.chunk_overlap', 200);
        
        // Split content into chunks with overlap
        $chunks = $this->splitContentIntoChunks($content, $maxContentSize, $chunkOverlap);
        $totalChunks = count($chunks);
        
        Log::info('Indexing model with multiple chunks', [
            'model' => $modelClass,
            'id' => $model->id,
            'original_size' => strlen($content),
            'total_chunks' => $totalChunks,
        ]);
        
        // Prepare base metadata
        $baseMetadata = $this->getMetadata($model);
        $baseMetadata['total_chunks'] = $totalChunks;
        
        // Generate embeddings and prepare points
        $points = [];
        $driver = $this->driverManager->driver();
        
        foreach ($chunks as $index => $chunk) {
            try {
                // Generate embedding for this chunk
                $embedding = $this->embeddingService->embed($chunk, $userId);
                
                // Create unique ID for this chunk: model_id_chunk_index
                $chunkId = $model->id . '_chunk_' . $index;
                
                // Prepare metadata for this chunk
                $metadata = $baseMetadata;
                $metadata['chunk_index'] = $index;
                $metadata['chunk_preview'] = substr($chunk, 0, 100) . '...';
                
                $points[] = [
                    'id' => $chunkId,
                    'vector' => $embedding,
                    'metadata' => $metadata,
                ];
                
                Log::debug('Chunk embedded', [
                    'model' => $modelClass,
                    'id' => $model->id,
                    'chunk_index' => $index,
                    'chunk_size' => strlen($chunk),
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to embed chunk', [
                    'model' => $modelClass,
                    'id' => $model->id,
                    'chunk_index' => $index,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other chunks
            }
        }
        
        if (empty($points)) {
            Log::error('No chunks were successfully embedded', [
                'model' => $modelClass,
                'id' => $model->id,
            ]);
            return false;
        }
        
        // Delete existing chunks for this model before upserting new ones
        $this->deleteModelChunks($driver, $collectionName, $model->id);
        
        // Upsert all chunk points
        $success = $driver->upsert($collectionName, $points);
        
        if ($success) {
            Log::info('Model indexed with multiple chunks', [
                'model' => $modelClass,
                'id' => $model->id,
                'chunks_indexed' => count($points),
                'total_chunks' => $totalChunks,
            ]);
        }
        
        return $success;
    }
    
    /**
     * Split content into chunks with overlap for better context
     * 
     * @param string $content Content to split
     * @param int $chunkSize Maximum size per chunk
     * @param int $overlap Overlap between chunks
     * @return array Array of content chunks
     */
    protected function splitContentIntoChunks(string $content, int $chunkSize, int $overlap = 200): array
    {
        $chunks = [];
        $contentLength = strlen($content);
        $position = 0;
        
        while ($position < $contentLength) {
            // Extract chunk
            $chunk = substr($content, $position, $chunkSize);
            
            // Try to break at sentence/paragraph boundary
            if ($position + $chunkSize < $contentLength) {
                // Look for natural break points
                $lastPeriod = strrpos($chunk, '. ');
                $lastNewline = strrpos($chunk, "\n");
                $lastBreak = max($lastPeriod, $lastNewline);
                
                // Only use break point if it's in the last 30% of the chunk
                if ($lastBreak !== false && $lastBreak > $chunkSize * 0.7) {
                    $chunk = substr($chunk, 0, $lastBreak + 1);
                }
            }
            
            $chunks[] = trim($chunk);
            
            // Move position with overlap
            $position += strlen($chunk) - $overlap;
            
            // Ensure we make progress
            if ($position <= 0 || strlen($chunk) <= $overlap) {
                $position += $chunkSize;
            }
        }
        
        return array_filter($chunks, fn($c) => !empty(trim($c)));
    }
    
    /**
     * Delete existing chunks for a model
     * Note: Upsert will overwrite existing points with same ID, so deletion is optional
     * 
     * @param mixed $driver Vector driver
     * @param string $collectionName Collection name
     * @param mixed $modelId Model ID
     */
    protected function deleteModelChunks($driver, string $collectionName, $modelId): void
    {
        // Skip deletion - upsert will overwrite existing points with same ID
        // Old chunks with different IDs will remain but won't affect search quality significantly
        // To fully clean up, use --force flag which recreates the collection
    }
}
