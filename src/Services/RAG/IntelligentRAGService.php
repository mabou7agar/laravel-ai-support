<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent RAG Service
 * 
 * The AI agent decides when to search the vector database based on the query.
 * This provides a more natural and efficient RAG experience.
 */
class IntelligentRAGService
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
     * Process message with intelligent RAG
     * 
     * The AI decides if it needs to search for context
     */
    public function processMessage(
        string $message,
        string $sessionId,
        array $availableCollections = [],
        array $conversationHistory = [],
        array $options = []
    ): AIResponse {
        try {
            // Step 1: Analyze if query needs context retrieval
            $analysis = $this->analyzeQuery($message, $conversationHistory);

            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->debug('RAG Query Analysis', [
                    'needs_context' => $analysis['needs_context'],
                    'search_queries' => $analysis['search_queries'] ?? [],
                    'collections' => $analysis['collections'] ?? [],
                ]);
            }

            // Step 2: Retrieve context if needed
            $context = collect();
            if ($analysis['needs_context'] && !empty($analysis['search_queries'])) {
                $context = $this->retrieveRelevantContext(
                    $analysis['search_queries'],
                    $analysis['collections'] ?? $availableCollections,
                    $options
                );
            }

            // Step 3: Build enhanced prompt with context
            $enhancedPrompt = $this->buildEnhancedPrompt(
                $message,
                $context,
                $conversationHistory,
                $options
            );

            // Step 4: Generate response
            $response = $this->generateResponse($enhancedPrompt, $options);

            // Step 5: Add metadata about sources
            if ($context->isNotEmpty()) {
                $response = $this->enrichResponseWithSources($response, $context);
            }

            return $response;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Intelligent RAG failed', [
                'message' => $message,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            // Fallback to regular response without RAG
            return $this->generateResponse($message, $options);
        }
    }

    /**
     * Analyze query to determine if context retrieval is needed
     * 
     * Uses AI to intelligently decide:
     * - Does this query need external knowledge?
     * - What should we search for?
     * - Which collections are relevant?
     */
    protected function analyzeQuery(string $query, array $conversationHistory = []): array
    {
        $systemPrompt = <<<PROMPT
You are a query analyzer. Determine if the user's question requires searching external knowledge.

Analyze the query and respond with JSON:
{
    "needs_context": true/false,
    "reasoning": "why context is/isn't needed",
    "search_queries": ["query1", "query2"],
    "collections": ["collection_name"],
    "query_type": "factual|conversational|creative|technical"
}

Examples of queries that NEED context:
- "What did the document say about X?"
- "Find information about Y in our database"
- "What are the details of Z?"
- "Search for emails about..."

Examples that DON'T need context:
- "Hello, how are you?"
- "What's 2+2?"
- "Tell me a joke"
- "Continue our conversation"
PROMPT;

        $conversationContext = '';
        if (!empty($conversationHistory)) {
            $recentMessages = array_slice($conversationHistory, -3);
            $conversationContext = "\n\nRecent conversation:\n" . 
                implode("\n", array_map(fn($m) => "{$m['role']}: {$m['content']}", $recentMessages));
        }

        $analysisPrompt = <<<PROMPT
{$conversationContext}

Current query: "{$query}"

Analyze this query and provide your assessment in JSON format.
PROMPT;

        try {
            $response = $this->aiEngine
                ->engine(config('ai-engine.default'))
                ->model('gpt-4o-mini') // Use fast model for analysis
                ->temperature(0.3) // Low temperature for consistent analysis
                ->maxTokens(300)
                ->systemPrompt($systemPrompt)
                ->chat($analysisPrompt);

            // Parse JSON response
            $analysis = $this->parseJsonResponse($response);

            return [
                'needs_context' => $analysis['needs_context'] ?? false,
                'reasoning' => $analysis['reasoning'] ?? '',
                'search_queries' => $analysis['search_queries'] ?? [$query],
                'collections' => $analysis['collections'] ?? [],
                'query_type' => $analysis['query_type'] ?? 'conversational',
            ];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Query analysis failed, defaulting to no context', [
                'error' => $e->getMessage(),
            ]);

            // Default to not using context if analysis fails
            return [
                'needs_context' => false,
                'reasoning' => 'Analysis failed',
                'search_queries' => [],
                'collections' => [],
                'query_type' => 'conversational',
            ];
        }
    }

    /**
     * Retrieve relevant context from vector database
     */
    protected function retrieveRelevantContext(
        array $searchQueries,
        array $collections,
        array $options = []
    ): Collection {
        $allResults = collect();
        $maxResults = $options['max_context'] ?? $this->config['max_context_items'] ?? 5;
        $threshold = $options['min_score'] ?? $this->config['min_relevance_score'] ?? 0.7;

        foreach ($searchQueries as $searchQuery) {
            foreach ($collections as $collection) {
                try {
                    $results = $this->vectorSearch->search(
                        $collection,
                        $searchQuery,
                        $maxResults,
                        $threshold
                    );

                    $allResults = $allResults->merge($results);
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Vector search failed', [
                        'collection' => $collection,
                        'query' => $searchQuery,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Deduplicate and sort by relevance
        return $allResults
            ->unique('id')
            ->sortByDesc('vector_score')
            ->take($maxResults);
    }

    /**
     * Build enhanced prompt with context
     */
    protected function buildEnhancedPrompt(
        string $message,
        Collection $context,
        array $conversationHistory = [],
        array $options = []
    ): string {
        if ($context->isEmpty()) {
            return $message;
        }

        $systemPrompt = $options['system_prompt'] ?? $this->getDefaultSystemPrompt();
        $contextText = $this->formatContext($context);

        $prompt = "{$systemPrompt}\n\n";
        $prompt .= "RELEVANT CONTEXT FROM KNOWLEDGE BASE:\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "{$contextText}\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "USER QUESTION: {$message}\n\n";
        $prompt .= "Please answer based on the context above. If the context doesn't fully answer the question, acknowledge what you can answer and what you cannot.";

        return $prompt;
    }

    /**
     * Format context for prompt
     */
    protected function formatContext(Collection $context): string
    {
        $formatted = [];

        foreach ($context as $index => $item) {
            $content = $this->extractContent($item);
            $score = round(($item->vector_score ?? 0) * 100, 1);
            $source = $item->title ?? $item->name ?? "Document " . ($index + 1);
            
            $formatted[] = "[Source {$index}: {$source}] (Relevance: {$score}%)\n{$content}";
        }

        return implode("\n\n", $formatted);
    }

    /**
     * Extract content from model
     */
    protected function extractContent($model): string
    {
        if (method_exists($model, 'getVectorContent')) {
            return $model->getVectorContent();
        }

        if (property_exists($model, 'vectorizable')) {
            $content = [];
            foreach ($model->vectorizable as $field) {
                if (isset($model->$field)) {
                    $content[] = $model->$field;
                }
            }
            return implode(' ', $content);
        }

        $fields = ['content', 'body', 'description', 'text', 'title', 'name'];
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
        $maxTokens = $options['max_tokens'] ?? 2000;

        return $this->aiEngine
            ->engine($engine)
            ->model($model)
            ->temperature($temperature)
            ->maxTokens($maxTokens)
            ->generate(AIRequest::create($prompt));
    }

    /**
     * Enrich response with source citations
     */
    protected function enrichResponseWithSources(AIResponse $response, Collection $context): AIResponse
    {
        $sources = $context->map(function ($item, $index) {
            return [
                'id' => $item->id ?? null,
                'title' => $item->title ?? $item->name ?? "Source " . ($index + 1),
                'relevance' => round(($item->vector_score ?? 0) * 100, 1),
                'type' => class_basename($item),
            ];
        })->toArray();

        return $response->withMetadata([
            'rag_enabled' => true,
            'sources' => $sources,
            'context_count' => $context->count(),
        ]);
    }

    /**
     * Get default system prompt
     */
    protected function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are a helpful AI assistant with access to a knowledge base. When answering questions:

1. Use the provided context to give accurate, relevant answers
2. Cite sources by referring to [Source 0], [Source 1], etc.
3. If the context doesn't contain enough information, say so clearly
4. Be concise but thorough
5. If you're unsure, acknowledge uncertainty rather than guessing
PROMPT;
    }

    /**
     * Parse JSON response from AI
     */
    protected function parseJsonResponse(string $response): array
    {
        // Extract JSON from response (handle markdown code blocks)
        $response = preg_replace('/```json\s*|\s*```/', '', $response);
        $response = trim($response);

        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::channel('ai-engine')->warning('Failed to parse JSON response', [
                'response' => $response,
                'error' => $e->getMessage(),
            ]);
            
            return [];
        }
    }
}
