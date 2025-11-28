<?php

namespace LaravelAIEngine\Traits;

use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\DTOs\AIResponse;

/**
 * Vectorizable Trait
 * 
 * All-in-one trait for vector search, RAG, and AI chat capabilities.
 * Combines functionality from: Vectorizable, HasVectorSearch, HasVectorChat, RAGgable
 * 
 * Usage:
 * ```php
 * class Document extends Model
 * {
 *     use Vectorizable;
 *     
 *     protected $ragPriority = 80;
 *     
 *     public function toVectorContent(): string
 *     {
 *         return $this->title . "\n\n" . $this->content;
 *     }
 * }
 * ```
 */
trait Vectorizable
{
    /**
     * Boot the Vectorizable trait for a model.
     * This automatically registers the VectorIndexObserver.
     */
    public static function bootVectorizable(): void
    {
        // Only register observer if auto_index is enabled
        if (config('ai-engine.vector.auto_index', false)) {
            static::observe(\LaravelAIEngine\Observers\VectorIndexObserver::class);
        }
    }

    /**
     * Define which fields should be vectorized
     * Override this in your model
     */
    public array $vectorizable = [];
    
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

    /**
     * Get content to be vectorized
     * Override this method for custom content generation
     */
    public function getVectorContent(): string
    {
        if (!empty($this->vectorizable)) {
            $content = [];
            
            foreach ($this->vectorizable as $field) {
                if (isset($this->$field)) {
                    $content[] = $this->$field;
                }
            }
            
            return implode(' ', $content);
        }

        // Default behavior: use common text fields
        $commonFields = ['title', 'name', 'content', 'description', 'body', 'text'];
        $content = [];
        
        foreach ($commonFields as $field) {
            if (isset($this->$field)) {
                $content[] = $this->$field;
            }
        }

        return implode(' ', $content);
    }

    /**
     * Get metadata for vector storage
     * Override this method for custom metadata
     */
    public function getVectorMetadata(): array
    {
        $metadata = [];

        // Add common metadata
        if (isset($this->user_id)) {
            $metadata['user_id'] = $this->user_id;
        }

        if (isset($this->status)) {
            $metadata['status'] = $this->status;
        }

        if (isset($this->category_id)) {
            $metadata['category_id'] = $this->category_id;
        }

        if (isset($this->type)) {
            $metadata['type'] = $this->type;
        }

        return $metadata;
    }

