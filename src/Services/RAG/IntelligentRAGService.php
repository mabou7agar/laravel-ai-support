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

    protected $nodeRegistry = null;
    protected $federatedSearch = null;

    public function __construct(
        VectorSearchService $vectorSearch,
        AIEngineManager $aiEngine,
        ConversationService $conversationService
    ) {
        $this->vectorSearch = $vectorSearch;
        $this->aiEngine = $aiEngine;
        $this->conversationService = $conversationService;
        $this->config = config('ai-engine.intelligent_rag', []);
        
        // Lazy load node services if available
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            $this->nodeRegistry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
        }
        if (class_exists(\LaravelAIEngine\Services\Node\FederatedSearchService::class)) {
            $this->federatedSearch = app(\LaravelAIEngine\Services\Node\FederatedSearchService::class);
        }
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
     * Analyze query with conversation context to determine nodes (CONTEXT-AWARE)
     * 
     * This method considers the full conversation history to intelligently
     * select which nodes to search, maintaining context continuity.
     */
    protected function analyzeQueryWithContext(
        string $query,
        array $conversationHistory = [],
        ?Collection $availableNodes = null
    ): array {
        // If node services not available, fallback to regular analysis
        if (!$this->nodeRegistry || !$this->federatedSearch) {
            return $this->analyzeQuery($query, $conversationHistory, []);
        }
        
        // Get available nodes
        $nodes = $availableNodes ?? $this->nodeRegistry->getActiveNodes();
        
        if ($nodes->isEmpty()) {
            return $this->analyzeQuery($query, $conversationHistory, []);
        }
        
        // Build node information for AI
        $nodeInfo = $this->buildNodeInformation($nodes);
        
        // Build conversation context
        $contextSummary = $this->buildConversationContext($conversationHistory);
        
        // Create enhanced analysis prompt
        $systemPrompt = $this->getContextAwareAnalysisPrompt($nodeInfo);
        
        $userPrompt = <<<PROMPT
CONVERSATION CONTEXT:
{$contextSummary}

CURRENT QUERY: "{$query}"

Based on the conversation context and current query, determine:
1. Should we search for information?
2. Which specific nodes are relevant?
3. What collections/models to search?
4. What search queries to use?

Consider:
- Previous topics discussed
- User's current intent
- Which nodes have relevant data
- Context continuity

Respond in JSON format.
PROMPT;

        try {
            $request = new AIRequest(
                prompt: $userPrompt,
                engine: new \LaravelAIEngine\Enums\EngineEnum(config('ai-engine.default')),
                model: new \LaravelAIEngine\Enums\EntityEnum($this->config['analysis_model'] ?? 'gpt-4o'),
                systemPrompt: $systemPrompt,
                temperature: 0.3,
                maxTokens: 500
            );

            $aiResponse = $this->aiEngine->processRequest($request);
            $response = $aiResponse->getContent();
            
            // Parse JSON response
            $analysis = $this->parseJsonResponse($response);
            
            return [
                'needs_context' => $analysis['needs_context'] ?? false,
                'nodes' => $analysis['nodes'] ?? [],
                'collections' => $analysis['collections'] ?? [],
                'search_queries' => $analysis['search_queries'] ?? [$query],
                'reasoning' => $analysis['reasoning'] ?? '',
                'context_topics' => $analysis['context_topics'] ?? [],
                'search_strategy' => $analysis['search_strategy'] ?? 'parallel',
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Context-aware analysis failed', [
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to basic analysis
            return $this->analyzeQuery($query, $conversationHistory, []);
        }
    }
    
    /**
     * Build node information for AI analysis
     */
    protected function buildNodeInformation(Collection $nodes): string
    {
        $info = "AVAILABLE NODES:\n\n";
        
        foreach ($nodes as $node) {
            $capabilities = implode(', ', $node->capabilities ?? []);
            
            $info .= "Node: {$node->name} (slug: {$node->slug})\n";
            $info .= "  Capabilities: {$capabilities}\n";
            
            // Add description (primary source of information for AI)
            if ($node->description) {
                $info .= "  Description: {$node->description}\n";
            }
            
            // Add domain/category information
            if (!empty($node->domains)) {
                $info .= "  Domains: " . implode(', ', $node->domains) . "\n";
            }
            
            // Add data types
            if (!empty($node->data_types)) {
                $info .= "  Data Types: " . implode(', ', $node->data_types) . "\n";
            }
            
            // Add keywords for better matching
            if (!empty($node->keywords)) {
                $info .= "  Keywords: " . implode(', ', $node->keywords) . "\n";
            }
            
            // Add health status
            $info .= "  Status: " . ($node->isHealthy() ? '✅ Healthy' : '⚠️ Degraded') . "\n";
            
            $info .= "\n";
        }
        
        return $info;
    }
    
    /**
     * Build conversation context summary
     */
    protected function buildConversationContext(array $conversationHistory): string
    {
        if (empty($conversationHistory)) {
            return "No previous conversation.";
        }
        
        // Get recent messages (configurable window)
        $contextWindow = $this->config['context_window'] ?? 5;
        $recentMessages = array_slice($conversationHistory, -$contextWindow);
        
        $context = "Recent conversation:\n";
        foreach ($recentMessages as $msg) {
            $role = ucfirst($msg['role'] ?? 'user');
            $content = substr($msg['content'] ?? '', 0, 200);
            $context .= "{$role}: {$content}\n";
        }
        
        // Extract topics from conversation
        $topics = $this->extractTopicsFromConversation($conversationHistory);
        if (!empty($topics)) {
            $context .= "\nTopics discussed: " . implode(', ', $topics) . "\n";
        }
        
        return $context;
    }
    
    /**
     * Extract topics from conversation history
     */
    protected function extractTopicsFromConversation(array $conversationHistory): array
    {
        $topics = [];
        
        // Common keywords to extract
        $keywords = ['laravel', 'php', 'product', 'book', 'tutorial', 'customer', 'order', 'article', 'support', 'ticket', 'purchase', 'learn'];
        
        foreach ($conversationHistory as $msg) {
            $content = strtolower($msg['content'] ?? '');
            
            foreach ($keywords as $keyword) {
                if (str_contains($content, $keyword) && !in_array($keyword, $topics)) {
                    $topics[] = $keyword;
                }
            }
        }
        
        return $topics;
    }
    
    /**
     * Get context-aware analysis prompt
     */
    protected function getContextAwareAnalysisPrompt(string $nodeInfo): string
    {
        return <<<PROMPT
You are an intelligent node selector for a distributed AI system. Your job is to analyze the conversation context and current query to determine which nodes to search.

{$nodeInfo}

ANALYSIS RULES:

1. **Context Continuity**: If the conversation is about a specific topic, prefer nodes related to that topic
   - Example: If discussing "Laravel books", prioritize e-commerce node

2. **Intent Evolution**: Detect when user's intent changes
   - "Tell me about Laravel" → "Show me books" = Same topic, different intent
   - "Tell me about Laravel" → "What's the weather?" = Topic change

3. **Node Matching**: Match query intent to node capabilities
   - Product queries → E-commerce node
   - Tutorial/article queries → Blog node
   - Customer/support queries → CRM node
   - General queries → Multiple nodes

4. **Optimization**: Only search relevant nodes
   - Don't search all nodes for specific queries
   - Use context to narrow down nodes

5. **Multi-Node Queries**: Some queries need multiple nodes
   - "Find Laravel resources" → Blog + E-commerce
   - "Customer who bought X" → CRM + E-commerce

RESPONSE FORMAT (JSON):
{
    "needs_context": true,
    "reasoning": "User is asking about Laravel books based on previous context",
    "context_topics": ["laravel", "books", "learning"],
    "nodes": ["ecommerce"],
    "collections": ["App\\\\Models\\\\Product"],
    "search_queries": ["Laravel books", "Laravel learning resources"],
    "search_strategy": "parallel"
}

IMPORTANT:
- Consider the FULL conversation context, not just the current query
- Use previous topics to inform node selection
- Be specific about which nodes to search
- Explain your reasoning
- Use DOUBLE backslashes in class names (e.g., "App\\\\Models\\\\Product")
- Empty nodes array means search all nodes
PROMPT;
    }

    /**
     * Retrieve relevant context from vector database
     * Uses federated search across nodes if available, otherwise local search
     */
    protected function retrieveRelevantContext(
        array $searchQueries,
        array $collections,
        array $options = []
    ): Collection {
        $allResults = collect();
        $maxResults = $options['max_context'] ?? $this->config['max_context_items'] ?? 5;
        $threshold = $options['min_score'] ?? $this->config['min_relevance_score'] ?? 0.3;

        // Use federated search if available and enabled
        $useFederatedSearch = $this->federatedSearch && config('ai-engine.nodes.enabled', false);
        
        if ($useFederatedSearch) {
            // For federated search, we trust the collections array
            // The child nodes will validate if they have the class
            Log::channel('ai-engine')->debug('Using federated search - delegating collection validation to nodes', [
                'collections' => $collections,
                'note' => 'Collections may exist on remote nodes even if not available locally',
            ]);
            
            return $this->retrieveFromFederatedSearch($searchQueries, $collections, $maxResults, $threshold, $options);
        }
        
        // For local search only, validate collections exist locally
        $validCollections = array_filter($collections, function($collection) {
            if (!class_exists($collection)) {
                Log::channel('ai-engine')->debug('Collection class does not exist locally', [
                    'collection' => $collection,
                    'note' => 'Enable federated search to search remote nodes',
                ]);
                return false;
            }
            
            // Check if class uses Vectorizable trait
            $uses = class_uses_recursive($collection);
            $hasVectorizable = in_array(\LaravelAIEngine\Traits\Vectorizable::class, $uses) ||
                              in_array(\LaravelAIEngine\Traits\VectorizableWithMedia::class, $uses);
            
            if (!$hasVectorizable) {
                Log::channel('ai-engine')->debug('Collection class does not use Vectorizable trait', [
                    'collection' => $collection,
                    'available_traits' => array_values($uses),
                ]);
            }
            
            return $hasVectorizable;
        });

        // If no valid collections locally, return empty
        if (empty($validCollections)) {
            Log::channel('ai-engine')->debug('No valid RAG collections found locally', [
                'provided_collections' => $collections,
                'note' => 'Enable federated search (AI_ENGINE_NODES_ENABLED=true) to search remote nodes',
            ]);
            return collect();
        }

        // Use local search
        return $this->retrieveFromLocalSearch($searchQueries, $validCollections, $maxResults, $threshold);
    }
    
    /**
     * Retrieve context using federated search across nodes
     */
    protected function retrieveFromFederatedSearch(
        array $searchQueries,
        array $collections,
        int $maxResults,
        float $threshold,
        array $options
    ): Collection {
        $allResults = collect();
        
        foreach ($searchQueries as $searchQuery) {
            try {
                // Use federated search across all nodes
                $federatedResults = $this->federatedSearch->search(
                    query: $searchQuery,
                    nodeIds: null, // Auto-select nodes based on context
                    limit: $maxResults,
                    options: array_merge($options, [
                        'collections' => $collections,
                        'threshold' => $threshold,
                    ])
                );
                
                // Extract results from federated response
                if (!empty($federatedResults['results'])) {
                    // Always log first result structure to debug metadata issue
                    if (count($federatedResults['results']) > 0) {
                        \Log::info('Federated result structure', [
                            'first_result_keys' => array_keys($federatedResults['results'][0]),
                            'has_metadata' => isset($federatedResults['results'][0]['metadata']),
                            'has_vector_metadata' => isset($federatedResults['results'][0]['vector_metadata']),
                            'metadata_value' => $federatedResults['results'][0]['metadata'] ?? 'not set',
                        ]);
                    }
                    
                    foreach ($federatedResults['results'] as $result) {
                        // Convert array result to object for consistency
                        $obj = (object) $result;
                        
                        // Ensure metadata is preserved and accessible at the top level
                        // This is critical for enrichResponseWithSources to extract model_class
                        if (isset($result['metadata']) && is_array($result['metadata'])) {
                            $obj->metadata = $result['metadata'];
                            // Also set vector_metadata as an alias for consistency with local search
                            $obj->vector_metadata = $result['metadata'];
                        }
                        
                        // Handle both metadata and vector_metadata keys from different node implementations
                        if (isset($result['vector_metadata']) && is_array($result['vector_metadata'])) {
                            $obj->vector_metadata = $result['vector_metadata'];
                            if (!isset($obj->metadata)) {
                                $obj->metadata = $result['vector_metadata'];
                            }
                        }
                        
                        // Ensure vector_score is set for relevance calculation
                        if (!isset($obj->vector_score) && isset($result['score'])) {
                            $obj->vector_score = $result['score'];
                        }
                        
                        $allResults->push($obj);
                    }
                }
                
                Log::channel('ai-engine')->debug('Federated search completed', [
                    'query' => $searchQuery,
                    'nodes_searched' => $federatedResults['nodes_searched'] ?? 0,
                    'total_results' => $federatedResults['total_results'] ?? 0,
                ]);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Federated search failed, falling back to local', [
                    'query' => $searchQuery,
                    'error' => $e->getMessage(),
                ]);
                
                // Fallback to local search for this query
                $localResults = $this->retrieveFromLocalSearch([$searchQuery], $collections, $maxResults, $threshold);
                $allResults = $allResults->merge($localResults);
            }
        }
        
        // Deduplicate and sort by relevance
        return $allResults
            ->unique('id')
            ->sortByDesc('vector_score')
            ->take($maxResults);
    }
    
    /**
     * Retrieve context using local vector search
     */
    protected function retrieveFromLocalSearch(
        array $searchQueries,
        array $collections,
        int $maxResults,
        float $threshold
    ): Collection {
        $allResults = collect();
        
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
            // Determine model class - try multiple approaches
            $modelClass = null;
            
            // Approach 1: If it's an actual model instance (not stdClass), use get_class
            if (!($item instanceof \stdClass)) {
                $modelClass = get_class($item);
            }
            
            // Approach 2: Check vector_metadata property for model_class (from local search)
            if (!$modelClass && isset($item->vector_metadata) && is_array($item->vector_metadata)) {
                $modelClass = $item->vector_metadata['model_class'] ?? null;
            }
            
            // Approach 3: Check metadata property for model_class (from federated search)
            if (!$modelClass && isset($item->metadata) && is_array($item->metadata)) {
                $modelClass = $item->metadata['model_class'] ?? null;
            }
            
            // Log warning only if we still can't determine the model class
            if (!$modelClass && config('ai-engine.debug')) {
                Log::channel('ai-engine')->warning('Could not determine model class for source', [
                    'index' => $index,
                    'item_class' => get_class($item),
                    'has_vector_metadata' => isset($item->vector_metadata),
                    'has_metadata' => isset($item->metadata),
                ]);
            }
            
            return [
                'id' => $item->id ?? null,
                'model_id' => $item->id ?? null,  // Original model ID
                'model_class' => $modelClass ?? 'Unknown',  // Full model class name
                'model_type' => $modelClass ? class_basename($modelClass) : 'Unknown',  // Short model name
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
You are an intelligent AI assistant with access to a knowledge base powered by vector search. Your role is to help users by finding and using relevant information from the embedded content.

CAPABILITIES:
- Vector search across all embedded content (documents, emails, posts, files, etc.)
- Can analyze uploaded files (images, documents, code, emails, attachments)
- Can access and read files from provided URLs
- Can extract and interpret information from any embedded source

HOW TO RESPOND:
1. ✅ ALWAYS search for relevant context first
   - If context is found: Answer based on the embedded content and cite sources [Source 0], [Source 1]
   - If NO context found: Politely inform the user that no relevant information was found in the knowledge base
   
2. ✅ ANSWER any question where relevant context exists:
   - "Do I have emails?" → Search emails and summarize what's found
   - "What posts are available?" → Search posts and list them
   - "Show me Laravel tutorials" → Search and present relevant content
   - "What's in my knowledge base about X?" → Search and answer
   
3. ❌ ONLY reject if:
   - No context found AND question requires specific embedded data
   - Message: "I couldn't find any relevant information in the knowledge base about [topic]. The knowledge base contains: [list available content types if known]."

ANSWERING RULES:
- When context IS found: Use it confidently and cite sources
- When files/URLs provided: Access and analyze them
- Be helpful and informative based on what's embedded
- If multiple results: Summarize or list them
- Always cite sources when using embedded content

EXAMPLES:
✅ "Do I have mails?" → Search emails, list subjects/senders if found
✅ "What posts do you have?" → Search posts, summarize available content
✅ "Tell me about Laravel routing" → Search and answer from embedded docs
✅ "What's in this file?" → Analyze uploaded/linked file
✅ "Show me emails from yesterday" → Search emails with date filter
❌ "No relevant content found" → Only if vector search returns 0 results

Remember: You're a knowledge base assistant. If content exists in embeddings, help the user find and understand it. Be flexible and helpful with ANY embedded content.
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
    
    /**
     * Get all available collections from all nodes
     */
    public function getAllAvailableCollections(): array
    {
        $discoveryService = app(RAGCollectionDiscovery::class);
        return $discoveryService->discover(useCache: true, includeFederated: true);
    }
}
