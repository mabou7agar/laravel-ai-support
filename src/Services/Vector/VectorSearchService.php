<?php

namespace LaravelAIEngine\Services\Vector;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\GraphVectorLink;
use LaravelAIEngine\DTOs\SearchDocument;
use LaravelAIEngine\Services\Tenant\MultiTenantVectorService;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use Throwable;

class VectorSearchService
{
    protected VectorDriverManager $driverManager;
    protected EmbeddingService $embeddingService;
    protected VectorAccessControl $accessControl;
    protected SearchDocumentBuilder $documentBuilder;
    protected ChunkingService $chunkingService;
    protected ?MultiTenantVectorService $tenantScope;

    public function __construct(
        VectorDriverManager $driverManager,
        EmbeddingService $embeddingService,
        VectorAccessControl $accessControl,
        SearchDocumentBuilder $documentBuilder,
        ChunkingService $chunkingService,
        ?MultiTenantVectorService $tenantScope = null
    ) {
        $this->driverManager = $driverManager;
        $this->embeddingService = $embeddingService;
        $this->accessControl = $accessControl;
        $this->documentBuilder = $documentBuilder;
        $this->chunkingService = $chunkingService;
        $this->tenantScope = $tenantScope;
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
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
            $document = $this->buildSearchDocument($model);

            // Get indexable content from model
            $content = $document->content;

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

            $chunks = $this->buildChunksForDocument($document, $maxContentSize);

            if ($multiChunkEnabled && count($chunks) > 1) {
                return $this->indexWithMultipleChunks($model, $document, $chunks, $collectionName, $userId);
            }

            // Single chunk - standard indexing
            // Generate embedding
            $chunkText = $chunks[0]['content'] ?? $content;
            $embedding = $this->embeddingService->embed($chunkText, $userId);

            // Prepare metadata
            $metadata = $this->buildIndexMetadata($model, $document, $chunkText, 0, 1);

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
        } catch (Throwable $e) {
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
            $documents = [];

            foreach ($models as $model) {
                $document = $this->buildSearchDocument($model);
                $content = $document->primaryChunk();
                if (!empty($content)) {
                    $contents[] = $content;
                    $validModels[] = $model;
                    $documents[] = $document;
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
                    'metadata' => $this->buildIndexMetadata($model, $documents[$index], $contents[$index], 0, 1),
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
        } catch (Throwable $e) {
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
            $ids = $this->discoverIndexedIdsForModel($driver, $collectionName, $model->id);
            $success = $driver->delete($collectionName, $ids);

            if ($success) {
                Log::info('Model removed from index', [
                    'model' => $modelClass,
                    'id' => $model->id,
                ]);
            }

            return $success;
        } catch (Throwable $e) {
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
        } catch (Throwable $e) {
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
        return $this->buildSearchDocument($model)->content;
    }

    /**
     * Get metadata from model
     * Includes both ISO string dates and Unix timestamps for range filtering
     */
    protected function getMetadata(object $model): array
    {
        return $this->buildSearchDocument($model)->metadata;
    }
    
    /**
     * Extract common date fields from model and add as timestamps
     */
    protected function extractDateFieldsAsTimestamps(object $model): array
    {
        $timestamps = [];
        $dateFields = ['issue_date', 'due_date', 'paid_date', 'sent_date', 'date', 'published_at', 'deleted_at'];
        
        foreach ($dateFields as $field) {
            if (isset($model->$field) && $model->$field !== null) {
                $value = $model->$field;
                if ($value instanceof \Carbon\Carbon || $value instanceof \DateTimeInterface) {
                    $timestamps[$field . '_ts'] = $value->getTimestamp();
                } elseif (is_string($value)) {
                    try {
                        $timestamps[$field . '_ts'] = strtotime($value);
                    } catch (\Exception $e) {
                        // Skip if can't parse
                    }
                }
            }
        }
        
        return $timestamps;
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
        
        // Apply limit to prevent memory exhaustion
        $maxResults = config('ai-engine.vector.max_hydrate_results', 50);
        $limitedIds = array_slice($uniqueIds, 0, $maxResults);
        
        if (count($uniqueIds) > $maxResults) {
            \Log::warning('Hydrate results limited to prevent memory exhaustion', [
                'model' => $modelClass,
                'total_ids' => count($uniqueIds),
                'limited_to' => $maxResults,
            ]);
        }
        
        // Fetch models from database
        $models = $modelClass::whereIn('id', $limitedIds)->get()->keyBy('id');

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
                $model->matched_chunk_text = $model->vector_metadata['chunk_text'] ?? null;
                $model->matched_chunk_index = $model->vector_metadata['chunk_index'] ?? null;
                $model->entity_ref = $model->vector_metadata['entity_ref'] ?? $this->buildSearchDocument($model)->entityRef();
                $model->graph_object = $model->vector_metadata['object'] ?? $this->buildSearchDocument($model)->object;
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
     * Hybrid aggregation: Vector filter + SQL aggregation
     * Uses vector DB for filtering, then SQL for efficient aggregation
     * 
     * @param string $modelClass Model class name
     * @param string $operation Aggregation operation (sum, avg, min, max, count)
     * @param string $field Database field to aggregate
     * @param array $filters Vector filters (supports date ranges)
     * @return float|int Aggregated value
     * 
     * Example:
     * $vectorSearch->aggregate(Invoice::class, 'sum', 'total', ['created_at' => ['gte' => '2026-01-01']]);
     */
    public function aggregate(string $modelClass, string $operation, string $field, array $filters = []): float|int
    {
        try {
            $collection = $this->getCollectionName($modelClass);
            $driver = $this->driverManager->driver();
            
            // Get matching IDs from vector DB
            $ids = $driver->getMatchingIds($collection, $filters);
            
            if (empty($ids)) {
                return 0;
            }
            
            // Use SQL for aggregation (much more efficient)
            $query = $modelClass::whereIn('id', $ids);
            
            return match ($operation) {
                'sum' => (float) $query->sum($field),
                'avg' => (float) $query->avg($field),
                'min' => (float) $query->min($field),
                'max' => (float) $query->max($field),
                'count' => (int) $query->count(),
                default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
            };
            
        } catch (\Exception $e) {
            Log::error('Hybrid aggregation failed', [
                'model' => $modelClass,
                'operation' => $operation,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
    
    /**
     * Sum field values for records matching vector filters
     */
    public function sum(string $modelClass, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($modelClass, 'sum', $field, $filters);
    }
    
    /**
     * Average field values for records matching vector filters
     */
    public function avg(string $modelClass, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($modelClass, 'avg', $field, $filters);
    }
    
    /**
     * Minimum field value for records matching vector filters
     */
    public function min(string $modelClass, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($modelClass, 'min', $field, $filters);
    }
    
    /**
     * Maximum field value for records matching vector filters
     */
    public function max(string $modelClass, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($modelClass, 'max', $field, $filters);
    }
    
    /**
     * Count records matching vector filters (uses vector DB count, not SQL)
     */
    public function countWithFilters(string $modelClass, array $filters = []): int
    {
        return $this->getIndexedCountWithFilters($modelClass, $filters);
    }
    
    /**
     * Get all model IDs matching vector filters
     * Useful for custom queries
     */
    public function getMatchingIds(string $modelClass, array $filters = []): array
    {
        try {
            $collection = $this->getCollectionName($modelClass);
            $driver = $this->driverManager->driver();
            
            return $driver->getMatchingIds($collection, $filters);
        } catch (\Exception $e) {
            Log::error('Failed to get matching IDs', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            return [];
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
    protected function indexWithMultipleChunks(
        object $model,
        SearchDocument $document,
        array $chunks,
        string $collectionName,
        ?string $userId = null
    ): bool
    {
        $modelClass = get_class($model);
        $totalChunks = count($chunks);
        
        Log::info('Indexing model with multiple chunks', [
            'model' => $modelClass,
            'id' => $model->id,
            'original_size' => strlen($document->content),
            'total_chunks' => $totalChunks,
        ]);

        // Generate embeddings and prepare points
        $points = [];
        $driver = $this->driverManager->driver();
        
        foreach ($chunks as $index => $chunk) {
            try {
                $chunkText = $chunk['content'] ?? '';
                if ($chunkText === '') {
                    continue;
                }

                // Generate embedding for this chunk
                $embedding = $this->embeddingService->embed($chunkText, $userId);
                
                // Create unique ID for this chunk: model_id_chunk_index
                $chunkId = $model->id . '_chunk_' . $index;
                
                // Prepare metadata for this chunk
                $metadata = $this->buildIndexMetadata($model, $document, $chunkText, $index, $totalChunks);
                
                $points[] = [
                    'id' => $chunkId,
                    'vector' => $embedding,
                    'metadata' => $metadata,
                ];
                
                Log::debug('Chunk embedded', [
                    'model' => $modelClass,
                    'id' => $model->id,
                    'chunk_index' => $index,
                    'chunk_size' => strlen($chunkText),
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
        $ids = $this->discoverIndexedIdsForModel($driver, $collectionName, $modelId);
        if ($ids === []) {
            return;
        }

        try {
            $driver->delete($collectionName, $ids);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete model chunks before reindex', [
                'collection' => $collectionName,
                'model_id' => $modelId,
                'ids' => $ids,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function buildSearchDocument(object $model): SearchDocument
    {
        return $this->documentBuilder->build($model);
    }

    /**
     * @return array<int, array{content:string,index:int}>
     */
    protected function buildChunksForDocument(SearchDocument $document, int $maxContentSize): array
    {
        $normalized = [];

        foreach ($document->normalizedChunks() as $chunk) {
            $chunkText = trim((string) ($chunk['content'] ?? ''));
            if ($chunkText === '') {
                continue;
            }

            if (strlen($chunkText) > $maxContentSize) {
                foreach ($this->chunkingService->chunk($chunkText, [
                    'chunk_size' => $maxContentSize,
                    'overlap' => (int) config('ai-engine.vector.chunk_overlap', 200),
                ]) as $splitChunk) {
                    $normalized[] = [
                        'content' => $splitChunk,
                        'index' => count($normalized),
                    ];
                }

                continue;
            }

            $normalized[] = [
                'content' => $chunkText,
                'index' => is_numeric($chunk['index'] ?? null) ? (int) $chunk['index'] : count($normalized),
            ];
        }

        if ($normalized === []) {
            return [[
                'content' => $document->content,
                'index' => 0,
            ]];
        }

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildIndexMetadata(
        object $model,
        SearchDocument $document,
        string $chunkText,
        int $chunkIndex,
        int $totalChunks
    ): array {
        $link = GraphVectorLink::fromSearchDocument(
            $document,
            $chunkIndex,
            $this->getCollectionName(get_class($model)),
            GraphVectorLink::pointId($document->modelId, $chunkIndex, $totalChunks > 1)
        );

        $metadata = array_merge(
            $document->metadata,
            [
                'model_class' => $document->modelClass,
                'model_id' => $document->modelId,
                'title' => $document->title,
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks,
                'chunk_text' => $chunkText,
                'chunk_preview' => mb_substr($chunkText, 0, 200),
                'entity_ref' => $document->entityRef(),
                'object' => $document->object,
                'app_slug' => $document->appSlug,
                'source_node' => $document->sourceNode,
                'scope_type' => $document->scopeType,
                'scope_id' => $document->scopeId,
                'scope_label' => $document->scopeLabel,
                'graph_node_id' => $link->graphNodeId,
                'graph_chunk_id' => $link->graphChunkId,
                'vector_collection' => $link->vectorCollection,
                'vector_point_id' => $link->vectorPointId,
                'qdrant_collection' => $link->vectorCollection,
                'qdrant_point_id' => $link->vectorPointId,
                'graph_vector_link' => $link->toArray(),
            ]
        );

        return $this->tenantScope instanceof MultiTenantVectorService
            ? $this->tenantScope->applyScopeToMetadata($metadata)
            : $metadata;
    }

    /**
     * @return array<int, string>
     */
    protected function discoverIndexedIdsForModel($driver, string $collectionName, string|int $modelId): array
    {
        $modelIdString = (string) $modelId;
        $ids = [$modelIdString];
        $offset = null;
        $guard = 0;

        do {
            $page = $driver->scroll($collectionName, 200, $offset);

            foreach (($page['points'] ?? []) as $point) {
                $pointId = (string) ($point['id'] ?? '');
                $metadata = $point['metadata'] ?? [];
                $pointModelId = (string) ($metadata['model_id'] ?? '');

                if ($pointModelId === $modelIdString || str_starts_with($pointId, $modelIdString . '_chunk_')) {
                    $ids[] = $pointId;
                }
            }

            $offset = $page['next_offset'] ?? null;
            $guard++;
        } while ($offset !== null && $guard < 100);

        return array_values(array_unique(array_filter($ids)));
    }
}
