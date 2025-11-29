<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\ConversationService;
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
    protected ConversationService $conversationService;
    protected array $config;

    public function __construct(
        VectorSearchService $vectorSearch,
        AIEngineManager $aiEngine,
        ConversationService $conversationService
    ) {
        $this->vectorSearch = $vectorSearch;
        $this->aiEngine = $aiEngine;
        $this->conversationService = $conversationService;
        $this->config = config('ai-engine.intelligent_rag', []);
    }

    /**
     * Process message with intelligent RAG
     *
     * The AI decides if it needs to search for context
     *
     * @param string $message The user's message
     * @param string $sessionId Session identifier
     * @param array $availableCollections Model classes to search
     * @param array $conversationHistory Optional conversation history
     * @param array $options Additional options (intelligent, engine, model, etc.)
     * @return AIResponse
     */
    public function processMessage(
        string $message,
        string $sessionId,
        array $availableCollections = [],
        array $conversationHistory = [],
        array $options = []
    ): AIResponse {
        try {
            // Load conversation history from session if not provided
            if (empty($conversationHistory)) {
                $conversationHistory = $this->loadConversationHistory($sessionId);
            }

            // Check if intelligent mode is enabled (default: true)
            $useIntelligent = $options['intelligent'] ?? true;

            // Step 1: Analyze if query needs context retrieval
            $analysis = $useIntelligent 
                ? $this->analyzeQuery($message, $conversationHistory, $availableCollections)
                : ['needs_context' => true, 'search_queries' => [$message], 'collections' => $availableCollections];

            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->debug('RAG Query Analysis', [
                    'session_id' => $sessionId,
                    'needs_context' => $analysis['needs_context'],
                    'search_queries' => $analysis['search_queries'] ?? [],
                    'collections' => $analysis['collections'] ?? [],
                ]);
            }

            // Step 2: Retrieve context if needed
            $context = collect();
            if ($analysis['needs_context']) {
                // If no search queries provided, use the original message
                $searchQueries = !empty($analysis['search_queries']) 
                    ? $analysis['search_queries'] 
                    : [$message];
                    
                $context = $this->retrieveRelevantContext(
                    $searchQueries,
                    $analysis['collections'] ?? $availableCollections,
                    $options
                );
                
                // If no results found, try again with fallback threshold
                $fallbackThreshold = $this->config['fallback_threshold'] ?? null;
                if ($context->isEmpty() && !empty($availableCollections) && $fallbackThreshold !== null) {
                    Log::channel('ai-engine')->debug('No RAG results found, retrying with fallback threshold', [
                        'fallback_threshold' => $fallbackThreshold,
                    ]);
                    $context = $this->retrieveRelevantContext(
                        $searchQueries,
                        $analysis['collections'] ?? $availableCollections,
                        array_merge($options, ['min_score' => $fallbackThreshold])
                    );
                }
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

            // Step 5: Add metadata about sources and session
            $metadata = array_merge(
                $response->getMetadata(),
                ['session_id' => $sessionId]
            );

            if ($context->isNotEmpty()) {
                $response = $this->enrichResponseWithSources($response, $context);
                $metadata = array_merge($metadata, $response->getMetadata());
            }

            // Create new response with updated metadata
            return new AIResponse(
                content: $response->getContent(),
                engine: $response->getEngine(),
                model: $response->getModel(),
                metadata: $metadata,
                tokensUsed: $response->getTokensUsed(),
                creditsUsed: $response->getCreditsUsed(),
                latency: $response->getLatency(),
                requestId: $response->getRequestId(),
                usage: $response->getUsage(),
                cached: $response->getCached(),
                finishReason: $response->getFinishReason(),
                files: $response->getFiles(),
                actions: $response->getActions(),
                error: $response->getError(),
                success: $response->getSuccess()
            );

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Intelligent RAG failed', [
                'session_id' => $sessionId,
                'message' => $message,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            // Fallback to regular response without RAG
            return $this->generateResponse($message, $options);
        }
    }

    /**
     * Process message with streaming support
     *
     * @param string $message The user's message
     * @param string $sessionId Session identifier
     * @param callable $callback Streaming callback
     * @param array $availableCollections Model classes to search
     * @param array $conversationHistory Optional conversation history
     * @param array $options Additional options
     * @return array Legacy format for backward compatibility
     */
    public function processMessageStream(
        string $message,
        string $sessionId,
        callable $callback,
        array $availableCollections = [],
        array $conversationHistory = [],
        array $options = []
    ): array {
        try {
            // Load conversation history
            if (empty($conversationHistory)) {
                $conversationHistory = $this->loadConversationHistory($sessionId);
            }

            // Check intelligent mode
            $useIntelligent = $options['intelligent'] ?? true;

            // Analyze query
            $analysis = $useIntelligent 
                ? $this->analyzeQuery($message, $conversationHistory, $availableCollections)
                : ['needs_context' => true, 'search_queries' => [$message], 'collections' => $availableCollections];

            // Retrieve context if needed
            $context = collect();
            if ($analysis['needs_context'] && !empty($analysis['search_queries'])) {
                $context = $this->retrieveRelevantContext(
                    $analysis['search_queries'],
                    $analysis['collections'] ?? $availableCollections,
                    $options
                );
            }

            // Build enhanced prompt
            $enhancedPrompt = $this->buildEnhancedPrompt(
                $message,
                $context,
                $conversationHistory,
                $options
            );

            // Stream response
            $fullResponse = '';
            $this->aiEngine
                ->engine($options['engine'] ?? config('ai-engine.default'))
                ->model($options['model'] ?? 'gpt-4o')
                ->stream(function ($chunk) use (&$fullResponse, $callback) {
                    $fullResponse .= $chunk;
                    $callback($chunk);
                })
                ->chat($enhancedPrompt);

            // Return metadata with sources
            return [
                'response' => $fullResponse,
                'sources' => $context->isNotEmpty() ? $context->toArray() : [],
                'context_count' => $context->count(),
                'session_id' => $sessionId,
                'rag_enabled' => $context->isNotEmpty(),
            ];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Intelligent RAG stream failed', [
                'session_id' => $sessionId,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
    protected function analyzeQuery(string $query, array $conversationHistory = [], array $availableCollections = []): array
    {
        // Build available collections info
        $collectionsInfo = '';
        if (!empty($availableCollections)) {
            $collectionsInfo = "\n\nAvailable knowledge sources:\n";
            foreach ($availableCollections as $collection) {
                $collectionsInfo .= "- " . class_basename($collection) . " (class: {$collection})\n";
            }
        }

        $systemPrompt = <<<PROMPT
You are a query analyzer for a knowledge base system. Your job is to determine if we should search our LOCAL knowledge base.
{$collectionsInfo}
CRITICAL RULES:
1. DEFAULT TO SEARCHING - When in doubt, search! (needs_context: true)
2. ALWAYS search if the query could be asking about what we know or what we can help with
3. ALWAYS search if asking about capabilities, assistance, or what information we have
4. Only skip searching for pure greetings ("hi", "hello") or simple math

Analyze the query and respond with JSON:
{
    "needs_context": true,
    "reasoning": "query asks about capabilities - should search to show what topics we have",
    "search_queries": ["Laravel", "tutorial", "guide"],
    "collections": ["App\\\\Models\\\\Post"],
    "query_type": "informational"
}

IMPORTANT: 
- Use FULL class names with DOUBLE backslashes (e.g., "App\\\\Models\\\\Post")
- DEFAULT to needs_context: true unless it's clearly just a greeting
- Questions about "what can you help with", "what do you know", "assist me" → ALWAYS search with BROAD queries
- When asked about capabilities/help, use EMPTY search_queries: [] to return ALL content
- For specific technical questions, use specific search terms
- For ANY question that might relate to our content, ALWAYS search

Examples that NEED context (needs_context: true):
- "what can you assist me at" → Search (asking about capabilities!)
- "what do you know" → Search (asking about our knowledge!)
- "help me" → Search (asking for assistance!)
- "what information do you have" → Search (asking about content!)
- "Tell me about Laravel routing" → Search Post
- "How does Eloquent work?" → Search Post
- "What is middleware?" → Search Post  
- "Explain Laravel queues" → Search Post
- "What are the latest posts?" → Search Post

Examples that DON'T need context (needs_context: false):
- "Hello" → ONLY greeting, nothing else
- "Hi" → ONLY greeting, nothing else  
- "What's 2+2?" → Simple math

IMPORTANT: If a greeting is followed by a question, it's NOT just a greeting!
- "Hi, what can you help with?" → needs_context: true (it's a question!)
- "Hello, tell me about X" → needs_context: true (it's a request!)
- "Hey, what do you know?" → needs_context: true (asking about knowledge!)
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

REMEMBER: When in doubt, ALWAYS set needs_context: true and search our knowledge base!
Only set needs_context: false if this is CLEARLY just "hi", "hello", or simple math.

Analyze this query and provide your assessment in JSON format.
PROMPT;

        try {
            // Create AI request for analysis
            $analysisModel = $this->config['analysis_model'] ?? 'gpt-4o';
            
            $request = new AIRequest(
                prompt:       $analysisPrompt,
                engine:       new \LaravelAIEngine\Enums\EngineEnum(config('ai-engine.default')),
                model:        new \LaravelAIEngine\Enums\EntityEnum($analysisModel),
                systemPrompt: $systemPrompt,
                temperature:  0.3,
                maxTokens:    300
            );

            $aiResponse = $this->aiEngine->processRequest($request);
            $response = $aiResponse->getContent();

            // Parse JSON response
            $analysis = $this->parseJsonResponse($response);

            // Handle empty arrays - use defaults when arrays are empty or null
            $searchQueries = $analysis['search_queries'] ?? null;
            if (empty($searchQueries)) {
                $searchQueries = [$query];
            }

            $collections = $analysis['collections'] ?? null;
            if (empty($collections)) {
                $collections = $availableCollections;
            }

            return [
                'needs_context' => $analysis['needs_context'] ?? false,
                'reasoning' => $analysis['reasoning'] ?? '',
                'search_queries' => $searchQueries,
                'collections' => $collections,
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
                'collections' => $availableCollections,
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

        // Filter out invalid collection names (must be valid class names)
        $validCollections = array_filter($collections, function($collection) {
            return class_exists($collection);
        });

        // If no valid collections, return empty (this is OK - RAG will work without vector search)
        if (empty($validCollections)) {
            Log::channel('ai-engine')->debug('No valid RAG collections found - continuing without vector search', [
                'provided_collections' => $collections,
                'note' => 'This is normal if no models use the Vectorizable trait. The chat will work without RAG context.',
            ]);
            return collect();
        }

        foreach ($searchQueries as $searchQuery) {
            foreach ($validCollections as $collection) {
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
        $systemPrompt = $options['system_prompt'] ?? $this->getDefaultSystemPrompt();
        
        $prompt = "{$systemPrompt}\n\n";
        
        // Add conversation history if available
        if (!empty($conversationHistory)) {
            $prompt .= "CONVERSATION HISTORY:\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($conversationHistory as $msg) {
                $role = ucfirst($msg['role'] ?? 'user');
                $content = $msg['content'] ?? '';
                $prompt .= "{$role}: {$content}\n\n";
            }
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        // Add context if available
        if ($context->isNotEmpty()) {
            $contextText = $this->formatContext($context);
            $prompt .= "RELEVANT CONTEXT FROM KNOWLEDGE BASE:\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $prompt .= "{$contextText}\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }
        
        $prompt .= "CURRENT QUESTION: {$message}\n\n";
        
        if ($context->isNotEmpty()) {
            $prompt .= "Please answer based on the context above and our conversation history. ";
            $prompt .= "If the context doesn't fully answer the question, acknowledge what you can answer and what you cannot.";
        } else {
            $prompt .= "Please answer based on our conversation history.";
        }

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
            $source = $item->title ?? $item->subject ?? $item->name ?? "Document " . ($index + 1);

            // Build metadata section
            $metadata = $this->buildMetadataSection($item);

            // Format with enhanced context
            $formattedItem = "[Source {$index}: {$source}] (Relevance: {$score}%)";
            
            if (!empty($metadata)) {
                $formattedItem .= "\n{$metadata}";
            }
            
            $formattedItem .= "\n{$content}";

            $formatted[] = $formattedItem;
        }

        return implode("\n\n---\n\n", $formatted);
    }

    /**
     * Build metadata section for context item
     * 
     * @param mixed $item
     * @return string
     */
    protected function buildMetadataSection($item): string
    {
        $metadata = [];

        // Add date information
        if (isset($item->email_date)) {
            $metadata[] = "Date: {$item->email_date}";
        } elseif (isset($item->created_at)) {
            $metadata[] = "Date: {$item->created_at}";
        }

        // Add sender information for emails
        if (isset($item->from_name) && isset($item->from_address)) {
            $metadata[] = "From: {$item->from_name} <{$item->from_address}>";
        } elseif (isset($item->from_address)) {
            $metadata[] = "From: {$item->from_address}";
        }

        // Add recipient information
        if (isset($item->to_addresses) && is_array($item->to_addresses)) {
            $toList = array_map(function($addr) {
                if (isset($addr['name']) && $addr['name']) {
                    return "{$addr['name']} <{$addr['email']}>";
                }
                return $addr['email'] ?? '';
            }, array_slice($item->to_addresses, 0, 3));
            
            if (!empty($toList)) {
                $metadata[] = "To: " . implode(', ', $toList);
                if (count($item->to_addresses) > 3) {
                    $metadata[] = "... and " . (count($item->to_addresses) - 3) . " more recipients";
                }
            }
        }

        // Add folder/category
        if (isset($item->folder_name)) {
            $metadata[] = "Folder: {$item->folder_name}";
        } elseif (isset($item->category)) {
            $metadata[] = "Category: {$item->category}";
        }

        // Add type/status
        if (isset($item->type)) {
            $metadata[] = "Type: {$item->type}";
        }
        if (isset($item->status)) {
            $metadata[] = "Status: {$item->status}";
        }

        // Add thread context
        if (isset($item->in_reply_to) && !empty($item->in_reply_to)) {
            $metadata[] = "Part of conversation thread";
        }

        return !empty($metadata) ? implode(' | ', $metadata) : '';
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

        $request = new AIRequest(
            prompt: $prompt,
            engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model),
            temperature: $temperature,
            maxTokens: $maxTokens
        );

        return $this->aiEngine->processRequest($request);
    }

    /**
     * Enrich response with source citations
     */
    protected function enrichResponseWithSources(AIResponse $response, Collection $context): AIResponse
    {
        $sources = $context->map(function ($item, $index) {
            return [
                'id' => $item->id ?? null,
                'model_id' => $item->id ?? null,  // Original model ID
                'model_class' => get_class($item),  // Full model class name
                'model_type' => class_basename($item),  // Short model name
                'title' => $item->title ?? $item->name ?? "Source " . ($index + 1),
                'relevance' => round(($item->vector_score ?? 0) * 100, 1),
                'content_preview' => isset($item->content) 
                    ? substr($item->content, 0, 200) 
                    : (isset($item->body) ? substr($item->body, 0, 200) : null),
            ];
        })->toArray();
        
        // Detect numbered options in the response
        $numberedOptions = $this->extractNumberedOptions($response->getContent());

        // Create new response with enriched metadata
        return new AIResponse(
            content: $response->getContent(),
            engine: $response->getEngine(),
            model: $response->getModel(),
            metadata: array_merge(
                $response->getMetadata(),
                [
                    'rag_enabled' => true,
                    'context_count' => $context->count(),
                    'sources' => $sources,
                    'numbered_options' => $numberedOptions,
                    'has_options' => !empty($numberedOptions),
                ]
            ),
            usage: $response->getUsage()
        );
    }
    
    /**
     * Extract numbered options from response content
     */
    protected function extractNumberedOptions(string $content): array
    {
        $options = [];
        
        // Pattern 1: Simple numbered lists (1. , 2. , etc.)
        if (preg_match_all('/^\s*(\d+)\.\s+(.+?)$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 10) as $match) {
                $number = (int) $match[1];
                $fullLine = trim($match[2]);
                
                // Extract just the title (before the colon or first sentence)
                $text = $fullLine;
                if (strpos($fullLine, ':') !== false) {
                    $text = trim(substr($fullLine, 0, strpos($fullLine, ':')));
                } elseif (strpos($fullLine, '.') !== false) {
                    $text = trim(substr($fullLine, 0, strpos($fullLine, '.')));
                }
                
                // Skip very short options
                if (strlen($text) < 3) {
                    continue;
                }
                
                $options[] = [
                    'number' => $number,
                    'text' => $text,
                    'full_text' => $fullLine,
                    'preview' => substr($text, 0, 100),
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
            }
        }
        
        // Pattern 2: Markdown bullet points with bold headers (- **Title**: Description)
        if (empty($options) && preg_match_all('/^-\s+\*\*(.+?)\*\*:?\s*(.+?)(?=\n-|\n\n|$)/ms', $content, $matches, PREG_SET_ORDER)) {
            $number = 1;
            foreach (array_slice($matches, 0, 10) as $match) {
                $title = trim($match[1]);
                $description = trim($match[2]);
                
                $options[] = [
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $title . ': ' . $description,
                    'preview' => substr($title, 0, 100),
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
                $number++;
            }
        }
        
        // Pattern 3: Markdown headers (#### Title)
        if (empty($options) && preg_match_all('/^#{2,4}\s+(.+?)$/m', $content, $matches, PREG_SET_ORDER)) {
            $number = 1;
            foreach (array_slice($matches, 0, 10) as $match) {
                $title = trim($match[1]);
                
                // Skip main title or very short headers
                if (strlen($title) < 5 || stripos($title, 'title:') !== false) {
                    continue;
                }
                
                $options[] = [
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $title,
                    'preview' => substr($title, 0, 100),
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
                $number++;
            }
        }
        
        return $options;
    }
    
    /**
     * Extract full text for a numbered option including continuation lines
     */
    protected function extractFullOptionText(string $content, int $number, string $firstLine): string
    {
        $lines = explode("\n", $content);
        $fullText = $firstLine;
        $foundStart = false;
        $nextNumber = $number + 1;
        
        foreach ($lines as $line) {
            // Find the start of this option
            if (!$foundStart && preg_match('/^\s*' . $number . '\.\s+/', $line)) {
                $foundStart = true;
                continue;
            }
            
            // If we found the start, collect continuation lines
            if ($foundStart) {
                // Stop if we hit the next number or empty line
                if (preg_match('/^\s*' . $nextNumber . '\.\s+/', $line) || trim($line) === '') {
                    break;
                }
                
                // Add continuation line
                $trimmed = trim($line);
                if ($trimmed && !preg_match('/^\d+\./', $trimmed)) {
                    $fullText .= ' ' . $trimmed;
                }
            }
        }
        
        return trim($fullText);
    }

    /**
     * Load conversation history from session
     *
     * @param string $sessionId
     * @return array
     */
    protected function loadConversationHistory(string $sessionId): array
    {
        try {
            // Get conversation - might return ID or model
            $conversationResult = $this->conversationService->getOrCreateConversation(
                $sessionId,
                null, // userId
                config('ai-engine.default'),
                'gpt-4o-mini'
            );

            // If it's a string (ID), fetch the conversation model
            if (is_string($conversationResult)) {
                $conversation = \LaravelAIEngine\Models\Conversation::find($conversationResult);

                if (!$conversation) {
                    return [];
                }
            } else {
                $conversation = $conversationResult;
            }

            // Get messages
            $messages = $conversation->messages()
                ->orderBy('created_at', 'desc')
                ->limit(10) // Last 10 messages for context
                ->get()
                ->reverse()
                ->map(function ($message) {
                    return [
                        'role' => $message->role,
                        'content' => $message->content,
                    ];
                })
                ->toArray();

            return $messages;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to load conversation history', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [];
        }
    }

    /**
     * Get default system prompt
     */
    protected function getDefaultSystemPrompt(): string
    {
        return <<<PROMPT
You are a specialized AI assistant with access to a specific knowledge base. Your role is to answer questions ONLY within the scope of your knowledge base.

IMPORTANT RULES:
1. ONLY answer questions related to the topics in your knowledge base
2. If a question is completely outside your knowledge domain (e.g., cooking, sports, general topics), politely decline:
   "I apologize, but I'm specialized in [your domain] and cannot help with questions about [their topic]. Please ask questions related to the topics in my knowledge base."
3. Use the provided context to give accurate, relevant answers
4. Cite sources by referring to [Source 0], [Source 1], etc.
5. If the context doesn't contain enough information about a relevant topic, say so clearly
6. Be concise but thorough
7. If you're unsure, acknowledge uncertainty rather than guessing

Remember: You are NOT a general-purpose assistant. Stay within your knowledge domain.
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
