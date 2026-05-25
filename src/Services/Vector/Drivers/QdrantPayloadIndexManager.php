<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Vector\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Throwable;

class QdrantPayloadIndexManager
{
    protected static array $indexEnsuredCollections = [];

    protected static array $collectionIndexTypes = [];

    public function __construct(
        protected Client $client
    ) {}

    public function createPayloadIndexes(string $collection, ?string $modelClass = null): void
    {
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

        $relationFields = $this->detectBelongsToFields($modelClass);
        $customIndexes = $this->getModelCustomIndexes($modelClass);
        $indexableFields = array_unique(array_merge($configFields, $relationFields, $customIndexes));

        Log::debug('Payload index fields detected', [
            'collection' => $collection,
            'config_fields' => $configFields,
            'relation_fields' => $relationFields,
            'custom_indexes' => $customIndexes,
            'total_fields' => $indexableFields,
        ]);

        foreach ($this->detectFieldTypes($modelClass, $indexableFields) as $fieldName => $fieldType) {
            $this->createPayloadIndex($collection, $fieldName, $fieldType);
        }
    }

    public function getModelCustomIndexes(?string $modelClass): array
    {
        if (!$modelClass || !class_exists($modelClass)) {
            return [];
        }

        try {
            if (!method_exists($modelClass, 'getQdrantIndexes')) {
                return [];
            }

            $reflection = new \ReflectionMethod($modelClass, 'getQdrantIndexes');
            $vectorizableMethodFile = (new \ReflectionMethod(\LaravelAIEngine\Traits\Vectorizable::class, 'getQdrantIndexes'))->getFileName();

            if ($reflection->getFileName() === $vectorizableMethodFile) {
                return [];
            }

            $instance = new $modelClass();
            $indexes = $reflection->isStatic()
                ? $modelClass::getQdrantIndexes()
                : $instance->getQdrantIndexes();

            Log::debug('Model custom indexes detected', [
                'model' => $modelClass,
                'indexes' => $indexes,
            ]);

            return is_array($indexes) ? $indexes : [];
        } catch (Throwable $e) {
            Log::warning('Failed to get model custom indexes', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    public function detectBelongsToFields(?string $modelClass): array
    {
        $fields = [];

        if (!$modelClass || !class_exists($modelClass)) {
            return $fields;
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            $defaults = $reflection->getDefaultProperties();
            $fillable = $defaults['fillable'] ?? [];

            foreach ((array) $fillable as $fillableField) {
                if (is_string($fillableField) && str_ends_with($fillableField, '_id')) {
                    $fields[] = $fillableField;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed to detect belongsTo fields', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
        }

        return array_unique($fields);
    }

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

            Log::info('Created payload index', [
                'collection' => $collection,
                'field' => $fieldName,
                'type' => $fieldType,
                'status' => $statusCode,
            ]);

            return $statusCode === 200;
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
            $isAlreadyExists = str_contains($errorMessage, 'already exists')
                || str_contains($errorMessage, 'already indexed');

            if ($isAlreadyExists) {
                Log::debug('Payload index already exists', [
                    'collection' => $collection,
                    'field' => $fieldName,
                ]);

                return true;
            }

            Log::error('Failed to create payload index', [
                'collection' => $collection,
                'field' => $fieldName,
                'type' => $fieldType,
                'error' => $errorMessage,
            ]);

            return false;
        }
    }

    public function detectFieldTypes(?string $modelClass, array $fields): array
    {
        $fieldTypes = [];

        foreach ($fields as $field) {
            $fieldTypes[$field] = $this->guessFieldType($field);
        }

        return $fieldTypes;
    }

    public function mapDatabaseTypeToQdrant(string $dbType, string $fieldName = ''): string
    {
        $dbType = strtolower($dbType);

        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
            if (in_array($dbType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int4', 'int8', 'int2'], true)) {
                return 'integer';
            }

            return 'keyword';
        }

        if (in_array($dbType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint', 'int4', 'int8', 'int2'], true)) {
            return 'integer';
        }

        if (in_array($dbType, ['float', 'double', 'decimal', 'numeric', 'real', 'float4', 'float8'], true)) {
            return 'float';
        }

        if (in_array($dbType, ['bool', 'boolean'], true)) {
            return 'bool';
        }

        if (in_array($dbType, ['uuid', 'guid'], true)) {
            return 'keyword';
        }

        return 'keyword';
    }

    public function guessFieldType(string $fieldName): string
    {
        if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
            return 'integer';
        }

        if (in_array($fieldName, ['status', 'type', 'visibility', 'role', 'category', 'slug'], true)) {
            return 'keyword';
        }

        if (str_starts_with($fieldName, 'is_') || str_starts_with($fieldName, 'has_')) {
            return 'bool';
        }

        if (str_ends_with($fieldName, '_ts') || str_ends_with($fieldName, '_timestamp')) {
            return 'integer';
        }

        return 'keyword';
    }

    public function detectFieldTypeFromData(string $collection, string $fieldName): ?string
    {
        try {
            $response = $this->client->post("/collections/{$collection}/points/scroll", [
                'json' => [
                    'limit' => 5,
                    'with_payload' => true,
                    'with_vector' => false,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            foreach ($data['result']['points'] ?? [] as $point) {
                $value = $point['payload'][$fieldName] ?? null;

                if ($value === null) {
                    continue;
                }

                if (is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
                    return 'keyword';
                }

                if (is_string($value) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $value)) {
                    return 'keyword';
                }

                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    return 'integer';
                }

                if (is_float($value) || (is_string($value) && is_numeric($value) && str_contains($value, '.'))) {
                    return 'float';
                }

                if (is_bool($value)) {
                    return 'bool';
                }

                if (is_string($value)) {
                    return 'keyword';
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::debug('Failed to detect field type from data', [
                'collection' => $collection,
                'field' => $fieldName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function getFieldType(string $collection, string $fieldName): string
    {
        $detectedType = $this->detectFieldTypeFromData($collection, $fieldName);

        if ($detectedType !== null) {
            return $detectedType;
        }

        return $this->guessFieldType($fieldName);
    }

    public function ensureRequiredIndexes(string $collection, array $fields, ?string $modelClass = null): int
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

    public function getCachedIndexTypes(string $collection): array
    {
        if (!isset(static::$collectionIndexTypes[$collection])) {
            static::$collectionIndexTypes[$collection] = $this->getExistingIndexesWithTypes($collection);
        }

        return static::$collectionIndexTypes[$collection];
    }

    public function clearIndexTypeCache(?string $collection = null): void
    {
        if ($collection) {
            unset(static::$collectionIndexTypes[$collection]);

            return;
        }

        static::$collectionIndexTypes = [];
    }

    public function ensureFilterIndexes(string $collection, array $filterFields): void
    {
        $cacheKey = $collection . ':' . implode(',', $filterFields);
        if (isset(static::$indexEnsuredCollections[$cacheKey])) {
            return;
        }

        try {
            $existingIndexes = $this->getExistingIndexes($collection);
            $missingFields = array_diff($filterFields, $existingIndexes);

            if (!empty($missingFields)) {
                Log::info('Auto-creating missing payload indexes', [
                    'collection' => $collection,
                    'missing_fields' => $missingFields,
                    'existing_indexes' => $existingIndexes,
                ]);

                foreach ($missingFields as $field) {
                    $this->createPayloadIndex($collection, $field, $this->guessFieldType($field));
                }
            }

            static::$indexEnsuredCollections[$cacheKey] = true;
        } catch (\Exception $e) {
            Log::warning('Failed to ensure filter indexes', [
                'collection' => $collection,
                'fields' => $filterFields,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getExistingIndexes(string $collection): array
    {
        try {
            $response = $this->client->get("/collections/{$collection}");
            $data = json_decode($response->getBody()->getContents(), true);
            $payloadSchema = $data['result']['payload_schema'] ?? [];

            $indexedFields = [];
            foreach ($payloadSchema as $fieldName => $schema) {
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

    public function autoFixIndexTypes(string $collection, array $expectedTypes = []): array
    {
        $fixed = [];

        try {
            $currentTypes = $this->getExistingIndexesWithTypes($collection);

            if (empty($currentTypes)) {
                return $fixed;
            }

            if (empty($expectedTypes)) {
                foreach ($currentTypes as $field => $type) {
                    $expectedTypes[$field] = $this->getFieldType($collection, $field);
                }
            }

            foreach ($expectedTypes as $field => $expectedType) {
                $currentType = $currentTypes[$field] ?? null;

                if ($currentType === null) {
                    Log::info('Creating missing index', [
                        'collection' => $collection,
                        'field' => $field,
                        'type' => $expectedType,
                    ]);
                    $this->createPayloadIndex($collection, $field, $expectedType);
                    $fixed[] = $field;

                    continue;
                }

                $normalizedCurrent = $this->normalizeIndexType($currentType);
                $normalizedExpected = $this->normalizeIndexType($expectedType);

                if ($normalizedCurrent !== $normalizedExpected) {
                    Log::info('Index type mismatch detected, recreating', [
                        'collection' => $collection,
                        'field' => $field,
                        'current_type' => $currentType,
                        'expected_type' => $expectedType,
                    ]);

                    $this->deletePayloadIndex($collection, $field);
                    usleep(100000);
                    $this->createPayloadIndex($collection, $field, $expectedType);
                    $fixed[] = $field;
                }
            }

            if (!empty($fixed)) {
                Log::info('Auto-fixed index types', [
                    'collection' => $collection,
                    'fixed_fields' => $fixed,
                ]);

                $this->clearEnsuredCacheForCollection($collection);
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

    public function normalizeIndexType(string $type): string
    {
        $type = strtolower(trim($type));

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

    public function ensureAllPayloadIndexes(string $collection, ?string $modelClass = null): void
    {
        $cacheKey = 'all:' . $collection;
        if (isset(static::$indexEnsuredCollections[$cacheKey])) {
            return;
        }

        $existingIndexes = $this->getExistingIndexes($collection);
        $configFields = config('ai-engine.vector.payload_index_fields', [
            'user_id',
            'tenant_id',
            'workspace_id',
            'model_id',
            'status',
            'visibility',
            'type',
        ]);

        $relationFields = $modelClass ? $this->detectBelongsToFields($modelClass) : [];
        $allFields = array_unique(array_merge($configFields, $relationFields));
        $missingFields = array_diff($allFields, $existingIndexes);

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

        foreach ($this->detectFieldTypes($modelClass, $missingFields) as $fieldName => $fieldType) {
            $this->createPayloadIndex($collection, $fieldName, $fieldType);
        }
    }

    public static function clearIndexCache(): void
    {
        static::$indexEnsuredCollections = [];
    }

    private function clearEnsuredCacheForCollection(string $collection): void
    {
        foreach (static::$indexEnsuredCollections as $key => $value) {
            if (str_starts_with($key, $collection . ':') || str_starts_with($key, 'all:' . $collection)) {
                unset(static::$indexEnsuredCollections[$key]);
            }
        }
    }
}
