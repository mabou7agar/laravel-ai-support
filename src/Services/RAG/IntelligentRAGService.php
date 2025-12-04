<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    /**
     * Process message with intelligent RAG and multi-tenant access control
     *
     * @param string $message User's message
     * @param string $sessionId Session identifier
     * @param array $availableCollections Model classes to search
     * @param array $conversationHistory Conversation history
     * @param array $options Additional options
     * @param string|int|null $userId User ID (fetched internally for access control)
     * @return AIResponse
     */
    public function processMessage(
        string $message,
        string $sessionId,
        array $availableCollections = [],
        array $conversationHistory = [],
        array $options = [],
        $userId = null
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

            // Step 2: Handle aggregate queries (count, how many, etc.)
            // Use local detection OR AI analysis (local detection is more reliable for patterns)
            $needsAggregate = $this->isAggregateQuery($message) || ($analysis['needs_aggregate'] ?? false);
            
            Log::info('Aggregate query check', [
                'message' => $message,
                'needs_aggregate' => $needsAggregate,
                'is_aggregate_local' => $this->isAggregateQuery($message),
            ]);
            
            $aggregateData = [];
            if ($needsAggregate) {
                // Always use availableCollections (discovered models) for aggregate data
                // The AI-suggested collections might not exist locally
                $aggregateData = $this->getAggregateData(
                    $availableCollections,
                    $userId
                );
                
                Log::channel('ai-engine')->info('Aggregate data retrieved', [
                    'user_id' => $userId,
                    'message' => $message,
                    'available_collections' => $availableCollections,
                    'collections' => array_keys($aggregateData),
                    'data' => $aggregateData,
                ]);
            }

            // Step 3: Retrieve context if needed (for non-aggregate or combined queries)
            $context = collect();
            if ($analysis['needs_context']) {
                // If no search queries provided, use the original message
                $searchQueries = !empty($analysis['search_queries'])
                    ? $analysis['search_queries']
                    : [$message];

                // IMPORTANT: User-passed collections should be strictly respected
                // AI can only suggest a SUBSET of what user passed, never expand beyond it
                $suggestedCollections = $analysis['collections'] ?? [];
                
                // Validate AI-suggested collections - only use ones that exist in user's passed collections
                $validCollections = array_filter($suggestedCollections, function ($collection) use ($availableCollections) {
                    return class_exists($collection) && in_array($collection, $availableCollections);
                });
                
                // If AI suggested valid subset, use it; otherwise use ALL user-passed collections
                // Never search beyond what user explicitly passed
                $collectionsToSearch = !empty($validCollections) ? $validCollections : $availableCollections;
                
                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('Collection selection', [
                        'user_passed' => $availableCollections,
                        'ai_suggested' => $suggestedCollections,
                        'valid_subset' => $validCollections,
                        'final_collections' => $collectionsToSearch,
                    ]);
                }

                $context = $this->retrieveRelevantContext(
                    $searchQueries,
                    $collectionsToSearch,
                    $options,
                    $userId
                );

                if (config('ai-engine.debug')) {
                    Log::channel('ai-engine')->debug('RAG search completed', [
                        'user_id' => $userId,
                        'search_queries' => $searchQueries,
                        'collections' => $collectionsToSearch,
                        'results_found' => $context->count(),
                    ]);
                }

                // If no results found and we didn't search all collections, try ALL collections
                if ($context->isEmpty() && count($collectionsToSearch) < count($availableCollections)) {
                    Log::channel('ai-engine')->debug('No results in selected collections, searching ALL collections', [
                        'user_id' => $userId,
                        'original_collections' => $collectionsToSearch,
                        'all_collections' => $availableCollections,
                    ]);
                    
                    $context = $this->retrieveRelevantContext(
                        $searchQueries,
                        $availableCollections,
                        $options,
                        $userId
                    );
                    
                    if (config('ai-engine.debug')) {
                        Log::channel('ai-engine')->debug('All-collections search completed', [
                            'results_found' => $context->count(),
                        ]);
                    }
                }
                
                // If still no results, try with lower threshold (0.2 default)
                $fallbackThreshold = $this->config['fallback_threshold'] ?? 0.2;
                if ($context->isEmpty() && !empty($availableCollections)) {
                    Log::channel('ai-engine')->debug('No RAG results found, retrying with lower threshold', [
                        'user_id' => $userId,
                        'fallback_threshold' => $fallbackThreshold,
                        'search_queries' => $searchQueries,
                    ]);
                    $context = $this->retrieveRelevantContext(
                        $searchQueries,
                        $availableCollections,
                        array_merge($options, ['min_score' => $fallbackThreshold]),
                        $userId
                    );

                    if (config('ai-engine.debug')) {
                        Log::channel('ai-engine')->debug('Fallback search completed', [
                            'results_found' => $context->count(),
                        ]);
                    }
                }
            }

            // Step 4: Build enhanced prompt with context and aggregate data
            $enhancedPrompt = $this->buildEnhancedPrompt(
                $message,
                $context,
                $conversationHistory,
                array_merge($options, [
                    'available_collections' => $availableCollections,
                    'aggregate_data' => $aggregateData,
                ])
            );

            // Step 4: Generate response with conversation history and user context
            $response = $this->generateResponse($enhancedPrompt, array_merge($options, [
                'conversation_history' => $conversationHistory,
                'user_id' => $userId
            ]));

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

            // Fallback to regular response without RAG but with conversation history
            return $this->generateResponse($message, array_merge($options, [
                'conversation_history' => $conversationHistory ?? [],
                'user_id' => $userId
            ]));
        } catch (\Throwable $e) {
            // Catch any remaining errors
            Log::channel('ai-engine')->error('Intelligent RAG critical error', [
                'session_id' => $sessionId,
                'message' => $message,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]);

            return $this->generateResponse($message, array_merge($options, [
                'conversation_history' => $conversationHistory ?? [],
                'user_id' => $userId
            ]));
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
                array_merge($options, ['available_collections' => $availableCollections])
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
                ->generate($enhancedPrompt);

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
        // Build available collections info with descriptions
        $collectionsInfo = '';
        if (!empty($availableCollections)) {
            $collectionsInfo = "\n\nAvailable knowledge sources (READ DESCRIPTIONS CAREFULLY):\n";
            foreach ($availableCollections as $collection) {
                $name = class_basename($collection);
                $description = '';
                
                // Try to get RAG description from the model
                if (class_exists($collection)) {
                    try {
                        $instance = new $collection();
                        if (method_exists($instance, 'getRAGDescription')) {
                            $description = $instance->getRAGDescription();
                        }
                        if (method_exists($instance, 'getRAGDisplayName')) {
                            $name = $instance->getRAGDisplayName();
                        }
                    } catch (\Exception $e) {
                        // Silently ignore if we can't instantiate
                    }
                }
                
                if (!empty($description)) {
                    $collectionsInfo .= "- **{$name}**: {$description}\n  → Use class: {$collection}\n";
                } else {
                    $collectionsInfo .= "- **{$name}**\n  → Use class: {$collection}\n";
                }
            }
        }

        $systemPrompt = <<<PROMPT
Query analyzer for knowledge base. Determine if we should search.
{$collectionsInfo}

RULES:
1. DEFAULT: needs_context: true (always search unless pure greeting)
2. Skip search ONLY for: "hi", "hello", simple math
3. ALWAYS generate semantic search terms that will match indexed content
4. ONLY use collections from the available list above - never invent collection names

RESPOND WITH JSON:
{"needs_context": true, "reasoning": "brief reason", "search_queries": ["term1", "term2", "term3"], "collections": ["FullClassName"], "query_type": "informational", "needs_aggregate": false}

QUERY TYPES:
- "aggregate" → Questions about counts, totals, statistics (e.g., "how many emails", "count my documents")
- "informational" → Questions seeking specific information
- "conversational" → General chat, greetings

CRITICAL COLLECTION SELECTION RULES:
1. READ the description of each collection carefully to select the RIGHT one
2. "accounts", "configurations", "settings" → Look for collections about ACCOUNTS/SETTINGS, not messages/content
3. "messages", "emails", "inbox", "mail content" → Look for collections about MESSAGES/CONTENT
4. When in doubt, include MULTIPLE relevant collections

CRITICAL SEARCH QUERY RULES:
1. NEVER use abstract terms like "most important" or "best" alone - they won't match content
2. For vague/subjective queries, generate MULTIPLE concrete search terms:
   - "which is most important" → ["urgent", "deadline", "action required", "priority", "reminder", "important"]
   - "what should I focus on" → ["urgent", "pending", "deadline", "todo", "action needed"]
   - "anything urgent" → ["urgent", "asap", "immediately", "deadline", "critical"]
   - "show me important stuff" → ["important", "priority", "urgent", "flagged", "starred"]

3. For email-related queries, include email-specific terms:
   - ["unread", "inbox", "reply needed", "follow up", "meeting", "reminder", "deadline"]

4. For follow-up queries ("tell me more", "that one", "this email"):
   - Extract the ACTUAL topic/subject from conversation history
   - Use specific terms from previous messages

5. Short specific queries that match items from previous response:
   - If query looks like an email subject, document title, or item name from history → Use EXACT query as search term
   - Example: User asks about "Re: Check App" after seeing email list → Search for "Re: Check App" exactly
   - Include any context identifiers (email addresses, account names) from history in search

6. Short specific queries (e.g., "Password Reset Request") → Use EXACT query

6. Technical questions → Extract key terms: ["Laravel routing", "API endpoint"]

7. Aggregate queries (how many, count, total) → Set needs_aggregate: true

8. For "accounts" queries (email accounts, user accounts, etc.):
   - Search for collections with "account" in description
   - Use search terms: ["account", "configuration", "settings", "profile"]
PROMPT;

        $conversationContext = '';
        if (!empty($conversationHistory)) {
            // Include more context for better follow-up understanding
            $recentMessages = array_slice($conversationHistory, -4);
            $conversationContext = "\n\nConversation history (for context):\n";
            foreach ($recentMessages as $m) {
                // Include more content for assistant messages (they contain the results)
                $maxLen = $m['role'] === 'assistant' ? 500 : 150;
                $preview = mb_substr($m['content'], 0, $maxLen);
                $conversationContext .= "- {$m['role']}: {$preview}" . (strlen($m['content']) > $maxLen ? '...' : '') . "\n";
            }
            $conversationContext .= "\nIMPORTANT: If user's query matches an item title/subject from the assistant's previous response, search for that EXACT item.\n";
        }

        $analysisPrompt = <<<PROMPT
{$conversationContext}

Query: "{$query}"

CRITICAL INSTRUCTIONS:
1. Generate search_queries that will SEMANTICALLY MATCH indexed content
2. For vague queries like "which is important/best/urgent" → Use MULTIPLE concrete terms:
   ["urgent", "deadline", "priority", "action required", "reminder", "meeting", "follow up"]
3. NEVER return just ["most important"] or ["best"] - these won't match anything
4. For follow-ups about specific items from history:
   - If query matches an email subject, document title, or item name from previous response → Use EXACT query
   - Example: Query "Re: Check App" after email list → search_queries: ["Re: Check App"]
   - Keep the same collection as the previous search
5. Only use collections from the available list - use FULL class name
6. Default: needs_context: true
7. If query is a short phrase that looks like a title/subject (e.g., "Re: Check App", "Password Reset") → Use it EXACTLY as search term

Respond with JSON only. Example for follow-up about specific email:
{"needs_context": true, "reasoning": "user asking about specific email from previous list", "search_queries": ["Re: Check App"], "collections": ["Bites\\\\Modules\\\\MailBox\\\\Models\\\\EmailCache"], "query_type": "informational", "needs_aggregate": false}
PROMPT;

        try {
            // Create AI request for analysis
            $analysisModel = $this->config['analysis_model'] ?? 'gpt-4o';

            $request = new AIRequest(
                prompt:       $analysisPrompt,
                engine:       new \LaravelAIEngine\Enums\EngineEnum(config('ai-engine.default')),
                model:        new \LaravelAIEngine\Enums\EntityEnum($analysisModel),
                systemPrompt: $systemPrompt,
                maxTokens:    300,
                temperature:  0.3
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
                'needs_aggregate' => $analysis['needs_aggregate'] ?? false,
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
     * Retrieve relevant context from vector database with access control
     * Uses federated search across nodes if available, otherwise local search
     *
     * @param array $searchQueries Search queries
     * @param array $collections Model classes to search
     * @param array $options Additional options
     * @param string|int|null $userId User ID (fetched internally)
     * @return Collection
     */
    protected function retrieveRelevantContext(
        array $searchQueries,
        array $collections,
        array $options = [],
        $userId = null
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

            return $this->retrieveFromFederatedSearch($searchQueries, $collections, $maxResults, $threshold, $options, $userId);
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
        return $this->retrieveFromLocalSearch($searchQueries, $validCollections, $maxResults, $threshold, $userId);
    }

    /**
     * Retrieve context using federated search across nodes
     *
     * @param array $searchQueries Search queries
     * @param array $collections Model classes
     * @param int $maxResults Maximum results
     * @param float $threshold Similarity threshold
     * @param array $options Additional options
     * @param string|int|null $userId User ID for access control
     * @return Collection
     */
    protected function retrieveFromFederatedSearch(
        array $searchQueries,
        array $collections,
        int $maxResults,
        float $threshold,
        array $options,
        $userId = null
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
                $localResults = $this->retrieveFromLocalSearch([$searchQuery], $collections, $maxResults, $threshold, $userId);
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
     * Retrieve context using local vector search with access control
     *
     * @param array $searchQueries Search queries
     * @param array $collections Model classes
     * @param int $maxResults Maximum results
     * @param float $threshold Similarity threshold
     * @param string|int|null $userId User ID for access control
     * @return Collection
     */
    protected function retrieveFromLocalSearch(
        array $searchQueries,
        array $collections,
        int $maxResults,
        float $threshold,
        $userId = null
    ): Collection {
        $allResults = collect();

        if (config('ai-engine.debug')) {
            Log::channel('ai-engine')->debug('Starting local vector search', [
                'user_id' => $userId,
                'search_queries' => $searchQueries,
                'collections' => $collections,
                'max_results' => $maxResults,
                'threshold' => $threshold,
            ]);
        }

        foreach ($searchQueries as $searchQuery) {
            foreach ($collections as $collection) {
                try {
                    // SECURITY: Pass userId for multi-tenant access control
                    $results = $this->vectorSearch->search(
                        $collection,
                        $searchQuery,
                        $maxResults,
                        $threshold,
                        [], // filters
                        $userId // CRITICAL: User ID for access control (fetched internally)
                    );

                    if (config('ai-engine.debug')) {
                        Log::channel('ai-engine')->debug('Vector search results', [
                            'collection' => $collection,
                            'query' => $searchQuery,
                            'user_id' => $userId,
                            'results_count' => $results->count(),
                            'threshold' => $threshold,
                        ]);
                    }

                    $allResults = $allResults->merge($results);
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Vector search failed', [
                        'collection' => $collection,
                        'query' => $searchQuery,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
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
     *
     * Note: This method now returns just the current message with context.
     * Conversation history is passed separately as structured messages to the AI request.
     */
    protected function buildEnhancedPrompt(
        string $message,
        Collection $context,
        array $conversationHistory = [],
        array $options = []
    ): string {
        $systemPrompt = $options['system_prompt'] ?? $this->getDefaultSystemPrompt();

        $prompt = "{$systemPrompt}\n\n";

        // Add aggregate data if available (for count/statistics queries)
        $aggregateData = $options['aggregate_data'] ?? [];
        if (!empty($aggregateData)) {
            $prompt .= "DATABASE STATISTICS (REAL-TIME FROM DATABASE):\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            foreach ($aggregateData as $modelName => $stats) {
                $displayName = $stats['display_name'] ?? $modelName;
                // Use database_count as primary, fall back to indexed_count/count
                $count = $stats['database_count'] ?? $stats['indexed_count'] ?? $stats['count'] ?? 0;
                
                if ($count > 0) {
                    $prompt .= "📊 {$displayName}: {$count} total records\n";
                    
                    if (isset($stats['recent_count']) && $stats['recent_count'] > 0) {
                        $prompt .= "   - Recent (last 7 days): {$stats['recent_count']}\n";
                    }
                    if (isset($stats['unread_count']) && $stats['unread_count'] > 0) {
                        $prompt .= "   - Unread: {$stats['unread_count']}\n";
                    }
                }
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
            // Context found - answer directly with email-aware instructions
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "- Answer directly and naturally using the context above\n";
            $prompt .= "- Cite sources: [Source 0], [Source 1]\n";
            $prompt .= "- Don't say 'based on the context' - just answer naturally\n";
            $prompt .= "- For email replies: use actual names from context (from_name, subject), NEVER use placeholders like [Recipient's Name]\n";
            $prompt .= "- User's name is Mohamed - use for signatures\n";
            $prompt .= "- When listing multiple items (emails, tasks, options), use NUMBERED LISTS (1. 2. 3.) with clear titles\n";
            $prompt .= "- Format: 1. **Title/Subject**: Brief description [Source X]\n";
        } else {
            // No KB context - but check if we have aggregate data
            $hasAggregateData = !empty($aggregateData) && collect($aggregateData)->contains(function ($stats) {
                return ($stats['database_count'] ?? $stats['count'] ?? 0) > 0;
            });
            
            if ($hasAggregateData) {
                $prompt .= "INSTRUCTIONS:\n";
                $prompt .= "- Use the DATABASE STATISTICS above to answer count/quantity questions\n";
                $prompt .= "- Answer directly with the numbers from the statistics\n";
                $prompt .= "- Be helpful and offer to provide more details if needed\n\n";
            } else {
                $prompt .= "NO KNOWLEDGE BASE RESULTS FOUND FOR THIS QUERY.\n\n";
            }
            
            // Add available collections info so AI knows what data sources exist
            $availableCollections = $options['available_collections'] ?? [];
            if (!empty($availableCollections)) {
                $prompt .= "AVAILABLE DATA SOURCES IN KNOWLEDGE BASE:\n";
                foreach ($availableCollections as $collection) {
                    $name = class_basename($collection);
                    $description = '';
                    
                    if (class_exists($collection)) {
                        try {
                            $instance = new $collection();
                            if (method_exists($instance, 'getRAGDescription')) {
                                $description = $instance->getRAGDescription();
                            }
                            if (method_exists($instance, 'getRAGDisplayName')) {
                                $name = $instance->getRAGDisplayName();
                            }
                        } catch (\Exception $e) {
                            // Silently ignore
                        }
                    }
                    
                    if (!empty($description)) {
                        $prompt .= "- {$name}: {$description}\n";
                    } else {
                        $prompt .= "- {$name}\n";
                    }
                }
                $prompt .= "\n";
                $prompt .= "NOTE: The search didn't find matching results, but these data sources exist.\n";
                $prompt .= "For aggregate queries (counts, summaries), suggest the user try a more specific search.\n\n";
            }
            
            $prompt .= "CHECK CONVERSATION HISTORY FIRST:\n";
            $prompt .= "- If this is a follow-up (e.g., 'reply to this mail', 'tell me more'), answer using conversation history\n";
            $prompt .= "- If asking about something we just discussed, answer from that context\n\n";
            $prompt .= "ONLY say 'I don't have information' if:\n";
            $prompt .= "- It's a completely NEW topic not in our conversation\n";
            $prompt .= "- It requires general knowledge (e.g., 'what can I eat')\n\n";
            $prompt .= "FOR EMAIL REPLIES - Extract from conversation:\n";
            $prompt .= "- Sender name (from_name) → Use for greeting (e.g., 'Hi John,' not 'Hi [Recipient's Name],')\n";
            $prompt .= "- Email subject → Use for 'Re: [subject]'\n";
            $prompt .= "- User's name is Mohamed → Use for signature\n";
            $prompt .= "- NEVER use placeholders - always use actual information from context\n";
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
        $userId = $options['user_id'] ?? null;

        // Build system prompt with user context
        $systemPrompt = $this->getSystemPromptWithUserContext($userId);

        $request = new AIRequest(
            prompt: $prompt,
            engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
            model: new \LaravelAIEngine\Enums\EntityEnum($model),
            temperature: $temperature,
            maxTokens: $maxTokens,
            systemPrompt: $systemPrompt
        );

        // Attach conversation history if provided
        if (!empty($options['conversation_history'])) {
            $request = $request->withMessages($options['conversation_history']);

            if (config('ai-engine.debug')) {
                Log::channel('ai-engine')->debug('Conversation history attached to RAG request', [
                    'message_count' => count($options['conversation_history']),
                ]);
            }
        }

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

            // Get display name - check for custom display name method or property
            $displayName = $this->getModelDisplayName($item, $modelClass);
            
            return [
                'id' => $item->id ?? null,
                'model_id' => $item->id ?? null,  // Original model ID
                'model_class' => $modelClass ?? 'Unknown',  // Full model class name
                'model_type' => $displayName,  // Human-readable display name
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

        // Pattern 1: Numbered lists with markdown bold (1. **Title**: Description)
        if (preg_match_all('/^\s*(\d+)\.\s+\*\*(.+?)\*\*:?\s*(.*)$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 10) as $match) {
                $number = (int) $match[1];
                $title = trim($match[2]);
                $description = trim($match[3] ?? '');
                $fullText = $title . ($description ? ': ' . $description : '');

                // Extract source reference if present
                $sourceRef = null;
                if (preg_match('/\[Source (\d+)\]/', $fullText, $sourceMatch)) {
                    $sourceRef = (int) $sourceMatch[1];
                }

                // Skip very short options
                if (strlen($title) < 3) {
                    continue;
                }

                // Generate unique ID based on number and content hash
                $uniqueId = 'opt_' . $number . '_' . substr(md5($fullText), 0, 8);

                $options[] = [
                    'id' => $uniqueId,
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $fullText,
                    'preview' => substr($title, 0, 100),
                    'source_index' => $sourceRef,
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
            }
        }

        // Pattern 2: Numbered lists with paragraphs (1. text\n\n2. text)
        if (empty($options) && preg_match_all('/(\d+)\.\s+(.+?)(?=\n\n\d+\.|\n\n[A-Z]|$)/s', $content, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 10) as $match) {
                $number = (int) $match[1];
                $fullLine = trim($match[2]);
                
                // Extract source reference before removing it
                $sourceRef = null;
                if (preg_match('/\[Source (\d+)\]/', $fullLine, $sourceMatch)) {
                    $sourceRef = (int) $sourceMatch[1];
                }
                
                // Remove [Source X] references for display
                $cleanLine = preg_replace('/\s*\[Source \d+\]\.?/', '', $fullLine);

                // Extract title - try to find quoted title or subject first
                $text = $cleanLine;
                
                // Look for quoted titles like "Frontend Developer Take-Home Task" or 'subject "Hi"'
                if (preg_match('/(?:titled|subject|called|named)\s*["\']([^"\']+)["\']/i', $cleanLine, $titleMatch)) {
                    $text = trim($titleMatch[1]);
                }
                // Look for **bold** titles
                elseif (preg_match('/\*\*([^*]+)\*\*/', $cleanLine, $titleMatch)) {
                    $text = trim($titleMatch[1]);
                }
                // Extract sender/source info as fallback
                elseif (preg_match('/(?:from|by)\s+([A-Z][a-zA-Z\s]+?)(?:\s+on\s+|\s+at\s+|,|\.|$)/i', $cleanLine, $titleMatch)) {
                    $text = 'From ' . trim($titleMatch[1]);
                }
                // Use first meaningful phrase
                else {
                    // Get first sentence or up to 80 chars
                    $firstSentence = preg_split('/[.!?]/', $cleanLine)[0] ?? $cleanLine;
                    $text = strlen($firstSentence) > 80 ? substr($firstSentence, 0, 77) . '...' : $firstSentence;
                }

                // Skip very short options
                if (strlen($text) < 3) {
                    continue;
                }

                // Generate unique ID based on number and content hash
                $uniqueId = 'opt_' . $number . '_' . substr(md5($fullLine), 0, 8);

                $options[] = [
                    'id' => $uniqueId,
                    'number' => $number,
                    'text' => $text,
                    'full_text' => substr($cleanLine, 0, 200),
                    'preview' => substr($text, 0, 100),
                    'source_index' => $sourceRef,
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
            }
        }

        // Pattern 3: Markdown bullet points with bold headers (- **Title**: Description)
        if (empty($options) && preg_match_all('/^-\s+\*\*(.+?)\*\*:?\s*(.+?)(?=\n-|\n\n|$)/ms', $content, $matches, PREG_SET_ORDER)) {
            $number = 1;
            foreach (array_slice($matches, 0, 10) as $match) {
                $title = trim($match[1]);
                $description = trim($match[2]);
                $fullText = $title . ': ' . $description;
                
                // Extract source reference if present
                $sourceRef = null;
                if (preg_match('/\[Source (\d+)\]/', $fullText, $sourceMatch)) {
                    $sourceRef = (int) $sourceMatch[1];
                }
                
                // Generate unique ID
                $uniqueId = 'opt_' . $number . '_' . substr(md5($fullText), 0, 8);

                $options[] = [
                    'id' => $uniqueId,
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $fullText,
                    'preview' => substr($title, 0, 100),
                    'source_index' => $sourceRef,
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
                $number++;
            }
        }

        // Pattern 4: Markdown headers (#### Title)
        if (empty($options) && preg_match_all('/^#{2,4}\s+(.+?)$/m', $content, $matches, PREG_SET_ORDER)) {
            $number = 1;
            foreach (array_slice($matches, 0, 10) as $match) {
                $title = trim($match[1]);

                // Skip main title or very short headers
                if (strlen($title) < 5 || stripos($title, 'title:') !== false) {
                    continue;
                }
                
                // Generate unique ID
                $uniqueId = 'opt_' . $number . '_' . substr(md5($title), 0, 8);

                $options[] = [
                    'id' => $uniqueId,
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $title,
                    'preview' => substr($title, 0, 100),
                    'source_index' => null,
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
You are an intelligent knowledge base assistant with access to a curated knowledge base powered by vector search.

🎯 YOUR ROLE:
- Help users find and understand information from the knowledge base
- Be helpful, thorough, and conversational when context is available
- Be honest when information is not in the knowledge base
- When drafting emails or replies, automatically fill in placeholders with real information from context

📚 KNOWLEDGE BASE RULES:

✅ WHEN CONTEXT IS PROVIDED (you'll see "RELEVANT CONTEXT FROM KNOWLEDGE BASE" section):
- Answer directly and naturally - just provide the information
- Be conversational and helpful - explain, elaborate, and provide examples from the context
- ALWAYS cite sources using [Source 0], [Source 1], etc.
- DON'T say "based on the context" or "according to the knowledge base" - just answer naturally
- DON'T explain what's missing - just provide what you have
- Synthesize information from multiple sources when available
- Be thorough and informative with the available information

❌ WHEN NO CONTEXT IS PROVIDED (no "RELEVANT CONTEXT" section):
- You CAN use conversation history and user context to maintain continuity
- You CAN reference previous discussions if relevant
- You CANNOT provide general knowledge or training data
- If the question needs KB content that doesn't exist: "I don't have information about [topic] in the knowledge base."
- If you can answer from conversation history, do so naturally
- Example: "what can I eat" with no KB context → "I don't have information about that in the knowledge base"
- Example: "what did we discuss earlier?" with no KB context → Use conversation history to answer

🔍 CAPABILITIES:
- Vector search across all embedded content (documents, posts, emails, files, etc.)
- Analyze uploaded files if provided
- Access files from URLs if provided
- Answer questions comprehensively when relevant context exists

💡 EXAMPLES:

✅ GOOD - Context about Laravel found (direct, natural):
"Laravel routing works by defining routes in your routes files [Source 0]. You can use Route::get(), Route::post(), and other HTTP verb methods [Source 1]. Routes can accept parameters like Route::get('/user/{id}', ...) which allows dynamic URL segments [Source 0]."

✅ GOOD - Multiple sources about emails (direct, no meta-commentary):
"You have 5 emails. The most recent ones are from John about the project deadline [Source 0] and Sarah regarding the meeting schedule [Source 1]. The others are from Mike about code review [Source 2], Lisa about the budget [Source 3], and Tom about the launch date [Source 4]."

✅ GOOD - No KB context, but can use conversation history:
User: "What did we discuss about the project earlier?"
AI: "We discussed the Frontend Developer Take-Home Task. You mentioned it involves building a responsive dashboard with React and TypeScript."

✅ GOOD - No KB context, question needs KB data:
User: "What can I eat?"
AI: "I don't have information about that in the knowledge base."

❌ WRONG - Using general knowledge when no KB context:
User: "What can I eat?"
AI: "You can eat fruits, vegetables, proteins..." ← Don't do this!

✅ GOOD - Follow-up question using conversation context:
User: "Tell me more about that"
AI: (References previous message in conversation to understand "that")

📧 EMAIL DRAFTING RULES:
When helping draft emails or replies:
1. ALWAYS replace placeholders with actual information from context:
   - [Recipient's Name] → Use sender's name from the email being replied to
   - [Your Name] → Use user's name (Mohamed in this case)
   - [Company Name] → Use actual company name from context
   - [Date/Time] → Use actual dates from context
2. Extract information from:
   - Email metadata (from_name, from_address, to_addresses)
   - Conversation history
   - Knowledge base context
3. If information is missing, use a sensible default or ask the user

EXAMPLE:
Email from: "John Smith <john@example.com>"
User asks: "how can I reply this mail"
WRONG: "Hi [Recipient's Name],"
RIGHT: "Hi John," or "Hi John Smith,"

Remember: Be helpful and conversational with knowledge base content, but strict about not using external information when no context is found.
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

    /**
     * Get system prompt with user context
     *
     * @param string|int|null $userId
     * @return string|null
     */
    protected function getSystemPromptWithUserContext($userId): ?string
    {
        if (!$userId || !config('ai-engine.inject_user_context', true)) {
            return null;
        }

        try {
            // Get user model class from config
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');

            if (!class_exists($userModel)) {
                return null;
            }

            // Fetch user with caching (5 minutes)
            $user = \Illuminate\Support\Facades\Cache::remember(
                "ai_user_context_{$userId}",
                300,
                fn() => $userModel::find($userId)
            );

            if (!$user) {
                return null;
            }

            // Build user context
            $context = "USER CONTEXT:\n";

            // User ID (always include for data searching)
            $context .= "- User ID: {$user->id}\n";

            // Name
            if (isset($user->name)) {
                $context .= "- User's name: {$user->name}\n";
            }

            // Email (always include for data searching)
            if (isset($user->email)) {
                $context .= "- Email: {$user->email}\n";
            }

            // Phone number
            if (isset($user->phone)) {
                $context .= "- Phone: {$user->phone}\n";
            } elseif (isset($user->phone_number)) {
                $context .= "- Phone: {$user->phone_number}\n";
            } elseif (isset($user->mobile)) {
                $context .= "- Phone: {$user->mobile}\n";
            }

            // Additional useful fields
            if (isset($user->username)) {
                $context .= "- Username: {$user->username}\n";
            }

            if (isset($user->first_name) && isset($user->last_name)) {
                $context .= "- Full Name: {$user->first_name} {$user->last_name}\n";
            }

            if (isset($user->title) || isset($user->job_title)) {
                $title = $user->title ?? $user->job_title;
                $context .= "- Job Title: {$title}\n";
            }

            if (isset($user->department)) {
                $context .= "- Department: {$user->department}\n";
            }

            if (isset($user->location) || isset($user->city)) {
                $location = $user->location ?? $user->city;
                $context .= "- Location: {$location}\n";
            }

            if (isset($user->timezone)) {
                $context .= "- Timezone: {$user->timezone}\n";
            }

            if (isset($user->language) || isset($user->locale)) {
                $language = $user->language ?? $user->locale;
                $context .= "- Language: {$language}\n";
            }

            // Role/Admin status
            if (isset($user->is_admin) && $user->is_admin) {
                $context .= "- Role: Administrator (has full system access)\n";
            } elseif (method_exists($user, 'getRoleNames')) {
                // Spatie Laravel Permission
                $roles = $user->getRoleNames();
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            } elseif (method_exists($user, 'roles')) {
                // Generic roles relationship
                $roles = $user->roles()->pluck('name');
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            }

            // Tenant/Organization
            if (isset($user->tenant_id)) {
                $context .= "- Organization ID: {$user->tenant_id}\n";
            } elseif (isset($user->organization_id)) {
                $context .= "- Organization ID: {$user->organization_id}\n";
            } elseif (isset($user->company_id)) {
                $context .= "- Company ID: {$user->company_id}\n";
            }

            // Custom user context (if method exists)
            if (method_exists($user, 'getAIContext')) {
                $customContext = $user->getAIContext();
                if ($customContext) {
                    $context .= $customContext . "\n";
                }
            }

            $context .= "\nIMPORTANT INSTRUCTIONS:\n";
            $context .= "- Always address the user by their name when appropriate\n";
            $context .= "- When searching for user's data, use their User ID ({$user->id}) or Email ({$user->email})\n";
            $context .= "- Personalize responses based on their role and context\n";
            $context .= "- When user asks 'my emails', 'my documents', etc., search for data belonging to User ID: {$user->id}";

            return $context;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to get user context for RAG', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get aggregate data (counts, statistics) from vector database
     *
     * @param array $collections Model classes to query
     * @param string|int|null $userId User ID for filtering (multi-tenant)
     * @return array Aggregate data by collection
     */
    protected function getAggregateData(array $collections, $userId = null): array
    {
        $aggregateData = [];

        foreach ($collections as $collection) {
            if (!class_exists($collection)) {
                continue;
            }

            try {
                $instance = new $collection();
                $name = class_basename($collection);
                $displayName = $name;
                $description = '';

                // Get display name and description
                if (method_exists($instance, 'getRAGDisplayName')) {
                    $displayName = $instance->getRAGDisplayName();
                }
                if (method_exists($instance, 'getRAGDescription')) {
                    $description = $instance->getRAGDescription();
                }

                // Build filters for vector database query
                $filters = [];
                if ($userId !== null) {
                    $filters['user_id'] = $userId;
                }

                // Get count from vector database using VectorSearchService
                $vectorCount = $this->vectorSearch->getIndexedCountWithFilters($collection, $filters);

                // Get additional stats if available
                $stats = [
                    'count' => $vectorCount,
                    'indexed_count' => $vectorCount,
                    'display_name' => $displayName,
                    'description' => $description,
                    'source' => 'vector_database',
                ];

                // Also get database count for comparison (optional)
                try {
                    $dbQuery = $collection::query();
                    $table = $instance->getTable();
                    
                    if ($userId !== null) {
                        // Check if this is a User model - filter by id instead of user_id
                        $isUserModel = $table === 'users' || str_ends_with($collection, '\\User');
                        
                        if ($isUserModel) {
                            // For User model: check if user has admin/super admin role
                            $isAdmin = $this->isUserAdmin($userId);
                            if (!$isAdmin) {
                                // Non-admin users can only see their own record
                                $dbQuery->where('id', $userId);
                            }
                            // Admin users can see all users (no filter)
                        } else {
                            // For other models: filter by user_id or similar columns
                            foreach (['user_id', 'owner_id', 'created_by', 'author_id'] as $column) {
                                if (Schema::hasColumn($table, $column)) {
                                    $dbQuery->where($column, $userId);
                                    break;
                                }
                            }
                        }
                    }
                    $stats['database_count'] = $dbQuery->count();
                    
                    // Try to get recent items count (last 7 days)
                    if (Schema::hasColumn($table, 'created_at')) {
                        $recentQuery = $collection::query();
                        if ($userId !== null) {
                            $isUserModel = $table === 'users' || str_ends_with($collection, '\\User');
                            if ($isUserModel && !$this->isUserAdmin($userId)) {
                                $recentQuery->where('id', $userId);
                            } else if (!$isUserModel) {
                                foreach (['user_id', 'owner_id', 'created_by', 'author_id'] as $column) {
                                    if (Schema::hasColumn($table, $column)) {
                                        $recentQuery->where($column, $userId);
                                        break;
                                    }
                                }
                            }
                        }
                        $stats['recent_count'] = $recentQuery->where('created_at', '>=', now()->subDays(7))->count();
                    }

                    // Try to get unread count for emails
                    if (Schema::hasColumn($table, 'is_read')) {
                        $unreadQuery = $collection::query();
                        if ($userId !== null) {
                            foreach (['user_id', 'owner_id', 'created_by', 'author_id'] as $column) {
                                if (Schema::hasColumn($table, $column)) {
                                    $unreadQuery->where($column, $userId);
                                    break;
                                }
                            }
                        }
                        $stats['unread_count'] = $unreadQuery->where('is_read', false)->count();
                    }
                } catch (\Exception $e) {
                    // Database query failed, continue with vector count only
                    Log::channel('ai-engine')->debug('Database count failed, using vector count only', [
                        'collection' => $collection,
                        'error' => $e->getMessage(),
                    ]);
                }

                $aggregateData[$name] = $stats;

            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to get aggregate data for collection', [
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $aggregateData;
    }

    /**
     * Check if query is an aggregate query (count, how many, total, etc.)
     *
     * @param string $query User's query
     * @return bool
     */
    protected function isAggregateQuery(string $query): bool
    {
        $query = strtolower($query);
        
        // Patterns that indicate aggregate queries
        $aggregatePatterns = [
            'how many',
            'how much',
            'count',
            'total',
            'number of',
            'amount of',
            'quantity',
            'statistics',
            'stats',
            'summary',
            'overview',
            'all my',
            'list all',
        ];
        
        foreach ($aggregatePatterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get human-readable display name for a model
     * 
     * Checks in order:
     * 1. getRagDisplayName() method on model
     * 2. getVectorDisplayName() method on model
     * 3. $ragDisplayName property on model
     * 4. $vectorDisplayName property on model
     * 5. Static $displayName property on model class
     * 6. Falls back to humanized class_basename
     *
     * @param mixed $item The model instance
     * @param string|null $modelClass The model class name
     * @return string Human-readable display name
     */
    protected function getModelDisplayName($item, ?string $modelClass): string
    {
        // Priority 1: Check for getRagDisplayName() method
        if (method_exists($item, 'getRagDisplayName')) {
            $displayName = $item->getRagDisplayName();
            if (!empty($displayName)) {
                return $displayName;
            }
        }
        
        // Priority 2: Check for getVectorDisplayName() method
        if (method_exists($item, 'getVectorDisplayName')) {
            $displayName = $item->getVectorDisplayName();
            if (!empty($displayName)) {
                return $displayName;
            }
        }
        
        // Priority 3: Check for $ragDisplayName property
        if (property_exists($item, 'ragDisplayName') && !empty($item->ragDisplayName)) {
            return $item->ragDisplayName;
        }
        
        // Priority 4: Check for $vectorDisplayName property
        if (property_exists($item, 'vectorDisplayName') && !empty($item->vectorDisplayName)) {
            return $item->vectorDisplayName;
        }
        
        // Priority 5: Check for static $displayName on the class
        if ($modelClass && class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->hasProperty('displayName')) {
                    $prop = $reflection->getProperty('displayName');
                    if ($prop->isStatic()) {
                        $prop->setAccessible(true);
                        $displayName = $prop->getValue();
                        if (!empty($displayName)) {
                            return $displayName;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore reflection errors
            }
        }
        
        // Priority 6: Fall back to humanized class basename
        if ($modelClass) {
            $basename = class_basename($modelClass);
            // Convert CamelCase to words (e.g., "EmailCache" -> "Email Cache")
            return preg_replace('/(?<!^)([A-Z])/', ' $1', $basename);
        }
        
        return 'Unknown';
    }

    /**
     * Check if user has admin or super admin role
     *
     * @param string|int $userId User ID to check
     * @return bool True if user is admin/super admin
     */
    protected function isUserAdmin($userId): bool
    {
        try {
            // Get user model class from config or use default
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');
            
            if (!class_exists($userModel)) {
                return false;
            }
            
            $user = $userModel::find($userId);
            
            if (!$user) {
                return false;
            }
            
            // Check for is_admin flag
            if (isset($user->is_admin) && $user->is_admin) {
                return true;
            }
            
            // Check for is_super_admin flag
            if (isset($user->is_super_admin) && $user->is_super_admin) {
                return true;
            }
            
            // Check for Spatie Laravel Permission roles
            if (method_exists($user, 'hasRole')) {
                if ($user->hasRole(['admin', 'super-admin', 'super_admin', 'superadmin', 'administrator'])) {
                    return true;
                }
            }
            
            // Check for roles relationship
            if (method_exists($user, 'roles')) {
                $adminRoles = ['admin', 'super-admin', 'super_admin', 'superadmin', 'administrator'];
                $userRoles = $user->roles()->pluck('name')->map(fn($r) => strtolower($r))->toArray();
                
                if (count(array_intersect($adminRoles, $userRoles)) > 0) {
                    return true;
                }
            }
            
            // Check for role column
            if (isset($user->role)) {
                $role = strtolower($user->role);
                if (in_array($role, ['admin', 'super-admin', 'super_admin', 'superadmin', 'administrator'])) {
                    return true;
                }
            }
            
            return false;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to check user admin status', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
