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
    protected array $historyConfig;

    protected $nodeRegistry = null;
    protected $federatedSearch = null;
    protected $nodeRouter = null;
    protected ?AutonomousRAGAnalyzer $autonomousAnalyzer = null;

    public function __construct(
        VectorSearchService $vectorSearch,
        AIEngineManager $aiEngine,
        ConversationService $conversationService
    ) {
        $this->vectorSearch = $vectorSearch;
        $this->aiEngine = $aiEngine;
        $this->conversationService = $conversationService;
        $this->config = config('ai-engine.intelligent_rag', []);
        $this->historyConfig = config('ai-engine.conversation_history', []);

        // Initialize autonomous analyzer
        $this->autonomousAnalyzer = new AutonomousRAGAnalyzer($aiEngine);

        // Lazy load node services if available
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            $this->nodeRegistry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
        }
        if (class_exists(\LaravelAIEngine\Services\Node\FederatedSearchService::class)) {
            $this->federatedSearch = app(\LaravelAIEngine\Services\Node\FederatedSearchService::class);
        }
        if (class_exists(\LaravelAIEngine\Services\Node\NodeRouterService::class)) {
            $this->nodeRouter = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);
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
            // Load conversation history
            if (empty($conversationHistory)) {
                $conversationHistory = $this->loadConversationHistory($sessionId);
            }

            // Optimize history: sliding window (keep only recent messages)
            if ($this->historyConfig['enabled'] ?? true) {
                $maxMessages = $this->historyConfig['recent_messages'] ?? 10;

                if (count($conversationHistory) > $maxMessages) {
                    $conversationHistory = array_slice($conversationHistory, -$maxMessages);

                    Log::channel('ai-engine')->debug('Conversation history optimized (sliding window)', [
                        'total_messages' => count($conversationHistory),
                        'kept_recent' => $maxMessages,
                    ]);
                }
            }

            // Check if intelligent mode is enabled (default: true)
            $useIntelligent = $options['intelligent'] ?? true;
            $intentAnalysis = $options['intent_analysis'] ?? null;
            
            // FAST PATH: For pure aggregate queries, skip analysis and go straight to aggregate
            $isPure = $this->isPureAggregateQuery($message);
            $hasCollections = !empty($availableCollections);
            
            Log::info('RAG Fast path check', [
                'isPureAggregateQuery' => $isPure,
                'hasCollections' => $hasCollections,
                'collections_count' => count($availableCollections),
            ]);
            
            if ($isPure && $hasCollections) {
                $aggregateData = $this->getSmartAggregateData($availableCollections, $message, $userId);
                
                Log::info('RAG Fast path aggregate data', [
                    'data_empty' => empty($aggregateData),
                    'keys' => array_keys($aggregateData),
                ]);
                
                if (!empty($aggregateData)) {
                    Log::channel('ai-engine')->info('Fast path: Pure aggregate query - RETURNING', [
                        'message' => $message,
                        'collections' => array_keys($aggregateData),
                    ]);
                    return $this->generateAggregateResponse($message, $aggregateData, $options);
                }
            }

            // Step 1: Analyze if query needs context retrieval
            if ($intentAnalysis && isset($intentAnalysis['intent'])) {
                // Heuristic mapping from Intent Analysis to RAG Analysis
                $isRetrieval = in_array($intentAnalysis['intent'], ['retrieval', 'question']);
                $searchQueries = [$message];

                // If it's a retrieval intent, we might have specific entity data we can use
                // but simpler for now to just pass it through as needs_context=true
                $analysis = [
                    'needs_context' => $isRetrieval || $intentAnalysis['intent'] === 'question',
                    'reasoning' => $intentAnalysis['context_enhancement'] ?? 'Intent analysis indicates retrieval/question',
                    'search_queries' => $searchQueries,
                    'collections' => $availableCollections, // Trust default or refine later
                    'query_type' => $intentAnalysis['intent'] === 'question' ? 'conversational' : 'informational',
                ];

                Log::channel('ai-engine')->debug('Skipped RAG analysis (used Intent Analysis)', [
                    'intent' => $intentAnalysis['intent'],
                    'analysis' => $analysis
                ]);
            } else {
                $analysis = $useIntelligent
                    ? $this->analyzeQuery($message, $conversationHistory, $availableCollections)
                    : ['needs_context' => true, 'search_queries' => [$message], 'collections' => $availableCollections];
            }

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
                // Let AI extract filters from the query, then use hybrid vector+SQL
                $aggregateData = $this->getSmartAggregateData(
                    $availableCollections,
                    $message,
                    $userId
                );
                
                Log::channel('ai-engine')->info('Smart aggregate data retrieved', [
                    'user_id' => $userId,
                    'message' => $message,
                    'collections' => array_keys($aggregateData),
                ]);
                
                // For pure aggregate queries (count/how many), skip context retrieval
                // Just answer directly with the aggregate data
                if ($this->isPureAggregateQuery($message) && !empty($aggregateData)) {
                    return $this->generateAggregateResponse($message, $aggregateData, $options);
                }
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
                $validCollections = []; // Initialize for debug logging

                // If user passed collections, validate AI suggestions against them
                // If using auto-discovery (empty availableCollections), trust AI's selection
                if (!empty($availableCollections)) {
                    // When federated search is enabled, skip class_exists check
                    // Child nodes will validate if they have the class
                    $useFederatedSearch = $this->federatedSearch && config('ai-engine.nodes.enabled', false);

                    if ($useFederatedSearch) {
                        // For federated search, only check if collection is in user's passed list
                        $validCollections = array_filter($suggestedCollections, function ($collection) use ($availableCollections) {
                            return in_array($collection, $availableCollections);
                        });
                    } else {
                        // For local search only, validate collections exist locally
                        $validCollections = array_filter($suggestedCollections, function ($collection) use ($availableCollections) {
                            return class_exists($collection) && in_array($collection, $availableCollections);
                        });
                    }

                    // If AI suggested valid subset, use it; otherwise use ALL user-passed collections
                    // Never search beyond what user explicitly passed
                    $collectionsToSearch = !empty($validCollections) ? $validCollections : $availableCollections;
                } else {
                    // Auto-discovery mode: trust AI's selection from discovered collections
                    $useFederatedSearch = $this->federatedSearch && config('ai-engine.nodes.enabled', false);

                    if ($useFederatedSearch) {
                        // For federated search, accept all suggested collections
                        $collectionsToSearch = $suggestedCollections;
                    } else {
                        // For local search, validate collections exist locally
                        $collectionsToSearch = array_filter($suggestedCollections, function ($collection) {
                            return class_exists($collection);
                        });
                    }

                    // If AI didn't suggest any, use all discovered collections
                    if (empty($collectionsToSearch)) {
                        $collectionsToSearch = $availableCollections;
                    }
                }

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

                // Always log context results for debugging federated search issues
                Log::channel('ai-engine')->info('RAG context retrieved', [
                    'user_id' => $userId,
                    'search_queries' => $searchQueries,
                    'collections' => $collectionsToSearch,
                    'results_found' => $context->count(),
                    'first_result' => $context->isNotEmpty() ? [
                        'id' => $context->first()->id ?? null,
                        'has_content' => isset($context->first()->content),
                        'has_title' => isset($context->first()->title),
                        'has_name' => isset($context->first()->name),
                        'vector_score' => $context->first()->vector_score ?? null,
                    ] : null,
                ]);

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
                    'search_instructions' => $options['search_instructions'] ?? null,
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

            // Detect and extract actions from response
            $content = $response->getContent();
            $actions = $this->extractActions($content);
            if (!empty($actions)) {
                $metadata['actions'] = $actions;

                // Remove ACTION lines from content so they don't show to user
                $cleanContent = preg_replace('/ACTION:[A-Z_]+\|[^\n]+\n?/i', '', $content);
                $response = new AIResponse(
                    content: trim($cleanContent),
                    engine: $response->getEngine(),
                    model: $response->getModel(),
                    metadata: $response->getMetadata(),
                    tokensUsed: $response->getTokensUsed(),
                    creditsUsed: $response->getCreditsUsed(),
                    latency: $response->getLatency(),
                    requestId: $response->getRequestId(),
                    conversationId: $response->getConversationId()
                );

                Log::channel('ai-engine')->info('Actions detected in response', [
                    'session_id' => $sessionId,
                    'actions' => $actions,
                ]);
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
     * @param string|int|null $userId User ID for access control
     * @return array Legacy format for backward compatibility
     */
    public function processMessageStream(
        string $message,
        string $sessionId,
        callable $callback,
        array $availableCollections = [],
        array $conversationHistory = [],
        array $options = [],
        $userId = null
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
                    $options,
                    $userId
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
            $responseModel = $options['model'] ?? $this->config['response_model'] ?? config('ai-engine.default_model', 'gpt-4o');

            $generator = $this->aiEngine
                ->engine($options['engine'] ?? config('ai-engine.default'))
                ->model($responseModel)
                ->generateStream($enhancedPrompt);

            foreach ($generator as $chunk) {
                $fullResponse .= $chunk;
                if ($callback && is_callable($callback)) {
                    $callback($chunk);
                }
            }

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
        // Use autonomous analyzer if available
        if ($this->autonomousAnalyzer && config('ai-engine.intelligent_rag.use_autonomous_analyzer', true)) {
            Log::channel('ai-engine')->debug('Using autonomous RAG analyzer', ['query' => $query]);
            
            $analysis = $this->autonomousAnalyzer->analyze($query, $conversationHistory, $availableCollections);
            
            // Cache the result
            $cacheKey = 'rag_analysis_' . md5($query . implode(',', array_keys($availableCollections)));
            cache()->put($cacheKey, $analysis, 300);
            
            return $analysis;
        }

        // Fallback to rule-based analysis
        // 1. HEURISTICS: Quick check for simple messages to skip AI analysis
        if ($this->shouldSkipAnalysis($query)) {
            Log::channel('ai-engine')->debug('Skipping RAG analysis due to heuristics', ['query' => $query]);
            return [
                'needs_context' => false,
                'reasoning' => 'Skipped via heuristics (greeting/short message)',
                'search_queries' => [$query],
                'collections' => $availableCollections,
                'query_type' => 'conversational',
                'needs_aggregate' => false,
            ];
        }

        // 2. CACHING: Check if we analyzed this exact query recently
        $cacheKey = 'rag_analysis_' . md5($query . implode(',', array_keys($availableCollections)));
        $cachedAnalysis = cache()->get($cacheKey);

        if ($cachedAnalysis) {
            Log::channel('ai-engine')->debug('Using cached RAG analysis', ['query' => $query]);
            return $cachedAnalysis;
        }

        // Build available collections info with descriptions
        $collectionsInfo = '';

        // Check if federated search is enabled and we should discover remote collections
        $useFederatedSearch = $this->federatedSearch && config('ai-engine.nodes.enabled', false);
        $discoveredCollections = [];

        if ($useFederatedSearch && empty($availableCollections)) {
            // Discover collections from all nodes with their RAG descriptions
            try {
                $discoveryService = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
                $collections = $discoveryService->discoverWithDescriptions(useCache: true);

                if (!empty($collections)) {
                    $collectionsInfo = "\n\nAvailable knowledge sources across all nodes (READ DESCRIPTIONS CAREFULLY):\n";

                    foreach ($collections as $collection) {
                        $name = $collection['display_name'];
                        $description = $collection['description'];
                        $nodeCount = count($collection['nodes']);
                        $nodeNames = array_map(fn($n) => $n['node_name'], $collection['nodes']);

                        // Store discovered collection class for actual searching
                        $discoveredCollections[] = $collection['class'];

                        if (!empty($description)) {
                            $collectionsInfo .= "- **{$name}**: {$description}\n";
                            $collectionsInfo .= "  → Use class: {$collection['class']}\n";
                            $collectionsInfo .= "  → Available on {$nodeCount} node(s): " . implode(', ', $nodeNames) . "\n";
                        } else {
                            $collectionsInfo .= "- **{$name}**\n";
                            $collectionsInfo .= "  → Use class: {$collection['class']}\n";
                            $collectionsInfo .= "  → Available on {$nodeCount} node(s): " . implode(', ', $nodeNames) . "\n";
                        }
                    }

                    // Use discovered collections as availableCollections for searching
                    $availableCollections = $discoveredCollections;
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to discover remote collections', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If no federated discovery or availableCollections provided, use local collections
        if (empty($collectionsInfo) && !empty($availableCollections)) {
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
2. Skip search ONLY for: "hi", "hello", "thanks", simple math, pure acknowledgments
3. ALWAYS generate semantic search terms that will match indexed content
4. ONLY use collections from the available list above - never invent collection names
5. CRITICAL: Queries like "tell me about X", "more info on X", "details about X" → ALWAYS needs_context: true, search for X
6. If query mentions ANY specific item/product/topic name → needs_context: true

TIME & DATE AWARENESS:
1. If query mentions relative time ("last week", "yesterday", "recent"), add specific time-based terms to search_queries
   - "emails from last week" → ["emails", "created:2024-03", "sort:recent"] (use current year/month)
2. Always prefer RECENT and RELEVANT content

SYNONYM EXPANSION:
1. If query uses vague terms ("issues", "problems"), add specific synonyms ("bugs", "errors", "failures") to search_queries
2. If query is technical ("server is down"), add related technical terms ("500 error", "uptime", "crash")

RESPOND WITH JSON ONLY:
{"needs_context": true, "reasoning": "brief reason", "search_queries": ["term1", "term2", "term3"], "collections": ["Full\\\\Namespace\\\\ClassName"], "query_type": "informational", "needs_aggregate": false}

CRITICAL: Always use FULL class paths with namespace (e.g., "Workdo\\\\ProductService\\\\Entities\\\\ProductService", NOT just "ProductService")

QUERY TYPES:
- "aggregate" → Questions about counts, totals, statistics (e.g., "how many emails", "count my documents")
- "informational" → Questions seeking specific information
- "conversational" → General chat, greetings

CRITICAL COLLECTION SELECTION RULES:
1. MATCH query keywords to collection descriptions - read descriptions carefully
2. Look for keyword overlap between query and collection description
3. Collections may exist on different nodes - select based on description match, not node location
4. If query mentions specific data type, find collections whose description mentions that type
5. If multiple collections could match, include ALL relevant ones from ALL nodes
6. BE DECISIVE: If collection description clearly matches query intent, SELECT IT regardless of which node it's on
7. BE SELECTIVE: Only include collections that are RELEVANT to the query - do NOT include all collections
8. If uncertain which collection to use, select the 2-3 most likely matches based on description
9. Examples:
   - Query mentions "X" + Collection description contains "X" → SELECT IT
   - Query about data type A + Collection description mentions type A → SELECT IT
   - Query about data type A → Do NOT select collections for unrelated types B, C, D

CRITICAL SEARCH QUERY RULES:
1. NEVER use abstract terms like "most important" or "best" alone - they won't match content
2. For vague/subjective queries, generate MULTIPLE concrete search terms:
   - "which is most important" → ["urgent", "deadline", "action required", "priority", "reminder", "important"]
   - "what should I focus on" → ["urgent", "pending", "deadline", "todo", "action needed"]
   - "anything urgent" → ["urgent", "asap", "immediately", "deadline", "critical"]
   - "show me important stuff" → ["important", "priority", "urgent", "flagged", "starred"]

3. For email-related queries, include email-specific terms:
   - ["unread", "inbox", "reply needed", "follow up", "meeting", "reminder", "deadline"]

4. For queries asking about specific items (with or without conversation history):
   - If user asks "tell me about X", "more info on X", "details about X" → Extract X and use as EXACT search query
   - Example: "tell me about Sprinkler Heads" → Search for "Sprinkler Heads" exactly
   - Example: "more info on Gardening" → Search for "Gardening" exactly
   - If conversation history shows X was in previous results, keep the SAME collection
   - If no history, let collection selection rules determine appropriate collection based on X

5. For numbered references (e.g., "tell me about #2", "more on option 3"):
   - Look at previous assistant response for numbered items
   - Extract the title/name of that numbered item
   - Use exact title as search query
   - Example: Previous showed "2. Sprinkler Heads" → User asks "tell me about #2" → Search for "Sprinkler Heads"

6. Short specific queries that match items from previous response:
   - If query looks like a product name, email subject, or document title from history → Use EXACT query as search term
   - Include any context identifiers (IDs, names) from history in search

7. Technical questions → Extract key terms: ["Laravel routing", "API endpoint"]

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
            $conversationContext .= "\nCRITICAL FOLLOW-UP RULES:\n";
            $conversationContext .= "1. If user asks about a specific item/product name from previous response → Use EXACT name as search query\n";
            $conversationContext .= "2. If user uses numbered reference (#1, #2, option 3) → Extract item name from that number in previous response\n";
            $conversationContext .= "3. If user asks 'tell me more', 'details', 'info about that' → Look for item name in their message or previous context\n";
            $conversationContext .= "4. Keep the SAME collection as the previous search for follow-up queries\n";
            $conversationContext .= "5. CONTINUATION QUERIES: If user says 'more', 'next', 'continue', 'show more' → Repeat the SAME search from previous query\n";
            $conversationContext .= "   - Extract the search terms from the previous user query\n";
            $conversationContext .= "   - Use the SAME collections from the previous search\n";
            $conversationContext .= "   - Example: Previous query 'latest invoices' → User says 'more' → Search for 'invoices' with same collection\n";
        }

        $analysisPrompt = <<<PROMPT
{$conversationContext}

Query: "{$query}"

CRITICAL INSTRUCTIONS:
1. MATCH query keywords to collection descriptions:
   - "articles", "posts", "blog", "tutorials" in query → Select collections with these in description
   - "Laravel articles" → Look for collection mentioning "articles", "blog posts", "Laravel", "tutorials"
   - Example: "Blog Posts & Articles" collection with description "Blog posts and articles about Laravel, PHP..." → SELECT THIS for Laravel article queries
2. If query looks like an EMAIL SUBJECT or DOCUMENT TITLE (e.g., "Undelivered Mail Returned to Sender", "Re: Check App", "Password Reset"):
   → Use ONLY that EXACT phrase as search_queries: ["Undelivered Mail Returned to Sender"]
   → Do NOT expand or rephrase it - the user wants that specific item
3. ENTITY & ID EXTRACTION:
   - If query contains specific IDs (e.g., "Invoice #INV-2024-001"), use the ID as a search term.
   - If query mentions full names ("John Doe"), use the full name as a search term.
4. SPELLING CORRECTION:
   - If a term looks misspelled (e.g., "Laraval"), include both the original AND the corrected term in search_queries.
   - Example: "Laraval routing" → ["Laraval routing", "Laravel routing"]
5. For vague queries like "which is important/best/urgent" → Use MULTIPLE concrete terms:
   ["urgent", "deadline", "priority", "action required", "reminder", "meeting", "follow up"]
6. NEVER return just ["most important"] or ["best"] - these won't match anything
7. For follow-ups about specific items from history:
   - If query matches an email subject, document title, or item name from previous response → Use EXACT query
   - Keep the same collection as the previous search
8. Only use collections from the available list - use FULL class name (e.g., "App\\\\Models\\\\Post")
9. Default: needs_context: true
10. PERFORMANCE: Prefer 1-2 search queries over many. More queries = slower response
11. BE DECISIVE: If collection description clearly matches query intent, SELECT IT - don't be overly conservative

Respond with JSON only. Example for follow-up about specific email:
{"needs_context": true, "reasoning": "user asking about specific email from previous list", "search_queries": ["Re: Check App"], "collections": ["Bites\\\\Modules\\\\MailBox\\\\Models\\\\EmailCache"], "query_type": "informational", "needs_aggregate": false}
PROMPT;

        try {
            // Get analysis model from config, prioritize fast model (gpt-4o-mini)
            $analysisModel = $this->config['analysis_model']
                ?? config('ai-engine.vector.rag.analysis_model', 'gpt-4o-mini');

            Log::channel('ai-engine')->debug('Query analysis starting', [
                'model' => $analysisModel ?? 'default',
                'query' => $query,
            ]);

            // Use the driver's generateJsonAnalysis which handles all model-specific logic
            $driver = $this->aiEngine->getEngineDriver(
                new \LaravelAIEngine\Enums\EngineEnum(config('ai-engine.default', 'openai'))
            );

            $response = $driver->generateJsonAnalysis(
                prompt: $analysisPrompt,
                systemPrompt: $systemPrompt,
                model: $analysisModel,
                maxTokens: 300
            );

            // Handle empty response - default to context search
            if (empty(trim($response))) {
                Log::channel('ai-engine')->warning('Query analysis returned empty response', [
                    'query' => $query,
                    'model' => $analysisModel ?? 'default',
                ]);
                return [
                    'needs_context' => true,
                    'reasoning' => 'Empty analysis response, using default search',
                    'search_queries' => [$query],
                    'collections' => $availableCollections,
                    'query_type' => 'informational',
                    'needs_aggregate' => false,
                ];
            }

            // Parse JSON response
            $analysis = $this->parseJsonResponse($response);

            // Handle empty arrays - use defaults when arrays are empty or null
            $searchQueries = $analysis['search_queries'] ?? null;
            if (empty($searchQueries)) {
                $searchQueries = [$query];
            }

            $collections = $analysis['collections'] ?? null;
            if (empty($collections)) {
                // Don't default to all collections - log warning and use empty array
                Log::channel('ai-engine')->warning('AI did not select any collections for query', [
                    'query' => $query,
                    'available_collections' => count($availableCollections),
                ]);
                // Use a reasonable subset if available (first 3 collections as fallback)
                $collections = array_slice($availableCollections, 0, 3);
            }

            $result = [
                'needs_context' => $analysis['needs_context'] ?? false,
                'reasoning' => $analysis['reasoning'] ?? '',
                'search_queries' => $searchQueries,
                'collections' => $collections,
                'query_type' => $analysis['query_type'] ?? 'conversational',
                'needs_aggregate' => $analysis['needs_aggregate'] ?? false,
            ];

            // CACHING: Store result for 1 hour
            cache()->put($cacheKey, $result, now()->addHour());

            Log::channel('ai-engine')->info('Query analysis result', [
                'query' => $query,
                'needs_context' => $result['needs_context'],
                'collections_count' => count($result['collections']),
                'collections' => $result['collections'], // Log full class paths, not basenames
                'search_queries' => $result['search_queries'],
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Query analysis failed, defaulting to context search', [
                'error' => $e->getMessage(),
                'query' => $query,
            ]);

            // Default to using context search if analysis fails (safer fallback)
            return [
                'needs_context' => true,
                'reasoning' => 'Analysis failed, using default search',
                'search_queries' => [$query],
                'collections' => $availableCollections,
                'query_type' => 'informational',
                'needs_aggregate' => false,
            ];
        }
    }

    /**
     * Check if query should skip analysis based on heuristics
     */
    protected function shouldSkipAnalysis(string $query): bool
    {
        $queryLower = strtolower(trim($query));

        // Skip short greetings
        if (in_array($queryLower, ['hi', 'hello', 'hey', 'greetings', 'yo'])) {
            return true;
        }

        // Skip clear commands (that are likely handled by IntentAnalysisService anyway, but filtering here saves an LLM call)
        // If it starts with precise action commands, we don't need RAG context analysis usually
        if (preg_match('/^(create|delete|remove|clear)\s+/i', $queryLower)) {
            // Note: We might still want context for 'create', but usually 'new_request' intent handles it without RAG
            // Exception: 'create based on X' might need RAG.
            // Conservative heuristic: only skip single-word greetings for now to be safe.
            return false;
        }

        return false;
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
     * Returns the main subjects discussed based on user queries
     */
    protected function extractTopicsFromConversation(array $conversationHistory): array
    {
        $topics = [];

        foreach ($conversationHistory as $msg) {
            // Only extract from user messages
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }

            $content = trim($msg['content'] ?? '');

            // Skip short/empty messages
            if (strlen($content) < 5) {
                continue;
            }

            if (!in_array($content, $topics)) {
                $topics[] = $content;
            }
        }

        // Return last 3 topics (most recent)
        return array_slice($topics, -3);
    }

    /**
     * Extract actions from AI response
     * Format: ACTION:TYPE|param1=value1|param2=value2
     */
    protected function extractActions(string $content): array
    {
        $actions = [];

        // Match ACTION:TYPE|param=value|param=value
        if (preg_match_all('/ACTION:([A-Z_]+)\|([^\n]+)/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = strtoupper($match[1]);
                $paramsString = $match[2];

                // Parse parameters
                $params = [];
                $paramPairs = explode('|', $paramsString);
                foreach ($paramPairs as $pair) {
                    if (strpos($pair, '=') !== false) {
                        [$key, $value] = explode('=', $pair, 2);
                        $params[trim($key)] = trim($value);
                    }
                }

                $actions[] = [
                    'type' => $type,
                    'params' => $params,
                    'raw' => $match[0],
                ];
            }
        }

        return $actions;
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

2. **Intent Evolution & Topic Drift**: Detect when user's intent changes
   - "Tell me about Laravel" → "Show me books" = Same topic (Laravel), different intent -> **Stay on same node(s)**
   - "Tell me about Laravel" → "What's the weather?" = Topic change -> **RESET context, select new node**
   - If the new query is completely unrelated to the previous one, ignore the previous node selection.

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

        // Check if nodes are enabled
        $nodesEnabled = config('ai-engine.nodes.enabled', false);
        $searchMode = config('ai-engine.nodes.search_mode', 'routing');
        
        // Use routing mode (simple) or federated mode (complex)
        if ($nodesEnabled && $this->nodeRouter && $searchMode === 'routing') {
            // Simple routing: route to single node based on collections
            return $this->retrieveWithRouting($searchQueries, $collections, $maxResults, $threshold, $options, $userId);
        }
        
        // Use federated search if available and enabled (legacy/complex mode)
        $useFederatedSearch = $this->federatedSearch && $nodesEnabled && $searchMode === 'federated';

        Log::channel('ai-engine')->info('retrieveRelevantContext decision', [
            'nodes_enabled' => $nodesEnabled,
            'search_mode' => $searchMode,
            'useFederatedSearch' => $useFederatedSearch,
            'collections_count' => count($collections),
        ]);

        if ($useFederatedSearch) {
            // For federated search, we trust the collections array
            // The child nodes will validate if they have the class
            Log::channel('ai-engine')->info('Using federated search - delegating collection validation to nodes', [
                'collections' => $collections,
                'note' => 'Collections may exist on remote nodes even if not available locally',
            ]);

            return $this->retrieveFromFederatedSearch($searchQueries, $collections, $maxResults, $threshold, $options, $userId);
        }

        // For local search only, validate collections exist locally
        $validCollections = array_filter($collections, function ($collection) {
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
     * Retrieve context using simple routing (route to single node)
     * 
     * This is simpler and faster than federated search - routes the request
     * to the appropriate node based on collections instead of searching all nodes.
     */
    protected function retrieveWithRouting(
        array $searchQueries,
        array $collections,
        int $maxResults,
        float $threshold,
        array $options,
        $userId = null
    ): Collection {
        // Determine which node should handle this request
        $routing = $this->nodeRouter->route(implode(' ', $searchQueries), $collections, $options);
        
        Log::channel('ai-engine')->info('Node routing decision', [
            'is_local' => $routing['is_local'],
            'node' => $routing['node']?->slug ?? 'local',
            'reason' => $routing['reason'],
        ]);
        
        // If local, use local search
        if ($routing['is_local']) {
            // Validate collections exist locally
            $validCollections = array_filter($collections, function ($collection) {
                return class_exists($collection);
            });
            
            if (empty($validCollections)) {
                return collect();
            }
            
            return $this->retrieveFromLocalSearch($searchQueries, $validCollections, $maxResults, $threshold, $userId);
        }
        
        // Route to remote node
        $node = $routing['node'];
        $allResults = collect();
        
        foreach ($searchQueries as $searchQuery) {
            $response = $this->nodeRouter->forwardSearch(
                $node,
                $searchQuery,
                $collections,
                $maxResults,
                array_merge($options, ['threshold' => $threshold]),
                $userId
            );
            
            if ($response['success'] && !empty($response['results'])) {
                // Convert remote results to collection format
                foreach ($response['results'] as $result) {
                    $allResults->push((object) [
                        'id' => $result['id'] ?? null,
                        'model_id' => $result['model_id'] ?? $result['id'] ?? null,
                        'content' => $result['content'] ?? '',
                        'vector_score' => $result['score'] ?? 0,
                        'vector_metadata' => array_merge($result['metadata'] ?? [], [
                            'source_node' => $node->slug,
                            'source_node_name' => $node->name,
                        ]),
                        'source_node' => $node->slug,
                        'source_node_name' => $node->name,
                    ]);
                }
            } else if (!$response['success']) {
                // Fallback to local search if remote fails
                Log::channel('ai-engine')->warning('Remote node failed, falling back to local', [
                    'node' => $node->slug,
                    'error' => $response['error'] ?? 'Unknown error',
                ]);
                
                $validCollections = array_filter($collections, fn($c) => class_exists($c));
                if (!empty($validCollections)) {
                    return $this->retrieveFromLocalSearch($searchQueries, $validCollections, $maxResults, $threshold, $userId);
                }
            }
        }
        
        return $allResults->sortByDesc('vector_score')->take($maxResults);
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
                // Use federated search across all nodes or specific nodes if provided
                $nodeIds = $options['node_ids'] ?? null;

                $federatedResults = $this->federatedSearch->search(
                    query: $searchQuery,
                    nodeIds: $nodeIds, // Use specific nodes if provided, otherwise auto-select
                    limit: $maxResults,
                    options: array_merge($options, [
                        'collections' => $collections,
                        'threshold' => $threshold,
                    ]),
                    userId: $userId
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

                        // CRITICAL: Preserve source node attribution from federated search
                        if (isset($result['source_node'])) {
                            $obj->source_node = $result['source_node'];
                        }
                        if (isset($result['source_node_name'])) {
                            $obj->source_node_name = $result['source_node_name'];
                        }

                        $allResults->push($obj);
                    }
                }

                // If federated search returned no results, try database fallback for each collection
                if (empty($federatedResults['results']) || count($federatedResults['results']) === 0) {
                    \Log::warning('🔄 FEDERATED SEARCH EMPTY - TRYING DATABASE FALLBACK', [
                        'query' => $searchQuery,
                        'collections_count' => count($collections),
                    ]);

                    foreach ($collections as $collection) {
                        try {
                            $dbResults = $this->fallbackDatabaseSearch($collection, $searchQuery, $maxResults, $userId);
                            if ($dbResults->isNotEmpty()) {
                                Log::channel('ai-engine')->info('Database fallback successful in federated path', [
                                    'collection' => $collection,
                                    'results_count' => $dbResults->count(),
                                ]);
                                $allResults = $allResults->merge($dbResults);
                            }
                        } catch (\Exception $dbError) {
                            Log::channel('ai-engine')->warning('Database fallback failed in federated path', [
                                'collection' => $collection,
                                'error' => $dbError->getMessage(),
                            ]);
                        }
                    }
                }

                Log::channel('ai-engine')->info('Federated search completed', [
                    'query' => $searchQuery,
                    'nodes_searched' => $federatedResults['nodes_searched'] ?? 0,
                    'total_results' => $federatedResults['total_results'] ?? 0,
                    'allResults_count_so_far' => $allResults->count(),
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
        $finalResults = $allResults
            ->unique('id')
            ->sortByDesc('vector_score')
            ->take($maxResults);

        Log::channel('ai-engine')->info('retrieveFromFederatedSearch final results', [
            'allResults_before_dedup' => $allResults->count(),
            'finalResults_after_dedup' => $finalResults->count(),
            'first_result_id' => $finalResults->isNotEmpty() ? ($finalResults->first()->id ?? 'no id') : 'empty',
        ]);

        return $finalResults;
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

                    // If vector search returned no results, optionally try database fallback
                    // Database fallback is disabled by default to prevent memory issues
                    $enableDbFallback = config('ai-engine.intelligent_rag.enable_database_fallback', false);
                    
                    if ($enableDbFallback && ($results->isEmpty() || $results->count() === 0)) {
                        Log::channel('ai-engine')->info('Vector search returned no results, trying database fallback', [
                            'collection' => $collection,
                            'query' => $searchQuery,
                        ]);

                        try {
                            $dbResults = $this->fallbackDatabaseSearch($collection, $searchQuery, $maxResults, $userId);
                            if ($dbResults->isNotEmpty()) {
                                Log::channel('ai-engine')->info('Database fallback successful', [
                                    'collection' => $collection,
                                    'results_count' => $dbResults->count(),
                                ]);
                                $results = $dbResults;
                            }
                        } catch (\Exception $dbError) {
                            Log::channel('ai-engine')->warning('Database fallback failed', [
                                'collection' => $collection,
                                'error' => $dbError->getMessage(),
                            ]);
                        }
                    }

                    $allResults = $allResults->merge($results);
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Vector search failed', [
                        'collection' => $collection,
                        'query' => $searchQuery,
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);

                    // Optionally fallback to local database query (disabled by default)
                    if ($enableDbFallback) {
                        try {
                            $dbResults = $this->fallbackDatabaseSearch($collection, $searchQuery, $maxResults, $userId);
                            if ($dbResults->isNotEmpty()) {
                                Log::channel('ai-engine')->info('Database fallback successful', [
                                    'collection' => $collection,
                                    'results_count' => $dbResults->count(),
                                ]);
                                $allResults = $allResults->merge($dbResults);
                            }
                        } catch (\Exception $dbError) {
                            Log::channel('ai-engine')->error('Database fallback also failed', [
                                'collection' => $collection,
                                'error' => $dbError->getMessage(),
                            ]);
                        }
                    }
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

        // Collect search instructions from models and merge with API-level instructions
        $modelInstructions = null;
        $availableCollections = $options['available_collections'] ?? [];
        if (!empty($availableCollections)) {
            $modelInstructions = $this->collectModelSearchInstructions($availableCollections);
        }

        $apiInstructions = $options['search_instructions'] ?? null;

        // Merge model-level and API-level instructions
        $combinedInstructions = [];
        if (!empty($modelInstructions)) {
            $combinedInstructions[] = $modelInstructions;
        }
        if (!empty($apiInstructions)) {
            $combinedInstructions[] = $apiInstructions;
        }

        if (!empty($combinedInstructions)) {
            $prompt .= "SPECIAL INSTRUCTIONS FOR THIS SEARCH:\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
            $prompt .= implode("\n\n", $combinedInstructions) . "\n";
            $prompt .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        }

        if ($context->isNotEmpty()) {
            // Context found - answer directly with email-aware instructions
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "- Answer directly and naturally using the context above\n";
            $prompt .= "- Cite sources: [Source 0], [Source 1]\n";
            $prompt .= "- Don't say 'based on the context' - just answer naturally\n";
            $prompt .= "- For email replies: use actual names from context (from_name, subject), NEVER use placeholders like [Recipient's Name]\n";
            $prompt .= "- User's name is Mohamed - use for signatures\n";
            $prompt .= "- When listing multiple items (emails, tasks, options), use NUMBERED LISTS (1. 2. 3.) with FULL DETAILS\n";
            $prompt .= "- Format: 1. **Subject**: From [sender], Date [date], Preview: [first 100 chars] [Source X]\n";
            $prompt .= "- Include ALL relevant details in the list (subject, sender, date, preview) - don't just show titles\n";
            $prompt .= "- CRITICAL: If user responds with JUST a number (e.g., '1'), they want the FULL email content\n";
            $prompt .= "- Look at your previous response, find item #N, extract its subject/title, and show the COMPLETE details from the context above\n";
            $prompt .= "- Don't ask what they want - directly show the full email content with all fields (from, to, subject, body, date)\n\n";

            $prompt .= "EMAIL REPLY WORKFLOW:\n";
            $prompt .= "- When user asks to 'reply' or 'suggest a reply' to an email, draft a professional response\n";
            $prompt .= "- Include: To, Subject (Re: original), and full message body\n";
            $prompt .= "- After showing the draft, ask: 'Would you like me to send this reply? (yes/no)'\n";
            $prompt .= "- If user confirms (yes/send/ok), respond with: ACTION:SEND_EMAIL with the email details\n";
            $prompt .= "- Format: ACTION:SEND_EMAIL|to=email@example.com|subject=Re: Subject|body=Message body here\n";
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
            $toList = array_map(function ($addr) {
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
     * Extract content from model (with truncation for performance)
     */
    protected function extractContent($model): string
    {
        // Max characters per context item to prevent huge prompts
        $maxLength = $this->config['max_context_item_length']
            ?? config('ai-engine.vector.rag.max_context_item_length', 2000);

        $content = '';

        if (method_exists($model, 'getVectorContent')) {
            $content = $model->getVectorContent();
        } else {
            $fields = ['content', 'body', 'description', 'text', 'title', 'name'];
            $parts = [];

            foreach ($fields as $field) {
                if (isset($model->$field)) {
                    $parts[] = $model->$field;
                }
            }

            $content = implode(' ', $parts);
        }

        // Truncate if too long to prevent slow API calls
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . '... [truncated]';
        }

        return $content;
    }

    /**
     * Generate AI response
     */
    protected function generateResponse(string $prompt, array $options = []): AIResponse
    {
        $engine = $options['engine'] ?? config('ai-engine.default');
        $model = $options['model'] ?? $this->config['response_model'] ?? config('ai-engine.default_model', 'gpt-4o');
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

            // Extract source node information from federated search results
            $sourceNode = $item->source_node ?? null;
            $sourceNodeName = $item->source_node_name ?? null;

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
                'source_node' => $sourceNode,  // Node slug (e.g., 'dash', 'inbusiness')
                'source_node_name' => $sourceNodeName,  // Node display name (e.g., 'Dash', 'InBusiness')
                'score' => $item->vector_score ?? $item->score ?? null,  // Include score for debugging
            ];
        })->toArray();

        // Detect numbered options in the response
        $responseContent = $response->getContent();
        $numberedOptions = $this->extractNumberedOptions($responseContent);

        // Strip the OPTIONS_JSON block from content (user shouldn't see it)
        $cleanContent = preg_replace('/\s*<!--OPTIONS_JSON.*?OPTIONS_JSON-->\s*/s', '', $responseContent);

        // Create new response with enriched metadata
        return new AIResponse(
            content: trim($cleanContent),
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
     * Parses OPTIONS_JSON block if present, falls back to markdown parsing
     */
    protected function extractNumberedOptions(string $content): array
    {
        // Try to extract JSON block first
        if (preg_match('/<!--OPTIONS_JSON\s*(.*?)\s*OPTIONS_JSON-->/s', $content, $match)) {
            $json = trim($match[1]);
            $parsed = json_decode($json, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
                $options = [];
                foreach ($parsed as $item) {
                    $number = $item['number'] ?? count($options) + 1;
                    $title = $item['title'] ?? '';
                    $description = $item['description'] ?? '';

                    $options[] = [
                        'id' => 'opt_' . $number . '_' . substr(md5($title), 0, 8),
                        'number' => $number,
                        'text' => $title,
                        'full_text' => $title . ($description ? ' - ' . $description : ''),
                        'preview' => substr($title, 0, 100),
                        'source_index' => $item['source'] ?? null,
                        'clickable' => true,
                        'action' => 'select_option',
                        'value' => (string) $number,
                    ];
                }
                return $options;
            }
        }

        // Fallback: parse markdown numbered list (1. **Title** - Description)
        $options = [];
        preg_match_all('/^\s*\d+\.\s+/m', $content, $numberedItems);
        if (count($numberedItems[0]) < 2) {
            return [];
        }

        if (preg_match_all('/^\s*(\d+)\.\s+\*\*(.+?)\*\*[\s:\-]*(.*)$/m', $content, $matches, PREG_SET_ORDER)) {
            foreach (array_slice($matches, 0, 10) as $match) {
                $number = (int) $match[1];
                $title = trim($match[2]);
                $description = trim($match[3] ?? '');

                $sourceRef = null;
                if (preg_match('/\[Source (\d+)\]/', $description, $sourceMatch)) {
                    $sourceRef = (int) $sourceMatch[1];
                }

                $options[] = [
                    'id' => 'opt_' . $number . '_' . substr(md5($title), 0, 8),
                    'number' => $number,
                    'text' => $title,
                    'full_text' => $title . ($description ? ' - ' . $description : ''),
                    'preview' => substr($title, 0, 100),
                    'source_index' => $sourceRef,
                    'clickable' => true,
                    'action' => 'select_option',
                    'value' => (string) $number,
                ];
            }
        }

        return $options;
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
            $conversationResult = null;
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
        $projectContext = $this->buildProjectContextSection();

        $basePrompt = "You are an intelligent knowledge base assistant with access to a curated knowledge base powered by vector search.";

        // Inject project context if available
        if (!empty($projectContext)) {
            $basePrompt .= "\n\n" . $projectContext;
        }

        $restOfPrompt = <<<'PROMPT'

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
- **NO HALLUCINATION**: Do not invent emails, documents, or data that are not in the context.
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
"Laravel routing works by defining routes in your routes files. You can use Route::get(), Route::post(), and other HTTP verb methods. Routes can accept parameters like Route::get('/user/{id}', ...) which allows dynamic URL segments."

✅ GOOD - Multiple sources about emails (direct, no meta-commentary):
"You have 5 emails. The most recent ones are from John about the project deadline and Sarah regarding the meeting schedule. The others are from Mike about code review, Lisa about the budget, and Tom about the launch date."

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
1. **Tone**: Use a professional, approachable, and clear tone. Avoid overly formal or robotic language.
2. ALWAYS replace placeholders with actual information from context:
   - [Recipient's Name] → Use sender's name from the email being replied to
   - [Your Name] → Use user's name (Mohamed in this case)
   - [Company Name] → Use actual company name from context
   - [Date/Time] → Use actual dates from context
3. Extract information from:
   - Email metadata (from_name, from_address, to_addresses)
   - Conversation history
   - Knowledge base context
4. If information is missing, use a sensible default or ask the user

EXAMPLE:
Email from: "John Smith <john@example.com>"
User asks: "how can I reply this mail"
WRONG: "Hi [Recipient's Name],"
RIGHT: "Hi John," or "Hi John Smith,"

📋 LIST/OPTIONS FORMATTING:
When presenting multiple items (2+) that the user can select from, include a JSON block at the END of your response:

1. Write your natural response text first (intro + numbered list for readability)
2. Do NOT include [Source X] references in the text - sources are tracked internally
3. At the very end, add a JSON block with the options data

FORMAT:
```
Your natural response here with numbered items for readability...

1. **Title One** - Description
2. **Title Two** - Description

<!--OPTIONS_JSON
[
  {"number": 1, "title": "Title One", "description": "Description", "source": 0},
  {"number": 2, "title": "Title Two", "description": "Description", "source": 1}
]
OPTIONS_JSON-->
```

RULES:
- Only include OPTIONS_JSON when there are 2+ selectable items
- Never include [Source X] in the visible text - keep source references only in the JSON block
- Do NOT include OPTIONS_JSON for single item details or general responses
- The JSON must be valid and match the numbered items in your response

Remember: Be helpful and conversational with knowledge base content, but strict about not using external information when no context is found.
PROMPT;

        return $basePrompt . $restOfPrompt;
    }

    /**
     * Build project context section for system prompt
     *
     * This injects application-specific context to help the AI understand
     * the domain and make better decisions.
     */
    protected function buildProjectContextSection(): string
    {
        $config = config('ai-engine.project_context', []);

        // Check if project context is enabled
        if (!($config['enabled'] ?? true)) {
            return '';
        }

        $sections = [];

        // Main description
        $description = $config['description'] ?? '';
        if (!empty($description)) {
            $sections[] = "📋 APPLICATION CONTEXT:\n{$description}";
        }

        // Industry/domain
        $industry = $config['industry'] ?? '';
        if (!empty($industry)) {
            $sections[] = "🏢 Industry/Domain: {$industry}";
        }

        // Target users
        $targetUsers = $config['target_users'] ?? '';
        if (!empty($targetUsers)) {
            $sections[] = "👥 Target Users: {$targetUsers}";
        }

        // Key entities
        $keyEntities = $config['key_entities'] ?? [];
        if (!empty($keyEntities)) {
            $entitiesList = implode(', ', $keyEntities);
            $sections[] = "📊 Key Entities: {$entitiesList}";
        }

        // Business rules
        $businessRules = $config['business_rules'] ?? [];
        if (!empty($businessRules)) {
            $rulesList = array_map(fn($rule) => "  • {$rule}", $businessRules);
            $sections[] = "📜 Business Rules:\n" . implode("\n", $rulesList);
        }

        // Terminology
        $terminology = $config['terminology'] ?? [];
        if (!empty($terminology)) {
            $termsList = [];
            foreach ($terminology as $term => $definition) {
                $termsList[] = "  • {$term}: {$definition}";
            }
            $sections[] = "📖 Domain Terminology:\n" . implode("\n", $termsList);
        }

        // Data sensitivity
        $dataSensitivity = $config['data_sensitivity'] ?? 'internal';
        if (!empty($dataSensitivity) && $dataSensitivity !== 'public') {
            $sensitivityNote = match ($dataSensitivity) {
                'confidential' => '⚠️ Data Sensitivity: CONFIDENTIAL - Handle all data with strict confidentiality',
                'restricted' => '🔒 Data Sensitivity: RESTRICTED - Highly sensitive data, maximum security required',
                'internal' => '🔐 Data Sensitivity: INTERNAL - Data is for internal use only',
                default => '',
            };
            if (!empty($sensitivityNote)) {
                $sections[] = $sensitivityNote;
            }
        }

        // Additional context
        $additionalContext = $config['additional_context'] ?? '';
        if (!empty($additionalContext)) {
            $sections[] = "ℹ️ Additional Context:\n{$additionalContext}";
        }

        if (empty($sections)) {
            return '';
        }

        return "🏗️ PROJECT CONTEXT\n" .
            "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n" .
            implode("\n\n", $sections) .
            "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━";
    }

    /**
     * Parse JSON response from AI
     */
    protected function parseJsonResponse(string $response): array
    {
        $originalResponse = $response;
        // Extract JSON from response (handle markdown code blocks)
        $response = preg_replace('/```json\s*|\s*```/', '', $response);
        $response = trim($response);

        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::channel('ai-engine')->warning('Failed to parse JSON response', [
                'response' => $originalResponse,
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
        // Use federated search for aggregate if enabled
        $useFederatedSearch = $this->federatedSearch && config('ai-engine.nodes.enabled', false);

        if ($useFederatedSearch) {
            Log::channel('ai-engine')->info('Using federated aggregate data', [
                'collections_count' => count($collections),
                'user_id' => $userId,
            ]);
            return $this->federatedSearch->getAggregateData($collections, $userId);
        }

        // Otherwise use local only
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
     * Check if query is a pure aggregate query (just wants count, no details)
     */
    protected function isPureAggregateQuery(string $query): bool
    {
        $query = strtolower($query);
        $purePatterns = ['how many', 'count', 'total', 'number of'];
        
        foreach ($purePatterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Generate a direct response for aggregate queries (fast path)
     */
    protected function generateAggregateResponse(string $message, array $aggregateData, array $options = []): AIResponse
    {
        // Build response from aggregate data
        $parts = [];
        foreach ($aggregateData as $name => $data) {
            $count = $data['count'] ?? 0;
            $displayName = $data['display_name'] ?? $name;
            $filters = $data['filters_applied'] ?? [];
            
            if ($count > 0) {
                $filterStr = "";
                if (!empty($filters['created_at'])) {
                    $dateFilter = $filters['created_at'];
                    if (is_array($dateFilter)) {
                        $filterStr = " from {$dateFilter['gte']} to {$dateFilter['lte']}";
                    } else {
                        $filterStr = " on {$dateFilter}";
                    }
                }
                $parts[] = "**{$count}** {$displayName}(s){$filterStr}";
            }
        }
        
        $response = !empty($parts) 
            ? "Based on your data, there are:\n- " . implode("\n- ", $parts)
            : "No matching records found for your query.";
        
        return new AIResponse(
            content: $response,
            engine: \LaravelAIEngine\Enums\EngineEnum::OPENAI,
            model: \LaravelAIEngine\Enums\EntityEnum::GPT_4O_MINI,
            metadata: ['aggregate_data' => $aggregateData, 'fast_path' => true]
        );
    }
    
    /**
     * Smart aggregate: Use AI to extract filters from query, then hybrid vector+SQL
     * 
     * @param array $collections Available collections
     * @param string $query User's natural language query
     * @param string|int|null $userId User ID for filtering
     * @return array Aggregate data
     */
    public function getSmartAggregateData(array $collections, string $query, $userId = null): array
    {
        // Ask AI to extract filters from the query
        $filters = $this->extractFiltersWithAI($query);
        
        Log::channel('ai-engine')->info('Smart aggregate - AI extracted filters', [
            'query' => $query,
            'filters' => $filters,
        ]);
        
        $aggregateData = [];
        
        foreach ($collections as $collection) {
            if (!class_exists($collection)) {
                continue;
            }
            
            try {
                $instance = new $collection();
                $name = class_basename($collection);
                $displayName = method_exists($instance, 'getRAGDisplayName') 
                    ? $instance->getRAGDisplayName() 
                    : $name;
                
                // Build filters: user_id + AI-extracted filters
                $queryFilters = $userId !== null ? ['user_id' => $userId] : [];
                $queryFilters = array_merge($queryFilters, $filters);
                
                // Use hybrid vector+SQL count
                $count = $this->vectorSearch->countWithFilters($collection, $queryFilters);
                
                $aggregateData[$name] = [
                    'count' => $count,
                    'display_name' => $displayName,
                    'filters_applied' => $filters,
                ];
                
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Smart aggregate failed for collection', [
                    'collection' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        return $aggregateData;
    }
    
    /**
     * Extract date filters from query using pattern matching (fast, no AI call)
     * 
     * @param string $query User's query
     * @return array Filters array for vector search
     */
    protected function extractFiltersWithAI(string $query): array
    {
        $queryLower = strtolower($query);
        
        // Month + Year pattern (e.g., "January 2026", "jan 2026")
        $months = [
            'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2,
            'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
            'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
            'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9,
            'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];
        
        foreach ($months as $name => $num) {
            if (preg_match("/{$name}\s+(\d{4})/i", $query, $m)) {
                $year = $m[1];
                $days = cal_days_in_month(CAL_GREGORIAN, $num, (int)$year);
                $month = str_pad($num, 2, '0', STR_PAD_LEFT);
                return ['created_at' => ['gte' => "{$year}-{$month}-01", 'lte' => "{$year}-{$month}-{$days}"]];
            }
        }
        
        // Relative dates
        if (str_contains($queryLower, 'today')) {
            return ['created_at' => now()->format('Y-m-d')];
        }
        if (str_contains($queryLower, 'yesterday')) {
            return ['created_at' => now()->subDay()->format('Y-m-d')];
        }
        if (str_contains($queryLower, 'this week')) {
            return ['created_at' => ['gte' => now()->startOfWeek()->format('Y-m-d'), 'lte' => now()->endOfWeek()->format('Y-m-d')]];
        }
        if (str_contains($queryLower, 'last week')) {
            return ['created_at' => ['gte' => now()->subWeek()->startOfWeek()->format('Y-m-d'), 'lte' => now()->subWeek()->endOfWeek()->format('Y-m-d')]];
        }
        if (str_contains($queryLower, 'this month')) {
            return ['created_at' => ['gte' => now()->startOfMonth()->format('Y-m-d'), 'lte' => now()->endOfMonth()->format('Y-m-d')]];
        }
        if (str_contains($queryLower, 'last month')) {
            return ['created_at' => ['gte' => now()->subMonth()->startOfMonth()->format('Y-m-d'), 'lte' => now()->subMonth()->endOfMonth()->format('Y-m-d')]];
        }
        
        return [];
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
     * Collect search instructions from models being searched
     *
     * @param array $collections Array of model class names
     * @return string|null Combined search instructions from all models
     */
    protected function collectModelSearchInstructions(array $collections): ?string
    {
        $instructions = [];

        foreach ($collections as $collection) {
            if (!class_exists($collection)) {
                continue;
            }

            try {
                // Check if model has getRagSearchInstructions method
                if (method_exists($collection, 'getRagSearchInstructions')) {
                    $modelInstructions = $collection::getRagSearchInstructions();
                    if (!empty($modelInstructions)) {
                        $modelName = class_basename($collection);
                        $instructions[] = "For {$modelName}: {$modelInstructions}";
                    }
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to get search instructions from model', [
                    'model' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return !empty($instructions) ? implode("\n", $instructions) : null;
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

    /**
     * Fallback to local database search when vector search fails
     *
     * @param string $collection Model class name
     * @param string $query Search query
     * @param int $maxResults Maximum results to return
     * @param mixed $userId User ID for access control
     * @return \Illuminate\Support\Collection
     */
    /**
     * Cache for schema column checks to prevent memory leaks
     */
    protected static array $schemaCache = [];

    protected function fallbackDatabaseSearch(string $collection, string $query, int $maxResults = 5, $userId = null, ?array $messageAnalysis = null): \Illuminate\Support\Collection
    {
        \Log::info('🔍 fallbackDatabaseSearch START', [
            'collection' => $collection,
            'query' => $query,
            'maxResults' => $maxResults,
            'userId' => $userId,
        ]);

        if (!class_exists($collection)) {
            \Log::warning('fallbackDatabaseSearch: class does not exist', ['collection' => $collection]);
            return collect([]);
        }

        try {
            $model = new $collection();
            $table = $model->getTable();
            \Log::info('fallbackDatabaseSearch: model instantiated', ['table' => $table]);
            $queryBuilder = $collection::query();

            // Try to get filters from AI config first
            $filtersApplied = false;
            if (method_exists($model, 'initializeAI')) {
                try {
                    $aiConfig = $model->initializeAI();

                    // Check if model has filters defined in AI config
                    if (isset($aiConfig['filters']) && is_callable($aiConfig['filters'])) {
                        $queryBuilder = call_user_func($aiConfig['filters'], $queryBuilder);
                        $filtersApplied = true;
                        \Log::info('fallbackDatabaseSearch: applied AI config filters', ['model' => $collection]);
                    }
                } catch (\Exception $e) {
                    \Log::warning('fallbackDatabaseSearch: failed to apply AI config filters', [
                        'model' => $collection,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Fallback to default filters if AI config filters not applied
            if (!$filtersApplied) {
                // Apply user_id filter if model has the column
                $userIdCacheKey = $table . '.user_id';

                if (!isset(self::$schemaCache[$userIdCacheKey])) {
                    self::$schemaCache[$userIdCacheKey] = \Illuminate\Support\Facades\Schema::hasColumn($table, 'user_id');
                }

                if ($userId && self::$schemaCache[$userIdCacheKey]) {
                    $queryBuilder->where('user_id', $userId);
                }
            }

            // Search in searchable fields if query is meaningful
            $searchableFields = $this->getSearchableFields($model);

            // Use pre-analyzed message intent if provided, otherwise analyze
            $shouldApplyTextSearch = true;
            
            if ($messageAnalysis !== null) {
                // Use pre-analyzed intent (already analyzed by AgentOrchestrator/ChatService)
                if (in_array($messageAnalysis['type'] ?? '', ['simple_answer', 'conversational']) && 
                    ($messageAnalysis['confidence'] ?? 0) > 0.7) {
                    $shouldApplyTextSearch = false;
                    \Log::info('fallbackDatabaseSearch: using pre-analyzed intent', [
                        'query' => $query,
                        'type' => $messageAnalysis['type'],
                        'confidence' => $messageAnalysis['confidence']
                    ]);
                }
            }

            if (!empty($searchableFields) && $shouldApplyTextSearch && strlen($query) > 2) {
                $queryBuilder->where(function ($q) use ($searchableFields, $query) {
                    foreach ($searchableFields as $field) {
                        $q->orWhere($field, 'LIKE', "%{$query}%");
                    }
                });
            }

            // Get results ordered by most recent with strict limit
            // Use a hard limit to prevent memory issues even if maxResults is high
            $hardLimit = min($maxResults, config('ai-engine.intelligent_rag.database_fallback_limit', 20));
            
            $results = $queryBuilder
                ->orderBy('created_at', 'desc')
                ->limit($hardLimit)
                ->get();

            // Transform to match vector search result format (must return objects, not arrays)
            return $results->map(function ($item) {
                // Use getVectorContent if available for consistent content
                $content = method_exists($item, 'getVectorContent')
                    ? $item->getVectorContent()
                    : (method_exists($item, 'toSearchableArray')
                        ? json_encode($item->toSearchableArray())
                        : json_encode($item->only($item->getFillable())));

                // Return as object to match vector search format
                // Only include essential metadata to prevent memory issues
                return (object) [
                    'id' => $item->id,
                    'model_type' => get_class($item),
                    'model_id' => $item->id,
                    'content' => $content,
                    'vector_score' => 0.5, // Default score for database results
                    'metadata' => [
                        'id' => $item->id,
                        'model_class' => get_class($item),
                    ],
                ];
            });

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Database fallback search failed', [
                'collection' => $collection,
                'error' => $e->getMessage(),
            ]);
            return collect([]);
        }
    }

    /**
     * Get searchable fields from model's AI config
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @return array
     */
    protected function getSearchableFields($model): array
    {
        // Try to get from AI config first
        if (method_exists($model, 'initializeAI')) {
            try {
                $aiConfig = $model->initializeAI();

                // Extract searchable fields from entity field configs
                $searchableFields = [];

                // Check for entity fields with searchFields defined
                if (isset($aiConfig['entities'])) {
                    foreach ($aiConfig['entities'] as $entityConfig) {
                        if (isset($entityConfig['searchFields']) && is_array($entityConfig['searchFields'])) {
                            $searchableFields = array_merge($searchableFields, $entityConfig['searchFields']);
                        }
                    }
                }

                // Also check regular fields
                if (isset($aiConfig['fields'])) {
                    foreach ($aiConfig['fields'] as $fieldName => $fieldConfig) {
                        // Add fields that are likely searchable (strings, text)
                        if (is_array($fieldConfig) && isset($fieldConfig['type'])) {
                            if (in_array($fieldConfig['type'], ['string', 'text'])) {
                                $searchableFields[] = $fieldName;
                            }
                        }
                    }
                }

                if (!empty($searchableFields)) {
                    return array_unique($searchableFields);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Could not extract searchable fields from AI config', [
                    'model' => get_class($model),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: Check if model has explicit getSearchableFields method
        if (method_exists($model, 'getSearchableFields')) {
            return $model->getSearchableFields();
        }

        // Last resort: Check for common searchable fields
        $commonFields = ['name', 'title', 'description', 'email', 'content', 'body', 'invoice_id', 'status'];
        $table = $model->getTable();
        $existingFields = [];

        foreach ($commonFields as $field) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($table, $field)) {
                $existingFields[] = $field;
            }
        }

        return $existingFields;
    }

}