    /**
     * Check if model should be indexed
     * Override this method for custom logic
     */
    public function shouldBeIndexed(): bool
    {
        // Don't index if content is empty
        if (empty($this->getVectorContent())) {
            return false;
        }

        // Don't index drafts by default
        if (isset($this->status) && $this->status === 'draft') {
            return false;
        }

        // Don't index soft-deleted models
        if (method_exists($this, 'trashed') && $this->trashed()) {
            return false;
        }

        return true;
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
        
        // For now, just return direct relationships
        // TODO: Implement nested relationship traversal for depth > 1
        return $this->vectorRelationships;
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

    // ==========================================
    // RAGgable Methods
    // ==========================================

    /**
     * Get RAG priority for this model
     * 
     * @return int
     */
    public function getRAGPriority(): int
    {
        return property_exists($this, 'ragPriority') ? $this->ragPriority : 50;
    }

    /**
     * Determine if this model should be included in RAG
     * 
     * @param string $query
     * @param array $context
     * @return bool
     */
    public function shouldIncludeInRAG(string $query, array $context = []): bool
    {
        return true;
    }

    // ==========================================
    // Vector Search Methods (Static)
    // ==========================================

    /**
     * Search using vector similarity
     * 
     * @param string $query
     * @param int $limit
     * @param float $threshold
     * @param array $filters
     * @param string|null $userId
     * @return \Illuminate\Support\Collection
     */
    public static function vectorSearch(
        string $query,
        int $limit = 10,
        float $threshold = 0.5,
        array $filters = [],
        ?string $userId = null
    ): \Illuminate\Support\Collection {
        $vectorSearch = app(\LaravelAIEngine\Services\Vector\VectorSearchService::class);
        
        return $vectorSearch->search(
            static::class,
            $query,
            $limit,
            $threshold,
            $filters,
            $userId
        );
    }

    /**
     * Find similar items to this model
     * 
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    public function similarTo(int $limit = 5): \Illuminate\Support\Collection
    {
        return static::vectorSearch(
            $this->getVectorContent(),
            $limit
        )->reject(fn($item) => $item->id === $this->id);
    }

    // ==========================================
    // RAG Chat Methods (Static)
    // ==========================================

    /**
     * Intelligent RAG chat - AI decides when to search
     * 
     * @param string $query
     * @param string $sessionId
     * @param array $options Options:
     *   - 'collections': array - Specific collections to search (default: current model only)
     *   - 'search_all': bool - Search all available RAG collections (default: false)
     *   - 'restrict_to_model': bool - Only search this model (default: false, searches all for general context)
     * @return AIResponse
     */
    public static function intelligentChat(
        string $query,
        string $sessionId = 'default',
        array $options = []
    ): AIResponse {
        $intelligentRAG = app(IntelligentRAGService::class);
        
        // Determine which collections to search
        $restrictToModel = $options['restrict_to_model'] ?? false;
        
        if ($restrictToModel) {
            // Strict mode: Only search this specific model
            $collections = [static::class];
        } elseif ($options['search_all'] ?? false) {
            // Search all mode: Discover and search all RAG collections
            $discovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            $collections = $discovery->discover();
        } elseif (isset($options['collections'])) {
            // Custom collections provided
            $collections = $options['collections'];
        } else {
            // Default: Search all for general context (not restricted to this model)
            $discovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            $collections = $discovery->discover();
        }
        
        return $intelligentRAG->processMessage(
            $query,
            $sessionId,
            $collections,
            [],
            $options
        );
    }

    /**
     * Manual RAG chat - Always searches
     * 
     * @param string $query
     * @param string|null $userId
     * @param array $options
     * @return array
     */
    public static function vectorChat(
        string $query,
        ?string $userId = null,
        array $options = []
    ): array {
        $intelligentRAG = app(IntelligentRAGService::class);
        
        $response = $intelligentRAG->processMessage(
            $query,
            $userId ?? 'default',
            [static::class],
            [],
            array_merge($options, ['intelligent' => false])
        );
        
        return [
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
            'context_count' => $response->getMetadata()['context_count'] ?? 0,
            'query' => $query,
        ];
    }

    /**
     * Streaming RAG chat
     * 
     * @param string $query
     * @param callable $callback
     * @param string|null $userId
     * @param array $options
     * @return array
     */
    public static function vectorChatStream(
        string $query,
        callable $callback,
        ?string $userId = null,
        array $options = []
    ): array {
        $intelligentRAG = app(IntelligentRAGService::class);
        
        return $intelligentRAG->processMessageStream(
            $query,
            $userId ?? 'default',
            $callback,
            [static::class],
            [],
            array_merge($options, ['intelligent' => false])
        );
    }

    // ==========================================
    // Instance Methods
    // ==========================================

    /**
     * Ask a question about this specific model instance
     * 
     * @param string $question
     * @param array $options
     * @return string
     */
    public function ask(string $question, array $options = []): string
    {
        $content = $this->getVectorContent();
        
        $prompt = <<<PROMPT
Based on the following information:

{$content}

Question: {$question}

Please provide a helpful answer based on the information above.
PROMPT;

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o';

        $request = new \LaravelAIEngine\DTOs\AIRequest(
            prompt: $prompt,
            engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $response = app(\LaravelAIEngine\Services\AIEngineManager::class)->processRequest($request);
        
        return $response->getContent();
    }

    /**
     * Summarize this model's content
     * 
     * @param int $maxWords
     * @param array $options
     * @return string
     */
    public function summarize(int $maxWords = 100, array $options = []): string
    {
        $content = $this->getVectorContent();
        
        $prompt = "Summarize the following in {$maxWords} words or less:\n\n{$content}";

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o-mini';

        $request = new \LaravelAIEngine\DTOs\AIRequest(
            prompt: $prompt,
            engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $response = app(\LaravelAIEngine\Services\AIEngineManager::class)->processRequest($request);
        
        return $response->getContent();
    }

    /**
     * Generate tags/keywords for this model
     * 
     * @param int $maxTags
     * @param array $options
     * @return array
     */
    public function generateTags(int $maxTags = 5, array $options = []): array
    {
        $content = $this->getVectorContent();
        
        $prompt = "Extract {$maxTags} relevant tags/keywords from the following text. Return only the tags as a comma-separated list:\n\n{$content}";

        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o-mini';

        $request = new \LaravelAIEngine\DTOs\AIRequest(
            prompt: $prompt,
            engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $aiResponse = app(\LaravelAIEngine\Services\AIEngineManager::class)->processRequest($request);
        $response = $aiResponse->getContent();

        $tags = array_map('trim', explode(',', $response));
        return array_slice($tags, 0, $maxTags);
    }
}
