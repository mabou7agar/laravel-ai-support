<?php

declare(strict_types=1);

namespace LaravelAIEngine\Traits\Concerns;

use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AIResponse;

trait HasVectorRAGMethods
{
    // ==========================================
    // RAG Methods
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
     * Get display name for RAG collection
     * Override this in your model for custom names
     *
     * @return string
     */
    public function getRAGDisplayName(): string
    {
        // Check if model has a custom display name property
        if (property_exists($this, 'ragDisplayName') && !empty($this->ragDisplayName)) {
            return $this->ragDisplayName;
        }

        // Convert class name to readable format
        // Example: EmailMessage -> Email Message
        $className = class_basename(static::class);
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $className));
    }

    /**
     * Get description for RAG collection
     * Override this in your model for custom descriptions
     *
     * @return string
     */
    public function getRAGDescription(): string
    {
        // Check if model has a custom description property
        if (property_exists($this, 'ragDescription') && !empty($this->ragDescription)) {
            return $this->ragDescription;
        }

        // Generate default description
        $displayName = $this->getRAGDisplayName();
        return "Search through {$displayName}";
    }

    /**
     * Get icon for RAG collection
     * Override this in your model for custom icons
     *
     * @return string
     */
    public function getRAGIcon(): string
    {
        // Check if model has a custom icon property
        if (property_exists($this, 'ragIcon') && !empty($this->ragIcon)) {
            return $this->ragIcon;
        }

        // Auto-detect icon based on class name
        $className = strtolower(class_basename(static::class));

        if (str_contains($className, 'email')) return '📧';
        if (str_contains($className, 'message')) return '💬';
        if (str_contains($className, 'document')) return '📄';
        if (str_contains($className, 'file')) return '📁';
        if (str_contains($className, 'post')) return '📝';
        if (str_contains($className, 'article')) return '📰';
        if (str_contains($className, 'user')) return '👤';
        if (str_contains($className, 'customer')) return '👥';
        if (str_contains($className, 'product')) return '🛍️';
        if (str_contains($className, 'order')) return '📦';
        if (str_contains($className, 'task')) return '✅';
        if (str_contains($className, 'note')) return '📝';
        if (str_contains($className, 'comment')) return '💭';

        return '📚';
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
    // RAG Query Methods (Static)
    // ==========================================

    /**
     * RAG chat - AI decides when to search
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
        $ragChat = app(RAGPipelineContract::class);
        $userId = $options['user_id'] ?? null;

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

        return $ragChat->process(
            $query,
            $sessionId,
            $collections,
            [],
            $options,
            $userId
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
        $ragChat = app(RAGPipelineContract::class);

        $response = $ragChat->process(
            $query,
            $userId ?? 'default',
            [static::class],
            [],
            array_merge($options, ['intelligent' => false]),
            $userId
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
     * Builds the RAG context synchronously (vector retrieval + prompt augmentation),
     * then streams the AI response token-by-token via the engine's streaming API.
     *
     * Callback signature: callable(string $chunk, bool $isFirst, bool $isDone)
     *   - $chunk   : the text token/chunk yielded by the AI engine
     *   - $isFirst : true only on the very first chunk
     *   - $isDone  : true only on the final (empty) sentinel call
     *
     * @param string $query
     * @param callable $callback  fn(string $chunk, bool $isFirst, bool $isDone): void
     * @param string|null $userId
     * @param array $options
     * @return array  ['response' => string, 'sources' => array, 'context_count' => int, 'query' => string]
     */
    public static function vectorChatStream(
        string $query,
        callable $callback,
        ?string $userId = null,
        array $options = []
    ): array {
        // ── Step 1: Build the RAG context (same as the synchronous pipeline) ──
        $ragOptions = array_merge($options, [
            'intelligent'     => false,
            'rag_collections' => [static::class],
            'session_id'      => $userId ?? 'default',
        ]);

        /** @var \LaravelAIEngine\Services\RAG\RAGQueryAnalyzer $analyzer */
        $analyzer = app(\LaravelAIEngine\Services\RAG\RAGQueryAnalyzer::class);
        /** @var \LaravelAIEngine\Services\RAG\RAGCollectionResolver $collectionResolver */
        $collectionResolver = app(\LaravelAIEngine\Services\RAG\RAGCollectionResolver::class);
        /** @var \LaravelAIEngine\Services\RAG\RAGRetriever $retriever */
        $retriever = app(\LaravelAIEngine\Services\RAG\RAGRetriever::class);
        /** @var \LaravelAIEngine\Services\RAG\RAGContextBuilder $contextBuilder */
        $contextBuilder = app(\LaravelAIEngine\Services\RAG\RAGContextBuilder::class);
        /** @var \LaravelAIEngine\Services\RAG\RAGPromptBuilder $promptBuilder */
        $promptBuilder = app(\LaravelAIEngine\Services\RAG\RAGPromptBuilder::class);

        $analysis        = $analyzer->analyze($query, $ragOptions);
        $collections     = $collectionResolver->resolve($ragOptions);
        $sources         = $retriever->retrieve(
            $analysis['queries'],
            $collections,
            $ragOptions,
            is_int($userId) || is_string($userId) ? $userId : null
        );
        $context         = $contextBuilder->build($sources);
        $augmentedPrompt = $promptBuilder->build($query, $context['context'], $ragOptions);

        // ── Step 2: Stream the augmented prompt through the AI engine ──
        /** @var \LaravelAIEngine\Services\UnifiedEngineManager $manager */
        $manager = app(\LaravelAIEngine\Services\UnifiedEngineManager::class);

        $streamOptions = array_filter([
            'engine'       => $options['engine'] ?? null,
            'model'        => $options['model'] ?? null,
            'max_tokens'   => $options['max_tokens'] ?? null,
            'temperature'  => $options['temperature'] ?? null,
            'system_prompt' => $options['system_prompt']
                ?? 'Answer using only the retrieved context. If the context is insufficient, say what is missing.',
        ], static fn ($v): bool => $v !== null);

        $generator = $manager->streamPrompt($augmentedPrompt, $streamOptions);

        // ── Step 3: Forward each chunk to the caller's callback ──
        $fullResponse = '';
        $isFirst      = true;

        foreach ($generator as $chunk) {
            $fullResponse .= $chunk;
            $callback($chunk, $isFirst, false);
            $isFirst = false;
        }

        // Signal completion with an empty sentinel chunk
        $callback('', false, true);

        return [
            'response'      => $fullResponse,
            'sources'       => $context['sources'] ?? [],
            'context_count' => count($context['sources'] ?? []),
            'query'         => $query,
        ];
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
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $response = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($request);

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
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $response = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($request);

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
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model)
        );

        $aiResponse = app(\LaravelAIEngine\Services\AIEngineService::class)->generate($request);
        $response = $aiResponse->getContent();

        $tags = array_map('trim', explode(',', $response));
        return array_slice($tags, 0, $maxTags);
    }
}
