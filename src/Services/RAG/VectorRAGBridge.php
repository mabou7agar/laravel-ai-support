<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\AIEngineManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * VectorRAGBridge - Manual RAG Service
 *
 * This service ALWAYS performs vector search for every query.
 * Use this when you want guaranteed context retrieval.
 *
 * For intelligent RAG where AI decides when to search,
 * use IntelligentRAGService instead.
 *
 * @see IntelligentRAGService For AI-powered decision making
 */
class VectorRAGBridge
{
    protected VectorSearchService $vectorSearch;
    protected AIEngineManager $aiEngine;
    protected array $config;

    public function __construct(
        VectorSearchService $vectorSearch,
        AIEngineManager $aiEngine
    ) {
        $this->vectorSearch = $vectorSearch;
        $this->aiEngine = $aiEngine;
        $this->config = config('ai-engine.vector.rag', []);
    }

    /**
     * Generate AI response with vector context (RAG)
     */
    public function chat(
        string $query,
        string $modelClass,
        ?string $userId = null,
        array $options = []
    ): array {
        try {
            // Get relevant context from vector search
            $context = $this->retrieveContext($query, $modelClass, $userId, $options);

            // Build prompt with context
            $prompt = $this->buildPrompt($query, $context, $options);

            // Generate AI response
            $response = $this->generateResponse($prompt, $options);

            // Return response with sources
            return [
                'response' => $response,
                'sources' => $this->config['include_sources'] ?? true ? $context : [],
                'context_count' => $context->count(),
                'query' => $query,
            ];
        } catch (\Exception $e) {
            Log::error('RAG chat failed', [
                'query' => $query,
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Stream AI response with vector context
     */
    public function streamChat(
        string $query,
        string $modelClass,
        callable $callback,
        ?string $userId = null,
        array $options = []
    ): array {
        try {
            // Get relevant context
            $context = $this->retrieveContext($query, $modelClass, $userId, $options);

            // Build prompt with context
            $prompt = $this->buildPrompt($query, $context, $options);

            // Stream AI response
            $fullResponse = '';
            $this->aiEngine
                ->engine($options['engine'] ?? config('ai-engine.default'))
                ->model($options['model'] ?? 'gpt-4o')
                ->stream(function ($chunk) use (&$fullResponse, $callback) {
                    $fullResponse .= $chunk;
                    $callback($chunk);
                })
                ->generate($prompt);

            // Return metadata with sources
            return [
                'response' => $fullResponse,
                'sources' => $this->config['include_sources'] ?? true ? $context : [],
                'context_count' => $context->count(),
                'query' => $query,
            ];
        } catch (\Exception $e) {
            Log::error('RAG stream chat failed', [
                'query' => $query,
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Retrieve relevant context from vector database
     */
    protected function retrieveContext(
        string $query,
        string $modelClass,
        ?string $userId = null,
        array $options = []
    ): Collection {
        $limit = $options['max_context'] ?? $this->config['max_context_items'] ?? 5;
        $threshold = $options['min_score'] ?? $this->config['min_relevance_score'] ?? 0.5;
        $filters = $options['filters'] ?? [];

        return $this->vectorSearch->search(
            $modelClass,
            $query,
            $limit,
            $threshold,
            $filters,
            $userId
        );
    }

    /**
     * Build prompt with context
     */
    protected function buildPrompt(string $query, Collection $context, array $options = []): string
    {
        if ($context->isEmpty()) {
            return $query;
        }

        $systemPrompt = $options['system_prompt'] ?? $this->getDefaultSystemPrompt();
        $contextText = $this->formatContext($context, $options);

        return <<<PROMPT
{$systemPrompt}

CONTEXT INFORMATION:
{$contextText}

USER QUESTION:
{$query}

Please provide a helpful answer based on the context provided above. If the context doesn't contain enough information to answer the question, say so clearly.
PROMPT;
    }

    /**
     * Format context for prompt
     */
    protected function formatContext(Collection $context, array $options = []): string
    {
        $formatted = [];
        $includeScores = $options['include_scores'] ?? false;

        foreach ($context as $index => $item) {
            $content = $this->extractContent($item);
            $score = $includeScores ? " (Relevance: " . round($item->vector_score * 100, 1) . "%)" : "";

            $formatted[] = "[Source " . ($index + 1) . "]{$score}\n{$content}";
        }

        return implode("\n\n---\n\n", $formatted);
    }

    /**
     * Extract content from model
     */
    protected function extractContent($model): string
    {
        // Try custom method first
        if (method_exists($model, 'getVectorContent')) {
            return $model->getVectorContent();
        }

        // Try vectorizable fields
        if (property_exists($model, 'vectorizable')) {
            $content = [];
            foreach ($model->vectorizable as $field) {
                if (isset($model->$field)) {
                    $content[] = $model->$field;
                }
            }
            return implode(' ', $content);
        }

        // Fallback to common fields
        $fields = ['title', 'name', 'content', 'description', 'body'];
        $content = [];

        foreach ($fields as $field) {
            if (isset($model->$field)) {
                $content[] = $model->$field;
            }
        }

        return implode(' ', $content);
    }

    /**
     * Generate AI response
     */
    protected function generateResponse(string $prompt, array $options = []): AIResponse
    {
        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? 'gpt-4o';
        $temperature = $options['temperature'] ?? 0.7;
        $maxTokens = $options['max_tokens'] ?? 1000;

        return $this->aiEngine
            ->engine($engine)
            ->model($model)
            ->withTemperature($temperature)
            ->withMaxTokens($maxTokens)
            ->generate($prompt);
    }

    /**
     * Get default system prompt
     */
    protected function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are a helpful AI assistant. Your task is to answer questions based on the provided context information.

Guidelines:
- Use the context information to provide accurate, relevant answers
- If the context doesn't contain enough information, acknowledge this clearly
- Cite sources when possible by referring to "Source 1", "Source 2", etc.
- Be concise but thorough
- If you're unsure, say so rather than making up information
PROMPT;
    }

    /**
     * Set custom system prompt
     */
    public function setSystemPrompt(string $prompt): self
    {
        $this->config['system_prompt'] = $prompt;
        return $this;
    }

    /**
     * Set max context items
     */
    public function setMaxContext(int $max): self
    {
        $this->config['max_context_items'] = $max;
        return $this;
    }

    /**
     * Set minimum relevance score
     */
    public function setMinScore(float $score): self
    {
        $this->config['min_relevance_score'] = $score;
        return $this;
    }

    /**
     * Enable/disable source citations
     */
    public function includeSources(bool $include = true): self
    {
        $this->config['include_sources'] = $include;
        return $this;
    }
}
