<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Vector\Drivers;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QdrantDriver implements VectorDriverInterface
{
    protected Client $client;
    protected string $host;
    protected ?string $apiKey;
    protected int $timeout;
    protected ?QdrantFilterBuilder $filterBuilder = null;
    protected ?QdrantPayloadIndexManager $payloadIndexManager = null;

    public function __construct(array $config = [])
    {
        $this->host = $config['host'] ?? config('ai-engine.vector.drivers.qdrant.host', 'http://localhost:6333');
        $this->apiKey = $config['api_key'] ?? config('ai-engine.vector.drivers.qdrant.api_key');
        $this->timeout = $config['timeout'] ?? config('ai-engine.vector.drivers.qdrant.timeout', 30);

        $headers = ['Content-Type' => 'application/json'];
        if ($this->apiKey) {
            $headers['api-key'] = $this->apiKey;
        }

        $this->client = new Client([
            'base_uri' => $this->host,
            'timeout' => $this->timeout,
            'headers' => $headers,
        ]);
    }

    public function createCollection(string $name, int $dimensions, array $config = []): bool
    {
        try {
            $response = $this->client->put("/collections/{$name}", [
                'json' => [
                    'vectors' => [
                        'size' => $dimensions,
                        'distance' => $config['distance'] ?? 'Cosine',
                    ],
                    'optimizers_config' => [
                        'default_segment_number' => $config['segment_number'] ?? 2,
                    ],
                    'replication_factor' => $config['replication_factor'] ?? 1,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return false;
            }
            
            // Wait a moment for collection to be fully ready before creating indexes
            // Qdrant cloud may need a brief delay after collection creation
            usleep(500000); // 500ms
            
            // Create payload indexes for filterable fields
            // Model class can be passed in config for schema detection
            $modelClass = $config['model_class'] ?? null;
            
            Log::info('Creating payload indexes for new collection', [
                'collection' => $name,
                'model_class' => $modelClass,
            ]);
            
            $this->createPayloadIndexes($name, $modelClass);
            
            return true;
        } catch (Throwable $e) {
            Log::error('Qdrant create collection failed', [
                'collection' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Create payload indexes for a collection based on model schema
     * This enables filtering on these fields during search
     * 
     * @param string $collection Collection name
     * @param string|null $modelClass Optional model class to detect field types from schema
     */
    public function createPayloadIndexes(string $collection, ?string $modelClass = null): void
    {
        $this->payloadIndexManager()->createPayloadIndexes($collection, $modelClass);
    }
    
    /**
     * Get custom indexes defined by model's getQdrantIndexes() method
     */
    protected function getModelCustomIndexes(?string $modelClass): array
    {
        return $this->payloadIndexManager()->getModelCustomIndexes($modelClass);
    }
    
    /**
     * Detect foreign key fields from model's belongsTo relationships
     */
    protected function detectBelongsToFields(?string $modelClass): array
    {
        return $this->payloadIndexManager()->detectBelongsToFields($modelClass);
    }
    
    /**
     * Create a single payload index
     */
    public function createPayloadIndex(string $collection, string $fieldName, string $fieldType): bool
    {
        return $this->payloadIndexManager()->createPayloadIndex($collection, $fieldName, $fieldType);
    }
    
    /**
     * Detect field types from model's database schema
     */
    protected function detectFieldTypes(?string $modelClass, array $fields): array
    {
        return $this->payloadIndexManager()->detectFieldTypes($modelClass, $fields);
    }
    
    /**
     * Map database column type to Qdrant field schema type
     */
    protected function mapDatabaseTypeToQdrant(string $dbType, string $fieldName = ''): string
    {
        return $this->payloadIndexManager()->mapDatabaseTypeToQdrant($dbType, $fieldName);
    }
    
    /**
     * Guess field type based on field name conventions
     * Note: For ID fields, we default to 'integer' but this can be overridden
     * by detectFieldTypeFromData() which samples actual data
     */
    public function guessFieldType(string $fieldName): string
    {
        return $this->payloadIndexManager()->guessFieldType($fieldName);
    }
    
    /**
     * Detect field type by sampling actual data from the collection
     * This handles UUID vs integer ID detection
     * 
     * @param string $collection Collection name
     * @param string $fieldName Field to detect type for
     * @return string|null Detected type or null if can't determine
     */
    public function detectFieldTypeFromData(string $collection, string $fieldName): ?string
    {
        return $this->payloadIndexManager()->detectFieldTypeFromData($collection, $fieldName);
    }
    
    /**
     * Get the correct field type - tries data detection first, falls back to guessing
     * 
     * @param string $collection Collection name
     * @param string $fieldName Field name
     * @return string Field type
     */
    public function getFieldType(string $collection, string $fieldName): string
    {
        return $this->payloadIndexManager()->getFieldType($collection, $fieldName);
    }

    public function deleteCollection(string $name): bool
    {
        try {
            $response = $this->client->delete("/collections/{$name}");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant delete collection failed', [
                'collection' => $name,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function collectionExists(string $name): bool
    {
        try {
            $response = $this->client->get("/collections/{$name}");
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            return false;
        }
    }

    public function upsert(string $collection, array $vectors): bool
    {
        try {
            $points = array_map(function ($vector) {
                // Qdrant accepts UUIDs or unsigned integers as point IDs
                // We'll use a combination: model_class + model_id as a unique string
                $pointId = md5($vector['metadata']['model_class'] . '_' . $vector['id']);
                
                return [
                    'id' => $pointId,
                    'vector' => $vector['vector'],
                    'payload' => array_merge(
                        $vector['metadata'] ?? [],
                        ['point_id' => $pointId] // Store point ID for reference
                    ),
                ];
            }, $vectors);

            $response = $this->client->put("/collections/{$collection}/points", [
                'json' => [
                    'points' => $points,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant upsert failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        float $threshold = 0.0,
        array $filters = []
    ): array {
        // Check if collection is cached as missing to avoid repeated failed attempts
        if (Cache::has("qdrant_collection_missing_{$collection}")) {
            return [];
        }
        
        try {
            // Ensure collection exists before searching
            if (!$this->collectionExists($collection)) {
                Log::info('Collection does not exist, creating it', ['collection' => $collection]);
                
                // Create collection with default dimensions (will be updated on first index)
                $dimensions = count($vector);
                $this->createCollection($collection, $dimensions);
                
                // Collection is empty, return no results
                return [];
            }
            
            // Ensure filter indexes exist for the fields being filtered
            if (!empty($filters)) {
                $filterFields = array_keys($filters);
                // Remove internal keys
                $filterFields = array_filter($filterFields, fn($k) => $k !== 'model_class');
                if (!empty($filterFields)) {
                    $this->ensureFilterIndexes($collection, $filterFields);
                }
            }
            
            $body = [
                'vector' => $vector,
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($threshold > 0) {
                $body['score_threshold'] = $threshold;
            }

            if (!empty($filters)) {
                $body['filter'] = $this->buildFilter($filters, $collection);
            }

            $response = $this->client->post("/collections/{$collection}/points/search", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($result) {
                return [
                    'id' => $result['payload']['model_id'] ?? $result['id'], // Use model_id from payload
                    'score' => $result['score'],
                    'metadata' => $result['payload'] ?? [],
                ];
            }, $data['result'] ?? []);
        } catch (GuzzleException $e) {
            // Check if collection doesn't exist (404 error)
            if ($e->getCode() === 404 || str_contains($e->getMessage(), "doesn't exist")) {
                // Cache that this collection doesn't exist to prevent repeated attempts
                Cache::put("qdrant_collection_missing_{$collection}", true, 3600); // Cache for 1 hour
                
                Log::warning('Qdrant collection does not exist', [
                    'collection' => $collection,
                ]);
                return [];
            }
            
            // Check if it's a 400 error (bad request - usually type mismatch in filter)
            if ($e->getCode() === 400 || str_contains($e->getMessage(), "400 Bad Request")) {
                $errorMessage = $e->getMessage();
                
                Log::warning('Qdrant search failed - attempting auto-fix', [
                    'collection' => $collection,
                    'filters' => $filters,
                    'error' => substr($errorMessage, 0, 300),
                ]);
                
                // Check if this is an index type mismatch that we can fix
                if (!empty($filters) && !Cache::has("qdrant_autofix_attempted_{$collection}")) {
                    // Mark that we've attempted auto-fix to prevent infinite loops
                    Cache::put("qdrant_autofix_attempted_{$collection}", true, 60); // 1 minute
                    
                    // Try to auto-fix index types
                    Log::info('Attempting to auto-fix index types', ['collection' => $collection]);
                    $fixed = $this->autoFixIndexTypes($collection);
                    
                    if (!empty($fixed)) {
                        Log::info('Auto-fixed indexes, retrying search', [
                            'collection' => $collection,
                            'fixed_fields' => $fixed,
                        ]);
                        
                        // Clear the auto-fix cache and retry
                        Cache::forget("qdrant_autofix_attempted_{$collection}");
                        return $this->search($collection, $vector, $limit, $threshold, $filters);
                    }
                    
                    // If auto-fix didn't help, try search without filters
                    Log::info('Auto-fix did not resolve issue, retrying without filters', ['collection' => $collection]);
                    Cache::forget("qdrant_autofix_attempted_{$collection}");
                    return $this->search($collection, $vector, $limit, $threshold, []);
                }
                
                // Clear the cache if we're here
                Cache::forget("qdrant_autofix_attempted_{$collection}");
                
                return [];
            }
            
            Log::error('Qdrant search failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function delete(string $collection, array $ids): bool
    {
        try {
            // Qdrant requires specific format for point IDs
            // For string IDs (like "384_chunk_0"), we need to use the points array with string values
            // For integer IDs, we can pass them directly
            $response = $this->client->post("/collections/{$collection}/points/delete", [
                'json' => [
                    'points' => array_map(function($id) {
                        // Keep as string if it contains non-numeric characters
                        return is_numeric($id) ? (int) $id : (string) $id;
                    }, $ids),
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant delete failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Delete points by filter (e.g., by model_id)
     */
    public function deleteByFilter(string $collection, array $filter): bool
    {
        try {
            $response = $this->client->post("/collections/{$collection}/points/delete", [
                'json' => [
                    'filter' => $filter,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant delete by filter failed', [
                'collection' => $collection,
                'filter' => $filter,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getCollectionInfo(string $collection): array
    {
        try {
            $response = $this->client->get("/collections/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            return $data['result'] ?? [];
        } catch (GuzzleException $e) {
            Log::error('Qdrant get collection info failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function get(string $collection, string $id): ?array
    {
        try {
            $response = $this->client->get("/collections/{$collection}/points/{$id}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            $result = $data['result'] ?? null;
            if (!$result) {
                return null;
            }

            return [
                'id' => $result['id'],
                'vector' => $result['vector'] ?? [],
                'metadata' => $result['payload'] ?? [],
            ];
        } catch (GuzzleException $e) {
            return null;
        }
    }

    public function updateMetadata(string $collection, string $id, array $metadata): bool
    {
        try {
            $response = $this->client->post("/collections/{$collection}/points/payload", [
                'json' => [
                    'points' => [$id],
                    'payload' => $metadata,
                ],
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::error('Qdrant update metadata failed', [
                'collection' => $collection,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function count(string $collection, array $filters = []): int
    {
        try {
            $body = ['exact' => true];
            
            if (!empty($filters)) {
                $body['filter'] = $this->buildFilter($filters, $collection);
            }

            $response = $this->client->post("/collections/{$collection}/points/count", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['result']['count'] ?? 0;
        } catch (GuzzleException $e) {
            $errorMessage = $e->getMessage();
            $cacheKey = "qdrant_count_autofix_{$collection}_" . md5(json_encode($filters));
            
            // Check if this is an index type mismatch that we can fix (only try once per filter combo)
            if (!empty($filters) && str_contains($errorMessage, 'Index required but not found') && !Cache::has($cacheKey)) {
                // Mark as attempted for 5 minutes to prevent loops
                Cache::put($cacheKey, true, 300);
                
                Log::info('Count failed due to missing index, attempting auto-fix', ['collection' => $collection]);
                
                // Try to auto-fix by creating missing indexes
                $created = $this->ensureRequiredIndexes($collection, array_keys($filters));
                
                if ($created > 0) {
                    // Wait for index to be ready
                    usleep(200000); // 200ms
                    
                    // Retry the count (only once)
                    return $this->count($collection, $filters);
                }
            }
            
            Log::error('Qdrant count failed', [
                'collection' => $collection,
                'error' => $errorMessage,
            ]);
            return 0;
        }
    }
    
    /**
     * Ensure required indexes exist for filtering
     * @return int Number of indexes created
     */
    protected function ensureRequiredIndexes(string $collection, array $fields, ?string $modelClass = null): int
    {
        return $this->payloadIndexManager()->ensureRequiredIndexes($collection, $fields, $modelClass);
    }
    
    /**
     * Aggregate data from vector collection
     * Supports: sum, avg, min, max, count
     * 
     * @param string $collection Collection name
     * @param string $operation Aggregation operation (sum, avg, min, max, count)
     * @param string $field Field to aggregate (from metadata)
     * @param array $filters Optional filters (supports date ranges)
     * @return float|int Aggregated value
     * 
     * Example:
     * $driver->aggregate('vec_invoice', 'sum', 'total', ['created_at' => ['gte' => '2026-01-01']]);
     * $driver->aggregate('vec_invoice', 'avg', 'amount', ['user_id' => 86]);
     */
    public function aggregate(string $collection, string $operation, string $field, array $filters = []): float|int
    {
        try {
            $values = [];
            $offset = null;
            $batchSize = 100;
            
            // Build filter once
            $filter = !empty($filters) ? $this->buildFilter($filters, $collection) : null;
            
            // Scroll through all matching points
            do {
                $body = [
                    'limit' => $batchSize,
                    'with_payload' => true,
                    'with_vector' => false,
                ];
                
                if ($offset !== null) {
                    $body['offset'] = $offset;
                }
                
                if ($filter !== null) {
                    $body['filter'] = $filter;
                }
                
                $response = $this->client->post("/collections/{$collection}/points/scroll", [
                    'json' => $body,
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                $points = $data['result']['points'] ?? [];
                $offset = $data['result']['next_page_offset'] ?? null;
                
                // Extract field values
                foreach ($points as $point) {
                    $value = $point['payload'][$field] ?? null;
                    if ($value !== null && is_numeric($value)) {
                        $values[] = (float) $value;
                    }
                }
                
            } while ($offset !== null && count($points) > 0);
            
            // Perform aggregation
            if (empty($values)) {
                return 0;
            }
            
            return match ($operation) {
                'sum' => array_sum($values),
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values),
                'count' => count($values),
                default => throw new \InvalidArgumentException("Unknown operation: {$operation}"),
            };
            
        } catch (GuzzleException $e) {
            Log::error('Qdrant aggregate failed', [
                'collection' => $collection,
                'operation' => $operation,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
    
    /**
     * Get all matching model IDs from vector collection
     * Useful for fetching records from database for complex aggregations
     * 
     * @param string $collection Collection name
     * @param array $filters Optional filters
     * @return array Array of model IDs
     */
    public function getMatchingIds(string $collection, array $filters = []): array
    {
        try {
            $ids = [];
            $offset = null;
            $batchSize = 100;
            
            $filter = !empty($filters) ? $this->buildFilter($filters, $collection) : null;
            
            do {
                $body = [
                    'limit' => $batchSize,
                    'with_payload' => ['include' => ['model_id']],
                    'with_vector' => false,
                ];
                
                if ($offset !== null) {
                    $body['offset'] = $offset;
                }
                
                if ($filter !== null) {
                    $body['filter'] = $filter;
                }
                
                $response = $this->client->post("/collections/{$collection}/points/scroll", [
                    'json' => $body,
                ]);
                
                $data = json_decode($response->getBody()->getContents(), true);
                $points = $data['result']['points'] ?? [];
                $offset = $data['result']['next_page_offset'] ?? null;
                
                foreach ($points as $point) {
                    $modelId = $point['payload']['model_id'] ?? null;
                    if ($modelId !== null) {
                        $ids[] = $modelId;
                    }
                }
                
            } while ($offset !== null && count($points) > 0);
            
            return array_unique($ids);
            
        } catch (GuzzleException $e) {
            Log::error('Qdrant getMatchingIds failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Shorthand for sum aggregation
     */
    public function sum(string $collection, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($collection, 'sum', $field, $filters);
    }
    
    /**
     * Shorthand for average aggregation
     */
    public function avg(string $collection, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($collection, 'avg', $field, $filters);
    }
    
    /**
     * Shorthand for min aggregation
     */
    public function min(string $collection, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($collection, 'min', $field, $filters);
    }
    
    /**
     * Shorthand for max aggregation
     */
    public function max(string $collection, string $field, array $filters = []): float
    {
        return (float) $this->aggregate($collection, 'max', $field, $filters);
    }

    public function scroll(string $collection, int $limit = 100, $offset = null): array
    {
        try {
            $body = [
                'limit' => $limit,
                'with_payload' => true,
                'with_vector' => false,
            ];

            if ($offset) {
                $body['offset'] = $offset;
            }

            $response = $this->client->post("/collections/{$collection}/points/scroll", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return [
                'points' => array_map(function ($point) {
                    return [
                        'id' => $point['id'],
                        'metadata' => $point['payload'] ?? [],
                    ];
                }, $data['result']['points'] ?? []),
                'next_offset' => $data['result']['next_page_offset'] ?? null,
            ];
        } catch (GuzzleException $e) {
            Log::error('Qdrant scroll failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return ['points' => [], 'next_offset' => null];
        }
    }

    /**
     * Build Qdrant filter from array
     * Handles type conversion to match indexed field types
     * Supports range filters and auto-converts date strings to timestamps
     * 
     * @param array $filters Filter key-value pairs
     * @param string|null $collection Collection name for type-aware conversion
     * 
     * Filter formats:
     * - Exact match: ['user_id' => 86]
     * - Multiple values: ['status' => [1, 2, 3]]
     * - Range: ['created_at' => ['gte' => '2026-01-01', 'lte' => '2026-01-31']]
     * - Range with timestamps: ['created_at_ts' => ['gte' => 1704067200, 'lte' => 1706745599]]
     */
    protected function buildFilter(array $filters, ?string $collection = null): array
    {
        return $this->filterBuilder()->build(
            $filters,
            $collection,
            $collection ? $this->getCachedIndexTypes($collection) : [],
            fn (string $name, array $fields): null => $this->ensureFilterIndexes($name, $fields)
        );
    }
    
    /**
     * Get cached index types for a collection
     */
    protected function getCachedIndexTypes(string $collection): array
    {
        return $this->payloadIndexManager()->getCachedIndexTypes($collection);
    }
    
    /**
     * Clear cached index types for a collection (call after fixing indexes)
     */
    public function clearIndexTypeCache(?string $collection = null): void
    {
        $this->payloadIndexManager()->clearIndexTypeCache($collection);
    }
    
    protected function filterBuilder(): QdrantFilterBuilder
    {
        return $this->filterBuilder ??= new QdrantFilterBuilder();
    }

    protected function payloadIndexManager(): QdrantPayloadIndexManager
    {
        return $this->payloadIndexManager ??= new QdrantPayloadIndexManager($this->client);
    }
    
    /**
     * Ensure payload indexes exist for filter fields
     * This is called automatically before search to prevent "index required" errors
     * 
     * @param string $collection Collection name
     * @param array $filterFields Fields being used in filters
     */
    public function ensureFilterIndexes(string $collection, array $filterFields): void
    {
        $this->payloadIndexManager()->ensureFilterIndexes($collection, $filterFields);
    }
    
    /**
     * Get existing payload indexes for a collection
     * 
     * @param string $collection Collection name
     * @return array List of indexed field names
     */
    public function getExistingIndexes(string $collection): array
    {
        return $this->payloadIndexManager()->getExistingIndexes($collection);
    }
    
    /**
     * Get existing payload indexes with their types
     * 
     * @param string $collection Collection name
     * @return array Map of field name => type
     */
    public function getExistingIndexesWithTypes(string $collection): array
    {
        return $this->payloadIndexManager()->getExistingIndexesWithTypes($collection);
    }
    
    /**
     * Delete a payload index
     * 
     * @param string $collection Collection name
     * @param string $fieldName Field to remove index from
     * @return bool Success
     */
    public function deletePayloadIndex(string $collection, string $fieldName): bool
    {
        return $this->payloadIndexManager()->deletePayloadIndex($collection, $fieldName);
    }
    
    /**
     * Auto-fix index type mismatches
     * Detects when index type doesn't match expected type and recreates the index
     * 
     * @param string $collection Collection name
     * @param array $expectedTypes Map of field name => expected type
     * @return array List of fixed fields
     */
    public function autoFixIndexTypes(string $collection, array $expectedTypes = []): array
    {
        return $this->payloadIndexManager()->autoFixIndexTypes($collection, $expectedTypes);
    }
    
    /**
     * Normalize index type for comparison
     */
    protected function normalizeIndexType(string $type): string
    {
        return $this->payloadIndexManager()->normalizeIndexType($type);
    }
    
    /**
     * Fix all collections - scan and auto-fix index type mismatches
     * 
     * @return array Map of collection => fixed fields
     */
    public function autoFixAllCollections(): array
    {
        return $this->payloadIndexManager()->autoFixAllCollections();
    }
    
    /**
     * Ensure all configured payload indexes exist for a collection
     * Call this for existing collections that may be missing indexes
     * 
     * @param string $collection Collection name
     * @param string|null $modelClass Optional model class for schema detection
     */
    public function ensureAllPayloadIndexes(string $collection, ?string $modelClass = null): void
    {
        $this->payloadIndexManager()->ensureAllPayloadIndexes($collection, $modelClass);
    }
    
    /**
     * Clear the index ensured cache
     * Useful when indexes might have been deleted externally
     */
    public static function clearIndexCache(): void
    {
        QdrantPayloadIndexManager::clearIndexCache();
    }
}
