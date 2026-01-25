<?php

namespace LaravelAIEngine\Services\Vector\Drivers;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class QdrantDriver implements VectorDriverInterface
{
    protected Client $client;
    protected string $host;
    protected ?string $apiKey;
    protected int $timeout;
    
    /**
     * Cache of collections where indexes have been ensured
     * Prevents repeated API calls to check/create indexes
     */
    protected static array $indexEnsuredCollections = [];

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
        } catch (GuzzleException $e) {
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
        // Get base fields from config
        $configFields = config('ai-engine.vector.payload_index_fields', [
            'user_id',
            'tenant_id', 
            'workspace_id',
            'model_id',
            'status',
            'visibility',
            'type',
            'created_at_ts',
            'updated_at_ts',
        ]);
        
        // Detect additional fields from model's belongsTo relationships
        $relationFields = $this->detectBelongsToFields($modelClass);
        
        // Get custom indexes from model if defined
        $customIndexes = $this->getModelCustomIndexes($modelClass);
        
        // Merge all fields (unique)
        $indexableFields = array_unique(array_merge($configFields, $relationFields, $customIndexes));
        
        Log::debug('Payload index fields detected', [
            'collection' => $collection,
            'config_fields' => $configFields,
            'relation_fields' => $relationFields,
            'custom_indexes' => $customIndexes,
            'total_fields' => $indexableFields,
        ]);
        
        // Detect field types from model schema if available
        $fieldTypes = $this->detectFieldTypes($modelClass, $indexableFields);
        
        foreach ($fieldTypes as $fieldName => $fieldType) {
            $this->createPayloadIndex($collection, $fieldName, $fieldType);
        }
    }
    
    /**
     * Get custom indexes defined by model's getQdrantIndexes() method
     */
    protected function getModelCustomIndexes(?string $modelClass): array
    {
        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }
        
        try {
            $instance = new $modelClass();
            
            if (method_exists($instance, 'getQdrantIndexes')) {
                $indexes = $instance->getQdrantIndexes();
                
                Log::debug('Model custom indexes detected', [
                    'model' => $modelClass,
                    'indexes' => $indexes,
                ]);
                
                return is_array($indexes) ? $indexes : [];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to get model custom indexes', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }
        
        return [];
    }
    
    /**
     * Detect foreign key fields from model's belongsTo relationships
     */
    protected function detectBelongsToFields(?string $modelClass): array
    {
        $fields = [];
        
        if (!$modelClass || !class_exists($modelClass)) {
            return $fields;
        }
        
        try {
            $instance = new $modelClass();
            $reflection = new \ReflectionClass($instance);
            
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                // Skip non-model methods
                if ($method->class !== $modelClass && !is_subclass_of($method->class, \Illuminate\Database\Eloquent\Model::class)) {
                    continue;
                }
                
                // Skip methods with parameters
                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }
                
                // Skip common non-relation methods
                $skipMethods = ['getKey', 'getTable', 'getFillable', 'getHidden', 'getCasts', 'toArray', 'toJson'];
                if (in_array($method->getName(), $skipMethods)) {
                    continue;
                }
                
                try {
                    $returnType = $method->getReturnType();
                    if ($returnType) {
                        $typeName = $returnType->getName();
                        if ($typeName === \Illuminate\Database\Eloquent\Relations\BelongsTo::class || 
                            str_ends_with($typeName, 'BelongsTo')) {
                            
                            // Call the method to get the relation
                            $relation = $method->invoke($instance);
                            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                                $foreignKey = $relation->getForeignKeyName();
                                $fields[] = $foreignKey;
                                
                                Log::debug('Detected belongsTo foreign key', [
                                    'model' => $modelClass,
                                    'method' => $method->getName(),
                                    'foreign_key' => $foreignKey,
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip methods that fail
                    continue;
                }
            }
            
            // Also check for vectorParentLookup if defined
            if (method_exists($instance, 'getVectorParentKey')) {
                $parentKey = $instance->getVectorParentKey();
                if ($parentKey) {
                    $fields[] = $parentKey;
                }
            }
            
        } catch (\Exception $e) {
            Log::warning('Failed to detect belongsTo fields', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }
        
        return array_unique($fields);
    }
    
    /**
     * Create a single payload index
     */
    public function createPayloadIndex(string $collection, string $fieldName, string $fieldType): bool
    {
        try {
            $response = $this->client->put("/collections/{$collection}/index", [
                'json' => [
                    'field_name' => $fieldName,
                    'field_schema' => $fieldType,
                ],
            ]);
            
            $statusCode = $response->getStatusCode();
            $body = $response->getBody()->getContents();
            
            Log::info('Created payload index', [
                'collection' => $collection,
                'field' => $fieldName,
                'type' => $fieldType,
                'status' => $statusCode,
            ]);
            
            return $statusCode === 200;
        } catch (GuzzleException $e) {
            // Check if it's "already exists" error (which is OK)
            $errorMessage = $e->getMessage();
            $isAlreadyExists = str_contains($errorMessage, 'already exists') || 
                               str_contains($errorMessage, 'already indexed');
            
            if ($isAlreadyExists) {
                Log::debug('Payload index already exists', [
                    'collection' => $collection,
                    'field' => $fieldName,
                ]);
                return true;
            }
            
            // Log actual failures
            Log::error('Failed to create payload index', [
                'collection' => $collection,
                'field' => $fieldName,
                'type' => $fieldType,
                'error' => $errorMessage,
            ]);
            return false;
        }
    }
    
    /**
     * Detect field types from model's database schema
     */
    protected function detectFieldTypes(?string $modelClass, array $fields): array
    {
        $fieldTypes = [];
        
        if ($modelClass && class_exists($modelClass)) {
            try {
                $instance = new $modelClass();
                $table = $instance->getTable();
                $connection = $instance->getConnection();
                $schemaBuilder = $connection->getSchemaBuilder();
                
                // Get column information from database (compatible with Laravel 9+)
                $columnMap = [];
                
                // Try getColumns() first (Laravel 10+), fallback to getColumnListing + getColumnType
                if (method_exists($schemaBuilder, 'getColumns')) {
                    $columns = $schemaBuilder->getColumns($table);
                    foreach ($columns as $column) {
                        $columnMap[$column['name']] = $column['type_name'] ?? $column['type'] ?? 'string';
                    }
                } else {
                    // Fallback for Laravel 9 and earlier
                    $columnNames = $schemaBuilder->getColumnListing($table);
                    foreach ($columnNames as $columnName) {
                        if (in_array($columnName, $fields)) {
                            try {
                                $columnMap[$columnName] = $connection->getDoctrineColumn($table, $columnName)->getType()->getName();
                            } catch (\Exception $e) {
                                // If Doctrine fails, use guessing
                                $columnMap[$columnName] = 'string';
                            }
                        }
                    }
                }
                
                foreach ($fields as $field) {
                    if (isset($columnMap[$field])) {
                        $fieldTypes[$field] = $this->mapDatabaseTypeToQdrant($columnMap[$field], $field);
                    }
                }
                
                Log::debug('Detected field types from schema', [
                    'model' => $modelClass,
                    'table' => $table,
                    'field_types' => $fieldTypes,
                ]);
                
            } catch (\Exception $e) {
                Log::warning('Failed to detect field types from schema, using defaults', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // For fields not detected, use sensible defaults
        foreach ($fields as $field) {
            if (!isset($fieldTypes[$field])) {
                $fieldTypes[$field] = $this->guessFieldType($field);
            }
        }
        
        return $fieldTypes;
    }
    
    /**
     * Map database column type to Qdrant field schema type
     */
    protected function mapDatabaseTypeToQdrant(string $dbType, string $fieldName = ''): string
    {
        $dbType = strtolower($dbType);
        
        // ID fields: check if DB type is integer-based, use integer for proper numeric filtering
        // Only use keyword for UUID/GUID types
        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
            if (in_array($dbType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int4', 'int8', 'int2'])) {
                return 'integer';
            }
            // UUID/GUID or string IDs use keyword
            return 'keyword';
        }
        
        // Integer types
        if (in_array($dbType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int4', 'int8', 'int2'])) {
            return 'integer';
        }
        
        // Float types
        if (in_array($dbType, ['float', 'double', 'decimal', 'numeric', 'real', 'float4', 'float8'])) {
            return 'float';
        }
        
        // Boolean
        if (in_array($dbType, ['bool', 'boolean'])) {
            return 'bool';
        }
        
        // UUID - treat as keyword (string) in Qdrant
        if (in_array($dbType, ['uuid', 'guid'])) {
            return 'keyword';
        }
        
        // Default to keyword for strings, varchar, text, etc.
        return 'keyword';
    }
    
    /**
     * Guess field type based on field name conventions
     * Note: For ID fields, we default to 'integer' but this can be overridden
     * by detectFieldTypeFromData() which samples actual data
     */
    public function guessFieldType(string $fieldName): string
    {
        // Fields ending with _id are typically integers (but could be UUIDs)
        // Default to integer, but detectFieldTypeFromData() will correct if UUID
        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
            return 'integer';
        }
        
        // Common string/enum fields
        if (in_array($fieldName, ['status', 'type', 'visibility', 'role', 'category', 'slug'])) {
            return 'keyword';
        }
        
        // Common boolean fields
        if (str_starts_with($fieldName, 'is_') || str_starts_with($fieldName, 'has_')) {
            return 'bool';
        }
        
        // Timestamp fields for date range filtering
        if (str_ends_with($fieldName, '_ts') || str_ends_with($fieldName, '_timestamp')) {
            return 'integer';
        }
        
        // Default to keyword
        return 'keyword';
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
        try {
            // Sample a few points to detect the actual data type
            $scroll = $this->scroll($collection, 5);
            
            foreach ($scroll['points'] ?? [] as $point) {
                $value = $point['metadata'][$fieldName] ?? null;
                
                if ($value === null) {
                    continue;
                }
                
                // Check if it's a UUID (string with dashes, 36 chars)
                if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                    return 'keyword'; // UUIDs should be keyword
                }
                
                // Check if it's a ULID (26 chars alphanumeric)
                if (is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value)) {
                    return 'keyword'; // ULIDs should be keyword
                }
                
                // Check if it's an integer
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    return 'integer';
                }
                
                // Check if it's a float
                if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
                    return 'float';
                }
                
                // Check if it's a boolean
                if (is_bool($value)) {
                    return 'bool';
                }
                
                // Default to keyword for strings
                if (is_string($value)) {
                    return 'keyword';
                }
            }
            
            return null; // Couldn't determine
        } catch (\Exception $e) {
            Log::debug('Failed to detect field type from data', [
                'collection' => $collection,
                'field' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
        // Try to detect from actual data first
        $detectedType = $this->detectFieldTypeFromData($collection, $fieldName);
        
        if ($detectedType !== null) {
            return $detectedType;
        }
        
        // Fall back to guessing based on field name
        return $this->guessFieldType($fieldName);
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
        $existingIndexes = $this->getExistingIndexes($collection);
        $missingFields = array_diff($fields, $existingIndexes);
        
        if (empty($missingFields)) {
            return 0;
        }
        
        Log::info('Creating missing indexes', [
            'collection' => $collection,
            'missing' => $missingFields,
        ]);
        
        $created = 0;
        foreach ($missingFields as $field) {
            $fieldType = $this->guessFieldType($field);
            if ($this->createPayloadIndex($collection, $field, $fieldType)) {
                $created++;
            }
        }
        
        return $created;
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
     * Cache for collection index types to avoid repeated API calls
     */
    protected static array $collectionIndexTypes = [];
    
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
        $must = [];
        
        // Get index types for the collection to ensure proper type conversion
        $indexTypes = [];
        if ($collection) {
            $indexTypes = $this->getCachedIndexTypes($collection);
        }

        foreach ($filters as $key => $value) {
            // Skip internal keys that shouldn't be filtered
            if ($key === 'model_class') {
                continue;
            }
            
            // Check if this is a date field that needs timestamp conversion
            $processedFilter = $this->processDateFilter($key, $value, $collection);
            if ($processedFilter !== null) {
                $must[] = $processedFilter;
                continue;
            }
            
            // Check if this is a range filter
            if (is_array($value) && $this->isRangeFilter($value)) {
                $rangeFilter = $this->buildRangeFilter($key, $value, $indexTypes[$key] ?? null);
                if ($rangeFilter) {
                    $must[] = $rangeFilter;
                }
                continue;
            }
            
            // Get the actual index type for this field
            $indexType = $indexTypes[$key] ?? null;
            
            if (is_array($value)) {
                // Convert array values to appropriate types (match any)
                $convertedValues = array_map(fn($v) => $this->convertFilterValue($key, $v, $indexType), $value);
                $must[] = [
                    'key' => $key,
                    'match' => ['any' => $convertedValues],
                ];
            } else {
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $this->convertFilterValue($key, $value, $indexType)],
                ];
            }
        }

        return ['must' => $must];
    }
    
    /**
     * Check if filter value is a range filter (has gte, lte, gt, lt keys)
     */
    protected function isRangeFilter(array $value): bool
    {
        $rangeKeys = ['gte', 'lte', 'gt', 'lt'];
        foreach ($rangeKeys as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Build a range filter for Qdrant
     */
    protected function buildRangeFilter(string $key, array $range, ?string $indexType = null): ?array
    {
        $rangeCondition = [];
        
        if (isset($range['gte'])) {
            $rangeCondition['gte'] = $this->convertRangeValue($range['gte'], $indexType);
        }
        if (isset($range['lte'])) {
            $rangeCondition['lte'] = $this->convertRangeValue($range['lte'], $indexType);
        }
        if (isset($range['gt'])) {
            $rangeCondition['gt'] = $this->convertRangeValue($range['gt'], $indexType);
        }
        if (isset($range['lt'])) {
            $rangeCondition['lt'] = $this->convertRangeValue($range['lt'], $indexType);
        }
        
        if (empty($rangeCondition)) {
            return null;
        }
        
        return [
            'key' => $key,
            'range' => $rangeCondition,
        ];
    }
    
    /**
     * Convert range value to appropriate type
     */
    protected function convertRangeValue($value, ?string $indexType = null)
    {
        if ($indexType === 'integer' || $indexType === 'int') {
            return is_numeric($value) ? (int) $value : $value;
        }
        if ($indexType === 'float') {
            return is_numeric($value) ? (float) $value : $value;
        }
        
        // If numeric, convert to int/float
        if (is_numeric($value)) {
            return str_contains((string)$value, '.') ? (float) $value : (int) $value;
        }
        
        return $value;
    }
    
    /**
     * Process date filter - auto-converts date strings to timestamp filters
     * 
     * Examples:
     * - ['created_at' => ['gte' => '2026-01-01']] → ['created_at_ts' => ['gte' => 1704067200]]
     * - ['issue_date' => ['gte' => '2026-01-01', 'lte' => '2026-01-31']]
     */
    protected function processDateFilter(string $key, $value, ?string $collection = null): ?array
    {
        // Common date field names (without _ts suffix)
        $dateFields = ['created_at', 'updated_at', 'issue_date', 'due_date', 'paid_date', 'sent_date', 'date', 'published_at', 'deleted_at'];
        
        // Check if this is a date field
        if (!in_array($key, $dateFields)) {
            return null;
        }
        
        // If it's a range filter with date strings, convert to timestamp
        if (is_array($value) && $this->isRangeFilter($value)) {
            $tsKey = $key . '_ts';
            $tsRange = [];
            
            foreach (['gte', 'lte', 'gt', 'lt'] as $op) {
                if (isset($value[$op])) {
                    $ts = $this->parseToTimestamp($value[$op]);
                    if ($ts !== null) {
                        // For 'lte' on dates without time, set to end of day
                        if ($op === 'lte' && $this->isDateOnly($value[$op])) {
                            $ts = strtotime($value[$op] . ' 23:59:59');
                        }
                        $tsRange[$op] = $ts;
                    }
                }
            }
            
            if (!empty($tsRange)) {
                // Ensure the timestamp index exists
                if ($collection) {
                    $this->ensureFilterIndexes($collection, [$tsKey]);
                }
                
                return [
                    'key' => $tsKey,
                    'range' => $tsRange,
                ];
            }
        }
        
        // If it's a single date value for exact match, convert to timestamp range for that day
        if (is_string($value) && $this->isDateOnly($value)) {
            $tsKey = $key . '_ts';
            $startTs = strtotime($value . ' 00:00:00');
            $endTs = strtotime($value . ' 23:59:59');
            
            if ($startTs && $endTs && $collection) {
                $this->ensureFilterIndexes($collection, [$tsKey]);
                
                return [
                    'key' => $tsKey,
                    'range' => [
                        'gte' => $startTs,
                        'lte' => $endTs,
                    ],
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Parse various date formats to Unix timestamp
     */
    protected function parseToTimestamp($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        
        if (is_string($value)) {
            $ts = strtotime($value);
            return $ts !== false ? $ts : null;
        }
        
        return null;
    }
    
    /**
     * Check if value is a date-only string (no time component)
     */
    protected function isDateOnly(string $value): bool
    {
        // Matches: 2026-01-01, 01/01/2026, Jan 1 2026, etc.
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ||
               preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) ||
               (strtotime($value) !== false && !preg_match('/\d{2}:\d{2}/', $value));
    }
    
    /**
     * Get cached index types for a collection
     */
    protected function getCachedIndexTypes(string $collection): array
    {
        if (!isset(static::$collectionIndexTypes[$collection])) {
            static::$collectionIndexTypes[$collection] = $this->getExistingIndexesWithTypes($collection);
        }
        return static::$collectionIndexTypes[$collection];
    }
    
    /**
     * Clear cached index types for a collection (call after fixing indexes)
     */
    public function clearIndexTypeCache(?string $collection = null): void
    {
        if ($collection) {
            unset(static::$collectionIndexTypes[$collection]);
        } else {
            static::$collectionIndexTypes = [];
        }
    }
    
    /**
     * Convert filter value to match the actual index type in Qdrant
     * This ensures "10" becomes 10 for integer indexes, etc.
     * 
     * @param string $key Field name
     * @param mixed $value Value to convert
     * @param string|null $indexType Actual index type from Qdrant (integer, keyword, bool, float)
     */
    protected function convertFilterValue(string $key, $value, ?string $indexType = null)
    {
        // If we know the actual index type, convert to match it
        if ($indexType !== null) {
            $normalizedType = $this->normalizeIndexType($indexType);
            
            switch ($normalizedType) {
                case 'integer':
                    if (is_numeric($value)) {
                        return (int) $value;
                    }
                    // If value is not numeric but index is integer, log warning
                    Log::debug('Filter value is not numeric for integer index', [
                        'field' => $key,
                        'value' => $value,
                        'index_type' => $indexType,
                    ]);
                    return $value;
                    
                case 'float':
                    if (is_numeric($value)) {
                        return (float) $value;
                    }
                    return $value;
                    
                case 'bool':
                    if (is_bool($value)) {
                        return $value;
                    }
                    // Convert common boolean representations
                    if (is_string($value)) {
                        $lower = strtolower($value);
                        if (in_array($lower, ['true', '1', 'yes', 'on'])) {
                            return true;
                        }
                        if (in_array($lower, ['false', '0', 'no', 'off'])) {
                            return false;
                        }
                    }
                    return (bool) $value;
                    
                case 'keyword':
                    // Keywords should be strings
                    return (string) $value;
            }
        }
        
        // Fallback: guess based on field name conventions
        // For ID fields, convert to integer if numeric
        if (str_ends_with($key, '_id') || $key === 'id') {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        
        // For boolean fields
        if (str_starts_with($key, 'is_') || str_starts_with($key, 'has_')) {
            return (bool) $value;
        }
        
        return $value;
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
        // Check if we've already ensured indexes for this collection
        $cacheKey = $collection . ':' . implode(',', $filterFields);
        if (isset(static::$indexEnsuredCollections[$cacheKey])) {
            return;
        }
        
        try {
            // Get existing indexes for this collection
            $existingIndexes = $this->getExistingIndexes($collection);
            
            // Find missing indexes
            $missingFields = array_diff($filterFields, $existingIndexes);
            
            if (!empty($missingFields)) {
                Log::info('Auto-creating missing payload indexes', [
                    'collection' => $collection,
                    'missing_fields' => $missingFields,
                    'existing_indexes' => $existingIndexes,
                ]);
                
                foreach ($missingFields as $field) {
                    $fieldType = $this->guessFieldType($field);
                    $this->createPayloadIndex($collection, $field, $fieldType);
                }
            }
            
            // Mark as ensured
            static::$indexEnsuredCollections[$cacheKey] = true;
            
        } catch (\Exception $e) {
            Log::warning('Failed to ensure filter indexes', [
                'collection' => $collection,
                'fields' => $filterFields,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Get existing payload indexes for a collection
     * 
     * @param string $collection Collection name
     * @return array List of indexed field names
     */
    public function getExistingIndexes(string $collection): array
    {
        try {
            $response = $this->client->get("/collections/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            $payloadSchema = $data['result']['payload_schema'] ?? [];
            
            // Extract field names that have indexes
            $indexedFields = [];
            foreach ($payloadSchema as $fieldName => $schema) {
                // Check if field has an index (data_type indicates indexed field)
                if (isset($schema['data_type']) || isset($schema['params'])) {
                    $indexedFields[] = $fieldName;
                }
            }
            
            return $indexedFields;
        } catch (GuzzleException $e) {
            Log::warning('Failed to get existing indexes', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Get existing payload indexes with their types
     * 
     * @param string $collection Collection name
     * @return array Map of field name => type
     */
    public function getExistingIndexesWithTypes(string $collection): array
    {
        try {
            $response = $this->client->get("/collections/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            
            $payloadSchema = $data['result']['payload_schema'] ?? [];
            
            $indexedFields = [];
            foreach ($payloadSchema as $fieldName => $schema) {
                if (isset($schema['data_type'])) {
                    $indexedFields[$fieldName] = strtolower($schema['data_type']);
                }
            }
            
            return $indexedFields;
        } catch (GuzzleException $e) {
            Log::warning('Failed to get existing indexes with types', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
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
        try {
            $response = $this->client->delete("/collections/{$collection}/index/{$fieldName}");
            
            Log::info('Deleted payload index', [
                'collection' => $collection,
                'field' => $fieldName,
            ]);
            
            return $response->getStatusCode() === 200;
        } catch (GuzzleException $e) {
            Log::warning('Failed to delete payload index', [
                'collection' => $collection,
                'field' => $fieldName,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
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
        $fixed = [];
        
        try {
            // Get current index types
            $currentTypes = $this->getExistingIndexesWithTypes($collection);
            
            if (empty($currentTypes)) {
                return $fixed;
            }
            
            // If no expected types provided, detect from actual data or guess
            if (empty($expectedTypes)) {
                foreach ($currentTypes as $field => $type) {
                    // Use getFieldType which tries data detection first, then falls back to guessing
                    $expectedTypes[$field] = $this->getFieldType($collection, $field);
                }
            }
            
            // Find mismatches
            foreach ($expectedTypes as $field => $expectedType) {
                $currentType = $currentTypes[$field] ?? null;
                
                if ($currentType === null) {
                    // Index doesn't exist, create it
                    Log::info('Creating missing index', [
                        'collection' => $collection,
                        'field' => $field,
                        'type' => $expectedType,
                    ]);
                    $this->createPayloadIndex($collection, $field, $expectedType);
                    $fixed[] = $field;
                    continue;
                }
                
                // Normalize types for comparison
                $normalizedCurrent = $this->normalizeIndexType($currentType);
                $normalizedExpected = $this->normalizeIndexType($expectedType);
                
                if ($normalizedCurrent !== $normalizedExpected) {
                    Log::info('Index type mismatch detected, recreating', [
                        'collection' => $collection,
                        'field' => $field,
                        'current_type' => $currentType,
                        'expected_type' => $expectedType,
                    ]);
                    
                    // Delete old index and create new one with correct type
                    $this->deletePayloadIndex($collection, $field);
                    usleep(100000); // 100ms delay to ensure deletion completes
                    $this->createPayloadIndex($collection, $field, $expectedType);
                    $fixed[] = $field;
                }
            }
            
            if (!empty($fixed)) {
                Log::info('Auto-fixed index types', [
                    'collection' => $collection,
                    'fixed_fields' => $fixed,
                ]);
                
                // Clear the index ensured cache for this collection
                foreach (static::$indexEnsuredCollections as $key => $value) {
                    if (str_starts_with($key, $collection . ':') || str_starts_with($key, 'all:' . $collection)) {
                        unset(static::$indexEnsuredCollections[$key]);
                    }
                }
                
                // Clear the index type cache so buildFilter gets fresh types
                $this->clearIndexTypeCache($collection);
            }
            
            return $fixed;
        } catch (\Exception $e) {
            Log::error('Failed to auto-fix index types', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return $fixed;
        }
    }
    
    /**
     * Normalize index type for comparison
     */
    protected function normalizeIndexType(string $type): string
    {
        $type = strtolower(trim($type));
        
        // Map variations to canonical types
        $typeMap = [
            'int' => 'integer',
            'int64' => 'integer',
            'int32' => 'integer',
            'float64' => 'float',
            'float32' => 'float',
            'str' => 'keyword',
            'string' => 'keyword',
            'text' => 'keyword',
            'boolean' => 'bool',
        ];
        
        return $typeMap[$type] ?? $type;
    }
    
    /**
     * Fix all collections - scan and auto-fix index type mismatches
     * 
     * @return array Map of collection => fixed fields
     */
    public function autoFixAllCollections(): array
    {
        $results = [];
        
        try {
            $response = $this->client->get('/collections');
            $data = json_decode($response->getBody()->getContents(), true);
            
            foreach ($data['result']['collections'] ?? [] as $col) {
                $name = $col['name'];
                $fixed = $this->autoFixIndexTypes($name);
                if (!empty($fixed)) {
                    $results[$name] = $fixed;
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            Log::error('Failed to auto-fix all collections', [
                'error' => $e->getMessage(),
            ]);
            return $results;
        }
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
        // Check cache first - avoid repeated expensive operations
        $cacheKey = 'all:' . $collection;
        if (isset(static::$indexEnsuredCollections[$cacheKey])) {
            return;
        }
        
        // Get existing indexes
        $existingIndexes = $this->getExistingIndexes($collection);
        
        // Get configured fields
        $configFields = config('ai-engine.vector.payload_index_fields', [
            'user_id',
            'tenant_id', 
            'workspace_id',
            'model_id',
            'status',
            'visibility',
            'type',
        ]);
        
        // Detect additional fields from model (only if not too expensive)
        $relationFields = [];
        if ($modelClass) {
            $relationFields = $this->detectBelongsToFields($modelClass);
        }
        
        // Merge all fields
        $allFields = array_unique(array_merge($configFields, $relationFields));
        
        // Find missing indexes
        $missingFields = array_diff($allFields, $existingIndexes);
        
        // Mark as ensured (even if no missing fields, to prevent repeated checks)
        static::$indexEnsuredCollections[$cacheKey] = true;
        
        if (empty($missingFields)) {
            Log::debug('All payload indexes already exist', [
                'collection' => $collection,
                'existing_indexes' => $existingIndexes,
            ]);
            return;
        }
        
        Log::info('Creating missing payload indexes for existing collection', [
            'collection' => $collection,
            'missing_fields' => $missingFields,
            'existing_indexes' => $existingIndexes,
        ]);
        
        // Detect field types
        $fieldTypes = $this->detectFieldTypes($modelClass, $missingFields);
        
        foreach ($fieldTypes as $fieldName => $fieldType) {
            $this->createPayloadIndex($collection, $fieldName, $fieldType);
        }
    }
    
    /**
     * Clear the index ensured cache
     * Useful when indexes might have been deleted externally
     */
    public static function clearIndexCache(): void
    {
        static::$indexEnsuredCollections = [];
    }
}
