<?php

declare(strict_types=1);

namespace LaravelAIEngine\Traits;

use LaravelAIEngine\Traits\Concerns\HasVectorContent;
use LaravelAIEngine\Traits\Concerns\HasVectorRAGMethods;
use LaravelAIEngine\Traits\Concerns\HasVectorSearchDocuments;

/**
 * Vectorizable Trait
 *
 * All-in-one trait for vector search, RAG, and AI chat capabilities.
 * Combines vector search, RAG, and AI chat helpers.
 *
 * Optional but recommended to override:
 * - toSearchDocument(): Returns the canonical searchable document
 * - toGraphObject(): Returns a sanitized object payload for retrieval responses
 * - toRAGSummary(): Returns compact list/summary text
 * - toRAGDetail(): Returns detailed human-readable RAG content
 * - toRAGListPreview(): Returns compact list preview text
 * - shouldBeIndexed(): Whether this record should be indexed
 * - getQdrantIndexes(): Custom Qdrant indexes for filtering
 *
 * Usage:
 * ```php
 * use LaravelAIEngine\Traits\Vectorizable;
 * class Document extends Model
 * {
 *     use Vectorizable;
 *
 *     public function toSearchDocument(): SearchDocument
 *     {
 *         return app(SearchDocumentBuilder::class)->build($this);
 *     }
 * }
 * ```
 */
trait Vectorizable
{
    use HasVectorContent;
    use HasVectorSearchDocuments;
    use HasVectorRAGMethods;

    /**
     * Tracks whether we are currently inside an artisan-triggered indexing
     * flow (e.g. `ai:vector-index`). When false, autoDetectVectorizableFields()
     * will NOT make an AI API call — it falls back to $fillable-only heuristics
     * so that ordinary model saves in production never trigger unexpected AI calls.
     *
     * Set to true only via Vectorizable::setIndexingContext(true) inside
     * artisan commands or dedicated indexing jobs.
     */
    protected static bool $indexingContext = false;

    /**
     * Enable or disable the indexing context flag.
     *
     * Call Vectorizable::setIndexingContext(true) at the start of any artisan
     * indexing command, and Vectorizable::setIndexingContext(false) when done
     * (or just let it fall out of scope — it resets per-process).
     */
    public static function setIndexingContext(bool $value): void
    {
        static::$indexingContext = $value;
    }

    /**
     * Return the current indexing-context flag value.
     */
    public static function isInIndexingContext(): bool
    {
        return static::$indexingContext;
    }

    /**
     * Boot the Vectorizable trait for a model.
     * This automatically registers observers.
     */
    public static function bootVectorizable(): void
    {
        // Register VectorIndexObserver if auto_index is enabled
        if (config('ai-engine.vector.auto_index', false)) {
            static::observe(\LaravelAIEngine\Observers\VectorIndexObserver::class);
        }

        // Register ContextLimitationObserver if auto_update_limitations is enabled
        if (config('ai-engine.rag.auto_update_limitations', true)) {
            static::observe(\LaravelAIEngine\Observers\ContextLimitationObserver::class);
        }
    }

    /**
     * Define which fields should be vectorized
     * Override this in your model
     */
    protected array $vectorizable = [];

    /**
     * Define which relationships to include in vector content
     * Override this in your model
     *
     * Example: protected array $vectorRelationships = ['author', 'tags', 'comments'];
     */
    protected array $vectorRelationships = [];

    /**
     * Maximum relationship depth to traverse
     * Override this in your model
     */
    protected int $maxRelationshipDepth = 1;

    /**
     * RAG priority (0-100, higher = searched first)
     */
    protected int $ragPriority = 50;

    // Parent lookup is auto-detected from BelongsTo relationships.
    // The system will automatically find parent models when searching
    // by email addresses or other identifiers found in the query.

    /**
     * Get custom Qdrant indexes for this model
     * 
     * Override this method to define which metadata fields should have
     * indexes in Qdrant for efficient filtering.
     * 
     * @return array<string, string> Field name => index type ('keyword', 'integer', 'float', 'bool')
     */
    public function getQdrantIndexes(): array
    {
        // Default indexes based on common metadata fields
        $indexes = [];
        
        // Add indexes for fields that exist in metadata
        $metadata = $this->getVectorMetadata();
        
        if (isset($metadata['user_id'])) {
            $indexes['user_id'] = is_numeric($metadata['user_id']) ? 'integer' : 'keyword';
        }
        
        if (isset($metadata['tenant_id'])) {
            $indexes['tenant_id'] = is_numeric($metadata['tenant_id']) ? 'integer' : 'keyword';
        }
        
        if (isset($metadata['workspace_id'])) {
            $indexes['workspace_id'] = is_numeric($metadata['workspace_id']) ? 'integer' : 'keyword';
        }
        
        if (isset($metadata['status'])) {
            $indexes['status'] = 'keyword';
        }
        
        if (isset($metadata['type'])) {
            $indexes['type'] = is_numeric($metadata['type']) ? 'integer' : 'keyword';
        }
        
        if (isset($metadata['category_id'])) {
            $indexes['category_id'] = 'integer';
        }
        
        if (isset($metadata['is_public'])) {
            $indexes['is_public'] = 'bool';
        }
        
        return $indexes;
    }

