<?php

namespace LaravelAIEngine\Services\Vector\Drivers;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

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
        
        // ID fields should always be keyword for filtering, even if they're integers in DB
        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
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
     */
    protected function guessFieldType(string $fieldName): string
    {
        // Fields ending with _id are typically integers or UUIDs
        // We default to keyword to support both
        if (str_ends_with($fieldName, '_id')) {
            return 'keyword'; // Safe default that works for both int and UUID
        }
        
        // Common string/enum fields
        if (in_array($fieldName, ['status', 'type', 'visibility', 'role', 'category', 'slug'])) {
            return 'keyword';
        }
        
        // Common boolean fields
        if (str_starts_with($fieldName, 'is_') || str_starts_with($fieldName, 'has_')) {
            return 'bool';
        }
        
        // Default to keyword
        return 'keyword';
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
        try {
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
                $body['filter'] = $this->buildFilter($filters);
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
                $body['filter'] = $this->buildFilter($filters);
            }

            $response = $this->client->post("/collections/{$collection}/points/count", [
                'json' => $body,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['result']['count'] ?? 0;
        } catch (GuzzleException $e) {
            Log::error('Qdrant count failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array
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
     */
    protected function buildFilter(array $filters): array
    {
        $must = [];

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                $must[] = [
                    'key' => $key,
                    'match' => ['any' => $value],
                ];
            } else {
                $must[] = [
                    'key' => $key,
                    'match' => ['value' => $value],
                ];
            }
        }

        return ['must' => $must];
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