    /**
     * Get vector content with relationships included
     *
     * @param array|null $relationships Relationships to include (null = use $vectorRelationships)
     * @return string
     */
    public function getVectorContentWithRelationships(?array $relationships = null): string
    {
        $relationships = $relationships ?? $this->vectorRelationships;

        if (empty($relationships)) {
            return $this->getVectorContent();
        }

        // Load relationships if not already loaded
        $this->loadMissing($relationships);

        $content = [$this->getVectorContent()];

        foreach ($relationships as $relation) {
            if ($this->relationLoaded($relation)) {
                $related = $this->$relation;

                if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                    foreach ($related as $item) {
                        if (method_exists($item, 'getVectorContent')) {
                            $content[] = $item->getVectorContent();
                        } elseif (is_string($item)) {
                            $content[] = $item;
                        }
                    }
                } elseif ($related && method_exists($related, 'getVectorContent')) {
                    $content[] = $related->getVectorContent();
                } elseif ($related && is_string($related)) {
                    $content[] = $related;
                }
            }
        }

        return implode("\n\n---\n\n", array_filter($content));
    }

    /**
     * Get all relationships to index (respects depth)
     *
     * @param int|null $depth Maximum depth to traverse
     * @return array
     */
    public function getIndexableRelationships(?int $depth = null): array
    {
        $depth = $depth ?? $this->maxRelationshipDepth;

        if ($depth === 0 || empty($this->vectorRelationships)) {
            return [];
        }

        if ($depth === 1) {
            // Simple case: just return direct relationships
            return $this->vectorRelationships;
        }

        // Nested case: traverse relationships recursively
        return $this->traverseNestedRelationships($this->vectorRelationships, $depth);
    }

    /**
     * Traverse nested relationships up to specified depth
     *
     * @param array $relationships
     * @param int $maxDepth
     * @param int $currentDepth
     * @param string $prefix
     * @return array
     */
    protected function traverseNestedRelationships(
        array $relationships,
        int $maxDepth,
        int $currentDepth = 1,
        string $prefix = ''
    ): array {
        $result = [];

        foreach ($relationships as $relation) {
            // Add the current relationship
            $fullRelation = $prefix ? "{$prefix}.{$relation}" : $relation;
            $result[] = $fullRelation;

            // If we haven't reached max depth, traverse deeper
            if ($currentDepth < $maxDepth) {
                try {
                    // Load the relationship to inspect it
                    $this->loadMissing($relation);

                    if ($this->relationLoaded($relation)) {
                        $related = $this->$relation;

                        // Handle collections
                        if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                            $related = $related->first();
                        }

                        // Check if related model has vectorRelationships
                        if ($related && is_object($related)) {
                            $nestedRelations = $this->getNestedVectorRelationships($related);

                            if (!empty($nestedRelations)) {
                                // Recursively traverse nested relationships
                                $nestedResults = $this->traverseNestedRelationshipsForModel(
                                    $related,
                                    $nestedRelations,
                                    $maxDepth,
                                    $currentDepth + 1,
                                    $fullRelation
                                );

                                $result = array_merge($result, $nestedResults);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Skip relationships that fail to load
                    \Log::debug('Failed to traverse nested relationship', [
                        'model' => get_class($this),
                        'relation' => $fullRelation,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Traverse nested relationships for a related model
     *
     * @param mixed $model
     * @param array $relationships
     * @param int $maxDepth
     * @param int $currentDepth
     * @param string $prefix
     * @return array
     */
    protected function traverseNestedRelationshipsForModel(
        $model,
        array $relationships,
        int $maxDepth,
        int $currentDepth,
        string $prefix
    ): array {
        $result = [];

        foreach ($relationships as $relation) {
            $fullRelation = "{$prefix}.{$relation}";
            $result[] = $fullRelation;

            // Continue traversing if not at max depth
            if ($currentDepth < $maxDepth) {
                try {
                    $model->loadMissing($relation);

                    if ($model->relationLoaded($relation)) {
                        $related = $model->$relation;

                        if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                            $related = $related->first();
                        }

                        if ($related && is_object($related)) {
                            $nestedRelations = $this->getNestedVectorRelationships($related);

                            if (!empty($nestedRelations)) {
                                $nestedResults = $this->traverseNestedRelationshipsForModel(
                                    $related,
                                    $nestedRelations,
                                    $maxDepth,
                                    $currentDepth + 1,
                                    $fullRelation
                                );

                                $result = array_merge($result, $nestedResults);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    \Log::debug('Failed to traverse nested relationship for model', [
                        'model' => get_class($model),
                        'relation' => $fullRelation,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Get vector relationships from a related model
     *
     * @param mixed $model
     * @return array
     */
    protected function getNestedVectorRelationships($model): array
    {
        if (!is_object($model)) {
            return [];
        }

        // Check if model has vectorRelationships property
        if (property_exists($model, 'vectorRelationships')) {
            return $model->vectorRelationships ?? [];
        }

        // Check if it's a public property
        if (isset($model->vectorRelationships)) {
            return $model->vectorRelationships;
        }

        return [];
    }

    /**
     * Auto-detect parent lookup from BelongsTo relationships
     * 
     * Scans the model for BelongsTo relationships and returns lookup config
     * for relationships where the parent model uses Vectorizable trait.
     * 
     * @return array
     */
    public function getVectorParentLookup(): array
    {
        // Check for manual override first
        if (property_exists($this, 'vectorParentLookup') && !empty($this->vectorParentLookup)) {
            return $this->vectorParentLookup;
        }

        // Auto-detect from BelongsTo relationships
        return $this->autoDetectParentLookup();
    }

    /**
     * Auto-detect parent lookup configuration from model relationships
     * 
     * @return array
     */
    protected function autoDetectParentLookup(): array
    {
        $lookups = [];

        // Common relationship method names that are likely BelongsTo
        $commonBelongsToNames = [
            'mailbox', 'email', 'user', 'owner', 'parent', 'category', 
            'author', 'creator', 'company', 'organization', 'tenant',
            'workspace', 'team', 'department', 'project', 'account'
        ];

        try {
            $reflection = new \ReflectionClass($this);

            foreach ($commonBelongsToNames as $methodName) {
                if (!$reflection->hasMethod($methodName)) {
                    continue;
                }

                $method = $reflection->getMethod($methodName);
                
                // Skip if not public or has parameters
                if (!$method->isPublic() || $method->getNumberOfParameters() > 0) {
                    continue;
                }

                try {
                    $result = $method->invoke($this);

                    // Check if it's a BelongsTo relationship
                    if ($result instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                        $relatedModel = $result->getRelated();
                        $foreignKey = $result->getForeignKeyName();

                        // Check if related model uses Vectorizable
                        if (in_array(Vectorizable::class, class_uses_recursive($relatedModel))) {
                            // Get searchable fields from related model
                            $lookupFields = $this->getSearchableFieldsFromModel($relatedModel);

                            if (!empty($lookupFields)) {
                                $lookups[] = [
                                    'model' => get_class($relatedModel),
                                    'parent_key' => $foreignKey,
                                    'lookup_fields' => $lookupFields,
                                    'relationship' => $methodName,
                                ];
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // Skip methods that throw exceptions
                    continue;
                }
            }
        } catch (\Throwable $e) {
            \Log::debug('Failed to auto-detect parent lookup', [
                'model' => get_class($this),
                'error' => $e->getMessage(),
            ]);
        }

        // Return first lookup (most common case is single parent)
        return $lookups[0] ?? [];
    }

    /**
     * Get searchable fields from a model (email, name, title, etc.)
     * 
     * @param object $model
     * @return array
     */
    protected function getSearchableFieldsFromModel(object $model): array
    {
        $searchableFields = [];
        $commonFields = ['email', 'name', 'title', 'username', 'slug', 'code', 'identifier'];

        try {
            // Get fillable fields
            $fillable = $model->getFillable();

            foreach ($commonFields as $field) {
                if (in_array($field, $fillable)) {
                    $searchableFields[] = $field;
                }
            }

            // Also check if model has vectorizable fields
            if (property_exists($model, 'vectorizable') && !empty($model->vectorizable)) {
                foreach ($model->vectorizable as $field) {
                    if (!in_array($field, $searchableFields)) {
                        $searchableFields[] = $field;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to common fields
            $searchableFields = ['email', 'name'];
        }

        return $searchableFields;
    }

    /**
     * Check if this model has parent lookup configured (manual or auto-detected)
     * 
     * @return bool
     */
    public function hasVectorParentLookup(): bool
    {
        $lookup = $this->getVectorParentLookup();
        return !empty($lookup['model']) && !empty($lookup['parent_key']) && !empty($lookup['lookup_fields']);
    }

    /**
     * Resolve parent IDs from a search query
     * 
     * This allows searching for related records by looking up the parent first.
     * For example, searching "emails from john@example.com" will:
     * 1. Find Email records where email = "john@example.com"
     * 2. Return their IDs to filter EmailCache by mailbox_id
     * 
     * @param string $query The search query
     * @return array ['parent_key' => 'mailbox_id', 'parent_ids' => [1, 2, 3]]
     */
    public static function resolveParentIdsFromQuery(string $query): array
    {
        $instance = new static();
        
        if (!$instance->hasVectorParentLookup()) {
            return ['parent_key' => null, 'parent_ids' => []];
        }

        $lookup = $instance->getVectorParentLookup();
        $parentModel = $lookup['model'];
        $parentKey = $lookup['parent_key'];
        $lookupFields = $lookup['lookup_fields'];

        if (!class_exists($parentModel)) {
            return ['parent_key' => null, 'parent_ids' => []];
        }

        // Extract potential identifiers from query (emails, names, etc.)
        $identifiers = static::extractIdentifiersFromQuery($query);
        
        if (empty($identifiers)) {
            return ['parent_key' => $parentKey, 'parent_ids' => []];
        }

        try {
            $parentQuery = $parentModel::query();
            
            $parentQuery->where(function ($q) use ($lookupFields, $identifiers) {
                foreach ($lookupFields as $field) {
                    foreach ($identifiers as $identifier) {
                        $q->orWhere($field, 'LIKE', "%{$identifier}%");
                    }
                }
            });

            $parentIds = $parentQuery->pluck('id')->toArray();

            \Log::debug('Resolved parent IDs from query', [
                'model' => static::class,
                'parent_model' => $parentModel,
                'parent_key' => $parentKey,
                'identifiers' => $identifiers,
                'parent_ids' => $parentIds,
            ]);

            return [
                'parent_key' => $parentKey,
                'parent_ids' => $parentIds,
            ];
        } catch (\Exception $e) {
            \Log::warning('Failed to resolve parent IDs', [
                'model' => static::class,
                'error' => $e->getMessage(),
            ]);
            return ['parent_key' => null, 'parent_ids' => []];
        }
    }

    /**
     * Extract potential identifiers from a search query
     * 
     * @param string $query
     * @return array
     */
    protected static function extractIdentifiersFromQuery(string $query): array
    {
        $identifiers = [];

        // Extract email addresses
        preg_match_all('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $query, $emails);
        if (!empty($emails[0])) {
            $identifiers = array_merge($identifiers, $emails[0]);
        }

        // Extract quoted strings
        preg_match_all('/"([^"]+)"/', $query, $quoted);
        if (!empty($quoted[1])) {
            $identifiers = array_merge($identifiers, $quoted[1]);
        }

        // Extract single-quoted strings
        preg_match_all("/\'([^\']+)\'/", $query, $singleQuoted);
        if (!empty($singleQuoted[1])) {
            $identifiers = array_merge($identifiers, $singleQuoted[1]);
        }

        return array_unique(array_filter($identifiers));
    }

    /**
     * Get the parent key field name for filtering
     * 
     * @return string|null
     */
    public function getVectorParentKey(): ?string
    {
        $lookup = $this->getVectorParentLookup();
        return $lookup['parent_key'] ?? null;
    }

    /**
     * Convert model to vector array format
     * Used for indexing in vector database
     *
     * @return array
     */
    public function toVectorArray(): array
    {
        return [
            'id' => (string) $this->id,
            'content' => $this->getVectorContent(),
            'metadata' => array_merge(
                $this->getVectorMetadata(),
                [
                    'model_class' => get_class($this),
                    'model_id' => $this->id,
                    'indexed_at' => now()->toIso8601String(),
                ]
            ),
        ];
    }

    /**
     * Apply user-specific filters to query
     * Override this to implement row-level security
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string|null $userId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeApplyUserFilters($query, ?string $userId = null)
    {
        // If model has user_id column, filter by user
        if ($userId && $this->isFillable('user_id')) {
            $query->where('user_id', $userId);
        }

        // Filter by status if exists
        if ($this->isFillable('status')) {
            $query->where('status', '!=', 'draft');
        }

        // Filter by visibility if exists
        if ($this->isFillable('visibility')) {
            $query->where('visibility', 'public');
        }

        return $query;
    }


}
