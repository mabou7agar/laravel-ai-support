<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Models\AINode;

/**
 * Autonomous RAG Agent - Full AI Decision Maker
 *
 * No hardcoded rules or patterns. AI decides everything:
 * - Which tool to use (db_query, vector_search, answer_from_context, count)
 * - When to use DB vs RAG (based on model capabilities)
 * - How to format the response
 *
 * Uses RAGCollectionDiscovery to find available models dynamically.
 */
class AutonomousRAGAgent
{
    protected AIEngineManager $ai;
    protected ?IntelligentRAGService $ragService;
    protected ?RAGCollectionDiscovery $discovery;

    public function __construct(
        AIEngineManager $ai,
        ?IntelligentRAGService $ragService = null,
        ?RAGCollectionDiscovery $discovery = null
    ) {
        $this->ai = $ai;
        $this->ragService = $ragService;
        $this->discovery = $discovery ?? (app()->bound(RAGCollectionDiscovery::class) ? app(
            RAGCollectionDiscovery::class
        ) : null);
    }

    /**
     * Process a message - AI decides everything
     */
    public function process(
        string $message,
        string $sessionId,
        $userId = null,
        array $conversationHistory = [],
        array $options = []
    ): array {
        Log::channel('ai-engine')->info('Autonomus RAG User ID:' . $userId);
        $startTime = microtime(true);

        // Load query state from cache to get last entity list for context
        if ($sessionId && !isset($options['last_entity_list'])) {
            $queryState = Cache::get("rag_query_state:{$sessionId}");
            if ($queryState) {
                $options['last_entity_list'] = [
                    'entity_type' => $queryState['model'] ?? 'item',
                    'entity_data' => $queryState['entity_data'] ?? [],
                    'entity_ids' => $queryState['entity_ids'] ?? [],
                    'start_position' => $queryState['start_position'] ?? 1,
                    'end_position' => $queryState['end_position'] ?? count($queryState['entity_ids'] ?? []),
                    'current_page' => $queryState['current_page'] ?? 1,
                ];
            }
        }

        // Build context for AI with available tools and models
        $context = $this->buildAIContext($message, $conversationHistory, $userId, $options);

        // Detect if we should use function calling (OpenAI) or prompt-based routing
        $model = $options['model'] ?? 'gpt-4o-mini';
        $useOpenAIFunctions = false;

        if ($useOpenAIFunctions) {
            // Use OpenAI function calling - AI has direct access to all tools
            return $this->processWithFunctionCalling(
                $message,
                $sessionId,
                $userId,
                $conversationHistory,
                $context,
                $options,
                $model
            );
        } else {
            // Use prompt-based routing for other providers
            $decision = $this->getAIDecision($message, $context, $model);

            Log::channel('ai-engine')->info('AutonomousRAGAgent: AI decision', [
                'session_id' => $sessionId,
                'user_id' => $userId,
                'tool' => $decision['tool'] ?? 'unknown',
                'reasoning' => $decision['reasoning'] ?? '',
                'duration_ms' => round((microtime(true) - $startTime) * 1000),
            ]);

            // Execute the tool AI chose
            return $this->executeTool($decision, $message, $sessionId, $userId, $conversationHistory, $options);
        }
    }

    /**
     * Check if model supports OpenAI function calling
     */
    protected function supportsOpenAIFunctions(string $model): bool
    {
        // OpenAI models that support function calling
        $openAIModels = ['gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'];

        foreach ($openAIModels as $openAIModel) {
            if (stripos($model, $openAIModel) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Process with OpenAI function calling - AI has direct access to all tools
     */
    protected function processWithFunctionCalling(
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $context,
        array $options,
        string $model
    ): array {
        $startTime = microtime(true);

        // Build function definitions from available tools
        $functions = $this->buildFunctionDefinitions($context, $userId, $options);

        // Build conversation messages
        $messages = $this->buildConversationMessages($message, $conversationHistory, $context);

        try {
            // Build AIRequest with function calling
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $message,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                userId: $userId,
                messages: $messages,
                metadata: ['session_id' => $sessionId],
                functions: $functions,
                functionCall: ['name' => 'auto']
            );

            $response = $this->ai->processRequest($request);

            $content = $response->getContent();
            $functionCall = $response->getFunctionCall();

            // If AI wants to call a function
            if ($functionCall) {
                $functionName = $functionCall['name'] ?? null;
                $functionArgs = json_decode($functionCall['arguments'] ?? '{}', true);

                Log::channel('ai-engine')->info('AutonomousRAGAgent: Function call', [
                    'session_id' => $sessionId,
                    'user_id' => $userId,
                    'function' => $functionName,
                    'args' => $functionArgs,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                ]);

                // Execute the function
                return $this->executeFunction(
                    $functionName,
                    $functionArgs,
                    $userId,
                    $sessionId,
                    $conversationHistory,
                    $options
                );
            }

            // No function call - return AI response
            return [
                'success' => true,
                'response' => $content,
                'tool' => 'direct_response',
                'fast_path' => true,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Function calling failed', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
            ]);

            // Fallback to prompt-based routing
            $decision = $this->getAIDecision($message, $context, $model);

            return $this->executeTool($decision, $message, $sessionId, $userId, $conversationHistory, $options);
        }
    }

    /**
     * Build function definitions from available tools
     */
    protected function buildFunctionDefinitions(array $context, $userId, array $options): array
    {
        $functions = [];

        // Add model tools (find_customer, update_invoice, etc.)
        foreach ($context['models'] as $model) {
            if (empty($model['tools'])) {
                continue;
            }

            foreach ($model['tools'] as $toolName => $tool) {
                $functions[] = [
                    'name' => $toolName,
                    'description' => $tool['description'] ?? "Tool for {$toolName}",
                    'parameters' => $this->convertToolParametersToJsonSchema($tool['parameters'] ?? []),
                ];
            }
        }

        return $functions;
    }

    /**
     * Convert tool parameters to JSON schema format
     */
    protected function convertToolParametersToJsonSchema(array $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $description) {
            // Parse parameter format: "required|string - Description"
            $parts = explode(' - ', $description);
            $rules = explode('|', $parts[0] ?? '');
            $desc = $parts[1] ?? '';

            $isRequired = in_array('required', $rules);
            $type = 'string'; // default

            foreach ($rules as $rule) {
                if (in_array($rule, ['string', 'integer', 'number', 'boolean', 'array', 'object'])) {
                    $type = $rule;
                    break;
                }
            }

            $properties[$name] = [
                'type' => $type,
                'description' => $desc,
            ];

            if ($isRequired) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    /**
     * Build conversation messages for OpenAI
     */
    protected function buildConversationMessages(string $message, array $conversationHistory, array $context): array
    {
        $messages = [];

        // System message with context
        $systemPrompt = "You are an intelligent assistant with access to tools. Use the available tools to accomplish the user's goals.";

        if (!empty($context['last_entity_list']['entity_data'])) {
            $entityType = $context['last_entity_list']['entity_type'] ?? 'item';
            $entityData = $context['last_entity_list']['entity_data'];
            $systemPrompt .= "\n\nPrevious results ({$entityType}):\n" . json_encode($entityData, JSON_PRETTY_PRINT);
        }

        if (!empty($context['selected_entity']) && is_array($context['selected_entity'])) {
            $systemPrompt .= "\n\nCurrent selected entity:\n" . json_encode(
                $context['selected_entity'],
                JSON_PRETTY_PRINT
            );
        }

        $messages[] = ['role' => 'system', 'content' => $systemPrompt];

        // Add conversation history
        foreach ($conversationHistory as $turn) {
            $messages[] = [
                'role' => $turn['role'] ?? 'user',
                'content' => $turn['content'] ?? '',
            ];
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    /**
     * Execute a function called by AI
     */
    protected function executeFunction(
        string $functionName,
        array $args,
        $userId,
        string $sessionId,
        array $conversationHistory,
        array $options
    ): array {
        // Map function name to tool execution
        // For model tools, extract model name and tool name
        $decision = [
            'tool' => 'model_tool',
            'parameters' => [
                'tool_name' => $functionName,
                'tool_params' => $args,
            ],
        ];

        return $this->executeTool($decision, '', $sessionId, $userId, $conversationHistory, $options);
    }

    /**
     * Build context for AI with all available tools and models
     */
    protected function buildAIContext(string $message, array $conversationHistory, $userId, array $options): array
    {
        // Get available models with their capabilities
        $models = $this->getAvailableModels($options);

        // Get available nodes
        $nodes = $this->getAvailableNodes();

        // Summarize conversation
        $conversationSummary = $this->summarizeConversation($conversationHistory);

        return [
            'conversation' => $conversationSummary,
            'models' => $models,
            'nodes' => $nodes,
            'user_id' => $userId,
            'is_master' => config('ai-engine.nodes.is_master', true),
            'last_entity_list' => $options['last_entity_list'] ?? null,
            'selected_entity' => $options['selected_entity'] ?? null,
        ];
    }

    /**
     * Get available models with their capabilities (DB, RAG, etc.)
     */
    protected function getAvailableModels(array $options): array
    {
        $collections = $options['rag_collections'] ?? [];

        // Discover if not provided
        if (empty($collections) && $this->discovery) {
            $collections = $this->discovery->discover();
        }

        $models = [];

        foreach ($collections as $collection) {
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($collection)) {
                // New format: already has metadata from NodeMetadataDiscovery
                $collectionClass = $collection['class'];
                $isLocal = class_exists($collectionClass);

                $models[] = [
                    'name' => $collection['name'],
                    'class' => $collectionClass,
                    'table' => $collection['table'] ?? $collection['name'] . 's',
                    'description' => $collection['description'] ?? "Model for {$collection['name']} data",
                    'location' => $isLocal ? 'local' : 'remote',
                    'capabilities' => $collection['capabilities'] ?? [
                        'db_query' => true,
                        'db_count' => true,
                        'vector_search' => false,
                        'crud' => false,
                    ],
                    'schema' => [],
                    'filter_config' => [],
                    'tools' => [],
                ];
                continue;
            }

            // Legacy format: class string - need to instantiate and discover
            if (!class_exists($collection)) {
                continue;
            }

            try {
                $instance = new $collection;
                $name = class_basename($collection);

                // Check capabilities
                $hasVectorSearch = method_exists($instance, 'toVector') ||
                    in_array('LaravelAIEngine\Traits\Vectorizable', class_uses_recursive($collection));

                // Get model schema for AI to understand available fields
                $schema = method_exists($instance, 'getModelSchema')
                    ? $instance->getModelSchema()
                    : [];

                // Get filter config from AutonomousConfig class (not model)
                $filterConfig = $this->getFilterConfigForModel($collection);

                // Get CRUD tools from AutonomousModelConfig if available
                $tools = $this->getToolsForModel($collection);

                // Get description from model if available, otherwise use generic
                $description = method_exists($instance, 'getModelDescription')
                    ? $instance->getModelDescription()
                    : "Model for {$name} data";

                $models[] = [
                    'name' => strtolower($name),
                    'class' => $collection,
                    'table' => $instance->getTable() ?? strtolower($name) . 's',
                    'schema' => $schema, // AI uses this to decide filter fields
                    'filter_config' => $filterConfig, // From AutonomousConfig class
                    'tools' => $tools, // CRUD operations available for this model
                    'capabilities' => [
                        'db_query' => true, // All models support direct DB
                        'db_count' => true,
                        'vector_search' => $hasVectorSearch, // Only if vectorized
                        'crud' => !empty($tools), // Has CRUD tools
                    ],
                    'description' => $description,
                ];
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to inspect model', [
                    'class' => $collection,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $models;
    }

    /**
     * Get available nodes for routing
     */
    protected function getAvailableNodes(): array
    {
        $nodes = collect();

        // Prefer registry discovery; it works in local/dev even when health filters are relaxed.
        if (app()->bound(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            try {
                $nodes = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class)->getActiveNodes();
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Failed loading nodes from NodeRegistryService', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to direct DB query if registry returns nothing.
        if ($nodes->isEmpty()) {
            $nodes = AINode::active()->healthy()->get();
        }

        return $nodes->map(function ($node) {
            // Use rich collection metadata if available (new format)
            // Otherwise fall back to class names (legacy format)
            $collections = $node->collections ?? [];

            if (!empty($collections) && is_array($collections)) {
                $firstItem = reset($collections);

                // Check if it's the new format (array with metadata)
                if (is_array($firstItem) && isset($firstItem['name'])) {
                    $models = collect($collections)->map(function ($c) {
                        return [
                            'name' => $c['name'],
                            'description' => $c['description'] ?? "Model for {$c['name']} data",
                            'capabilities' => $c['capabilities'] ?? [],
                        ];
                    })->toArray();
                } else {
                    // Legacy format: just class names
                    $models = collect($collections)->map(fn($c) => [
                        'name' => strtolower(class_basename($c)),
                        'description' => "Model for " . class_basename($c) . " data",
                        'capabilities' => [],
                    ])->toArray();
                }
            } else {
                $models = [];
            }

            return [
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'models' => $models,
                'collections' => $collections, // Keep original for backward compatibility
            ];
        })->toArray();
    }

    /**
     * Let AI decide which tool to use and how
     */
    protected function getAIDecision(string $message, array $context, string $model = 'gpt-4o-mini'): array
    {
        $conversationSummary = $context['conversation'];

        // Optimize model info - summarize instead of full dump
        $modelsInfo = collect($context['models'])->map(function ($m) {
            return [
                'name' => $m['name'],
                'description' => $m['description'] ?? "Model for {$m['name']} data",
                'table' => $m['table'] ?? $m['name'] . 's',
                'capabilities' => $m['capabilities'] ?? [],
                'key_fields' => !empty($m['schema']) ? array_keys($m['schema']) : [],
                'tools' => !empty($m['tools']) ? array_keys($m['tools']) : [],
                'location' => $m['location'] ?? 'local'
            ];
        })->toArray();
        $modelsJson = json_encode($modelsInfo, JSON_PRETTY_PRINT);

        // Include available nodes for routing
        $nodesJson = '';
        if (!empty($context['nodes'])) {
            $nodesJson = "\nAvailable Remote Nodes:\n" . json_encode($context['nodes'], JSON_PRETTY_PRINT) . "\n";
        }

        // Add last entity list context if available
        $lastListContext = '';
        if (!empty($context['last_entity_list'])) {
            $entityType = $context['last_entity_list']['entity_type'] ?? 'item';
            $entityData = $context['last_entity_list']['entity_data'] ?? [];
            $entityIds = $context['last_entity_list']['entity_ids'] ?? [];
            $startPosition = $context['last_entity_list']['start_position'] ?? 1;
            $endPosition = $context['last_entity_list']['end_position'] ?? count($entityIds);

            if (!empty($entityData)) {
                $lastListContext = "\nCURRENTLY VISIBLE {$entityType}s (positions {$startPosition}-{$endPosition}):\n";

                // Include entity IDs mapping for position-based selection
                if (!empty($entityIds)) {
                    $lastListContext .= "ENTITY IDS (current page): " . json_encode($entityIds) . "\n";
                    $lastListContext .= "POSITIONS: {$startPosition} to {$endPosition}\n\n";
                }
                //  $lastListContext .= json_encode($entityData, JSON_PRETTY_PRINT) . "\n";
            }
        }

        $selectedEntityContext = '';
        if (!empty($context['selected_entity']) && is_array($context['selected_entity'])) {
            $selectedEntityContext = "\nCURRENT SELECTED ENTITY:\n" . json_encode(
                $context['selected_entity'],
                JSON_PRETTY_PRINT
            ) . "\n";
        }

        $prompt = <<<PROMPT
You are a data retrieval agent. Select the best tool for the user's request.

USER REQUEST: {$message}

CONTEXT:
{$conversationSummary}
{$lastListContext}
{$selectedEntityContext}

Available Models:
{$modelsJson}

You have multiple nodes you should select the node depends on model node or use local one
Available Nodes:
{$nodesJson}

TOOLS:
- db_query: List or fetch records with exact filters (use for "list X", "show X", "get X", "details of X")
- db_query_next: Get next page ("more", "next", "continue")
- vector_search: Semantic/conceptual search (use for "important emails", "urgent messages", "emails about project X", etc.)
- db_count: Count records
- db_aggregate: Sum/average calculations
- answer_from_context: ONLY for simple questions about already visible data (counts, summaries). NEVER use for detail requests.
- model_tool: Execute a model tool (send_email, etc.)
- exit_to_orchestrator: Multi-model operations

NEVER USE MODEL TOOL IN CASE USER NEEDS RESPOND DEPENDS ON CONTEXT
 - EXAMPLE : user asks about context "should i reply on this" ai should check the context and reply depend on it ( correct )
 - EXAMPLE : user asks about context "reply on this message" ai should check the context and show suggest to the reply ( correct )
 - EXAMPLE : user asks about context "suggest reply" ai should use the tool ( incorrect )
 
  

IMPORTANT RULES:
1. When user refers to a SPECIFIC item (e.g., "show invoice #123", "the 4th invoice", "invoice 5", "details of the second one"), ALWAYS use vector_search and fallback to db_query with ID filter to fetch fresh complete data
2. When user asks for a LIST (e.g., "list invoices"), use db_query
3. When user asks for SEMANTIC/CONCEPTUAL search (e.g., "important emails", "urgent messages", "emails about the meeting"), use vector_search
4. ONLY use answer_from_context for simple aggregate questions like "how many total?", "which ones are flagged?", etc. about data already shown
5. If unsure whether user wants details or just a simple answer, prefer db_query to fetch complete data
6. If CURRENT SELECTED ENTITY exists and user asks a follow-up (details/action/draft) about "it/this/that", reuse its ID and model context without asking user to repeat identifiers
7- If user asks for enhancements/suggestions answer conversational with suggestions
8- DONT EXECUTE TOOL UNTIL USER CONFIRM THAT ALSO CONSIDER THAT USER MAY ONLY WANNA ENHANCE LAST MESSAGE OR GET INFO DEPEND ON IT
9- ONLY CONSIDER USE DB INSTEAD OF VECTOR IF USER WANNA LIST OR NOT ASKING ABOUT CONTEXT OTHERWISE PRIORITY TO RAG

RESPONSE FORMAT (JSON only):
{
  "tool": "db_query",
  "reasoning": "User wants to list emails",
  "parameters": {
    "model": "emailcache",
    "limit": 10
  }
}

EXAMPLE for fetching details by position:
{
  "tool": "db_query",
  "reasoning": "User wants the 4th invoice - use entity_ids from context to get ID, then fetch full details",
  "parameters": {
    "model": "invoice",
    "filters": {"id": "[use 4th ID from ENTITY IDS in context]"}
  }
}

EXAMPLE for fetching details by ID:
{
  "tool": "db_query",
  "reasoning": "User wants full details of invoice #5",
  "parameters": {
    "model": "invoice",
    "filters": {"id": 5}
  }
}

EXAMPLE for answer_from_context (ONLY for simple questions):
{
  "tool": "answer_from_context",
  "reasoning": "User asked a simple count question about visible data",
  "parameters": {
    "answer": "Based on the visible emails, you have 3 unread messages."
  }
}

EXAMPLE for vector_search (semantic queries):
{
  "tool": "vector_search",
  "reasoning": "User wants emails matching semantic concept 'important'",
  "parameters": {
    "model": "emailcache",
    "query": "important urgent priority",
    "limit": 10
  }
}

EXAMPLE for model_tool:
{
  "tool": "model_tool",
  "reasoning": "User wants to mark invoice as paid",
  "parameters": {
    "model": "invoice",
    "tool_name": "mark_as_paid",
    "tool_params": {"invoice_id": 217},
    "message": "make as paid"
  }
}
PROMPT;

        try {
            Log::channel('ai-engine')->info('RAG Agent Prompt', ['prompt' => $prompt]);

            $response = $this->ai
                ->model($model)
                ->withTemperature(0.1)
                ->withMaxTokens(1000)
                ->generate($prompt);

            $content = trim($response->getContent());

            Log::channel('ai-engine')->info('RAG Agent Response', ['content' => $content]);

            // Clean up common AI response formats (code blocks, etc.)
            $content = preg_replace('/^```(?:json)?\s*/m', '', $content);
            $content = preg_replace('/\s*```$/m', '', $content);

            // Try to parse as direct JSON first
            $decision = json_decode($content, true);
            if ($decision && isset($decision['tool'])) {
                return $decision;
            }

            // Try regex extraction if direct JSON fails
            if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
                $decision = json_decode($matches[0], true);
                if ($decision && isset($decision['tool'])) {
                    return $decision;
                }
            }

            Log::channel('ai-engine')->info('AI DECISION PARSING FAILED', ['content' => $content]);

            // Fallback: try to infer from message
            $messageLower = strtolower($message);
            $detectedModel = null;


            if (!$detectedModel) {
                // Try to detect model from registered models
                foreach ($context['models'] as $model) {
                    if (stripos($messageLower, $model['name']) !== false) {
                        $detectedModel = $model['name'];
                        break;
                    }
                }
            }

            // Check for aggregate keywords
            if (
                stripos($content, 'db_aggregate') !== false ||
                stripos($messageLower, 'sum') !== false ||
                stripos($messageLower, 'total') !== false ||
                stripos($messageLower, 'average') !== false
            ) {
                return [
                    'tool' => 'db_aggregate',
                    'reasoning' => 'AI suggested aggregate operation from content',
                    'parameters' => [
                        'model' => $detectedModel ?? 'invoice',
                        'aggregate' => ['operation' => 'sum', 'field' => 'amount']
                    ],
                ];
            }

            // Fallback to db_query
            return [
                'tool' => 'db_query',
                'reasoning' => 'Could not parse AI decision, inferred from message',
                'parameters' => ['model' => $detectedModel ?? 'unknown'],
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI decision failed', ['error' => $e->getMessage()]);

            return [
                'tool' => 'db_query',
                'reasoning' => 'AI decision failed: ' . $e->getMessage(),
                'parameters' => [],
            ];
        }
    }

    /**
     * Execute the tool AI chose
     */
    protected function executeTool(
        array $decision,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $tool = $decision['tool'] ?? 'db_query';
        $params = $decision['parameters'] ?? [];

        // Ensure session_id is in options for pagination state persistence
        $options['session_id'] = $sessionId;

        Log::channel('ai-engine')->info('Execute tool : ' . $tool, [
            'session_id' => $sessionId,
            'tool' => $tool,
        ]);

        switch ($tool) {
            case 'answer_from_context':
                return $this->answerFromContext($params, $conversationHistory);

            case 'db_query':
                $result = $this->dbQuery($params, $userId, $options);
                // If model not available locally, try to route to node that has it
                if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
                    return $this->routeToNodeForModel(
                        $params,
                        $message,
                        $sessionId,
                        $userId,
                        $conversationHistory,
                        $options
                    );
                }

                return $result;

            case 'db_count':
                $result = $this->dbCount($params, $userId, $options);
                if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
                    return $this->routeToNodeForModel(
                        $params,
                        $message,
                        $sessionId,
                        $userId,
                        $conversationHistory,
                        $options
                    );
                }

                return $result;

            case 'db_query_next':
                $response = $this->dbQueryNext($params, $userId, $options);
                Log::channel('ai-engine')->info('dbQueryNext : ' . json_encode($response));

                return $response;

            case 'db_aggregate':
                $result = $this->dbAggregate($params, $userId, $options);
                if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
                    return $this->routeToNodeForModel(
                        $params,
                        $message,
                        $sessionId,
                        $userId,
                        $conversationHistory,
                        $options
                    );
                }

                return $result;

            case 'vector_search':
                return $this->vectorSearch($params, $message, $sessionId, $userId, $conversationHistory, $options);

            case 'model_tool':
                // Pass message and conversation history for parameter extraction
                $params['message'] = $message;
                $params['conversation_history'] = $conversationHistory;
                $result = $this->executeModelTool($params, $userId, $options);
                // If model not available locally, try to route to node that has it
                if (!$result['success'] && ($result['should_route_to_node'] ?? false)) {
                    return $this->routeToNodeForModel(
                        $params,
                        $message,
                        $sessionId,
                        $userId,
                        $conversationHistory,
                        $options
                    );
                }

                return $result;

            case 'exit_to_orchestrator':
                return $this->exitToOrchestrator($params, $message);

            default:
                return $this->dbQuery($params, $userId, $options);
        }
    }

    /**
     * Tool: Answer from conversation context
     */
    protected function answerFromContext(array $params, array $conversationHistory): array
    {
        $answer = $params['answer'] ?? null;
        Log::channel('ai-engine')->info('Answer from context', ['answer' => $answer]);
        if ($answer) {
            return [
                'success' => true,
                'response' => $answer,
                'tool' => 'answer_from_context',
                'fast_path' => true,
            ];
        }

        return [
            'success' => false,
            'error' => 'No answer provided in parameters',
        ];
    }

    /** @var int Items per page for pagination */
    protected int $perPage = 10;

    /**
     * Tool: Direct DB query with pagination
     */
    protected function dbQuery(array $params, $userId, array $options, int $page = 1): array
    {
        $modelName = $params['model'] ?? null;
        $sessionId = $options['session_id'] ?? null;

        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $this->findModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        // Check if model exists locally - if not, it's a remote-only model
        if (!class_exists($modelClass)) {
            Log::channel('ai-engine')->info('Model not found locally, should route to remote node', [
                'model' => $modelName,
                'model_class' => $modelClass,
            ]);

            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass;
            $table = $instance->getTable();

            // Get filter config from AutonomousConfig
            $filterConfig = $this->getFilterConfigForModel($modelClass);

            // Eager load relationships from config
            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
                Log::channel('ai-engine')->info('Eager loading relationships from config', [
                    'model' => $modelClass,
                    'relationships' => $filterConfig['eager_load'],
                ]);
            }

            // Apply user filter from config or scope
            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } elseif (!empty($filterConfig['user_field'])) {
                $userField = $filterConfig['user_field'];
                if (\Schema::hasColumn($table, $userField)) {
                    $query->where($userField, $userId);
                }
            }

            // Apply filters from AI decision
            $filters = $params['filters'] ?? [];
            Log::info('dbQuery: ' . $query->latest()->skip(0)->take($this->perPage)->toSql());
            Log::info('dbQuery: ' . json_encode($query->latest()->skip(0)->take($this->perPage)->getBindings()));

            $query = $this->applyFilters($query, $filters, $modelClass, $options);

            // Get total count for pagination info
            $totalCount = (clone $query)->count();
            $totalPages = (int) ceil($totalCount / $this->perPage);

            // Apply pagination
            $offset = ($page - 1) * $this->perPage;
            Log::info('dbQuery: ' . $query->latest()->skip($offset)->take($this->perPage)->toSql());
            Log::info('dbQuery: ' . json_encode($filters));
            Log::info('dbQuery: ' . json_encode($query->latest()->skip($offset)->take($this->perPage)->getBindings()));
            $items = $query->latest()->skip($offset)->take($this->perPage)->get();

            if ($items->isEmpty()) {
                if ($page > 1) {
                    return [
                        'success' => true,
                        'response' => "No more {$modelName}s to show. You've seen all {$totalCount} results.",
                        'tool' => 'db_query',
                        'fast_path' => true,
                        'count' => 0,
                        'page' => $page,
                        'total_pages' => $totalPages,
                        'total_count' => $totalCount,
                    ];
                }

                return [
                    'success' => true,
                    'response' => "No {$modelName}s found matching your criteria.",
                    'tool' => 'db_query',
                    'fast_path' => true,
                    'count' => 0,
                ];
            }

            // Store query state for pagination in cache (persists across requests)
            if ($sessionId) {
                // Calculate position range for current page
                $startPosition = $offset + 1;
                $endPosition = $offset + $items->count();

                // Extract entity IDs and create display data
                $entityIds = $items->pluck('id')->toArray();
                $entityData = $items->map(function ($item, $index) use ($startPosition) {
                    $position = $startPosition + $index;
                    $summary = method_exists($item, 'toRAGSummary')
                        ? $item->toRAGSummary()
                        : (string) $item;

                    return [
                        'position' => $position,
                        'id' => $item->id,
                        'summary' => $summary,
                    ];
                })->toArray();

                $queryState = [
                    'model' => $modelName,
                    'model_class' => $modelClass,
                    'filters' => $filters,
                    'user_id' => $userId,
                    'options' => $options,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'total_count' => $totalCount,
                    'entity_ids' => $entityIds,
                    'entity_data' => $entityData,
                    'start_position' => $startPosition,
                    'end_position' => $endPosition,
                    'current_page' => $page,
                ];
                Cache::put("rag_query_state:{$sessionId}", $queryState, now()->addMinutes(30));

                Log::channel('ai-engine')->info('Stored query state for pagination', [
                    'session_id' => $sessionId,
                    'model' => $modelName,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'positions' => "{$startPosition}-{$endPosition}",
                    'entity_ids' => $entityIds,
                ]);
            }

            // Format response - use model's own formatting method
            $isSingleRecord = $totalCount === 1 && !empty($filters['id']);

            if ($isSingleRecord) {
                // Single record detail view - no numbering, just full content
                $item = $items->first();
                if (method_exists($item, 'toRAGContent')) {
                    $response = $item->toRAGContent();
                } elseif (method_exists($item, '__toString')) {
                    $response = $item->__toString();
                } else {
                    $response = json_encode($item->toArray(), JSON_PRETTY_PRINT);
                }
            } else {
                // List view - with numbering and pagination info
                $startNum = $offset + 1;
                $endNum = $offset + $items->count();
                $response = "**{$modelName}s** (showing {$startNum}-{$endNum} of {$totalCount}):\n\n";

                foreach ($items as $i => $item) {
                    $num = $offset + $i + 1;

                    // Always prefer model's own formatting
                    if (method_exists($item, 'toRAGContent')) {
                        $response .= "{$num}. " . $item->toRAGContent() . "\n\n";
                    } elseif (method_exists($item, '__toString')) {
                        $response .= "{$num}. " . $item->__toString() . "\n";
                    } else {
                        $response .= "{$num}. " . json_encode($item->toArray()) . "\n";
                    }
                }
            }

            // Add pagination hint if there are more pages
            if ($page < $totalPages) {
                $response .= "\n---\n*Say \"show more\" or \"next\" to see more results.*";
            }

            return [
                'success' => true,
                'response' => trim($response),
                'tool' => 'db_query',
                'fast_path' => true,
                'count' => $items->count(),
                'page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
                'has_more' => $page < $totalPages,
                'entity_ids' => $items->pluck('id')->toArray(),
                'entity_type' => $modelName,
                'items' => $items->toArray(), // Store full items for reference
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error(
                'db_query failed',
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]
            );

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tool: Get next page of results
     */
    protected function dbQueryNext(array $params, $userId, array $options): array
    {
        $sessionId = $options['session_id'] ?? null;

        Log::channel('ai-engine')->info('dbQueryNext: Attempting to retrieve query state', [
            'session_id' => $sessionId,
        ]);

        // Retrieve query state from cache
        if (!$sessionId) {
            return [
                'success' => false,
                'error' => 'No session ID provided for pagination.',
                'tool' => 'db_query_next',
            ];
        }

        $state = Cache::get("rag_query_state:{$sessionId}");

        if (empty($state)) {
            Log::channel('ai-engine')->warning('dbQueryNext: No query state found in cache', [
                'session_id' => $sessionId,
            ]);

            return [
                'success' => false,
                'error' => 'No previous query to continue. Please make a query first.',
                'tool' => 'db_query_next',
            ];
        }

        Log::channel('ai-engine')->info('dbQueryNext: Retrieved query state', [
            'session_id' => $sessionId,
            'current_page' => $state['page'] ?? 1,
            'total_pages' => $state['total_pages'] ?? 0,
        ]);

        $nextPage = ($state['page'] ?? 1) + 1;

        // Check if there are more pages
        if ($nextPage > ($state['total_pages'] ?? 1)) {
            return [
                'success' => true,
                'response' => "You've reached the end. All {$state['total_count']} {$state['model']}s have been shown.",
                'tool' => 'db_query_next',
                'fast_path' => true,
                'count' => 0,
                'page' => $state['page'],
                'total_pages' => $state['total_pages'],
            ];
        }
        Log::channel('ai-engine')->info('dbQueryNext 2');
        // Re-run the query with the next page
        $queryParams = [
            'model' => $state['model'],
            'filters' => $state['filters'] ?? [],
        ];
        Log::channel('ai-engine')->info('dbQueryNext 3');

        return $this->dbQuery($queryParams, $state['user_id'], $state['options'], $nextPage);
    }

    /**
     * Tool: DB count
     */
    protected function dbCount(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $this->findModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass;
            $table = $instance->getTable();

            // Get filter config from AutonomousConfig
            $filterConfig = $this->getFilterConfigForModel($modelClass);

            // Apply user filter from config or scope
            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } elseif (!empty($filterConfig['user_field'])) {
                $userField = $filterConfig['user_field'];
                if (\Schema::hasColumn($table, $userField)) {
                    $query->where($userField, $userId);
                }
            }

            // Apply filters from AI decision
            $filters = $params['filters'] ?? [];
            $query = $this->applyFilters($query, $filters, $modelClass, $options);

            $count = $query->count();

            return [
                'success' => true,
                'response' => "You have **{$count}** {$modelName}(s).",
                'tool' => 'db_count',
                'fast_path' => true,
                'count' => $count,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_count failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tool: DB aggregate (sum, avg, min, max)
     * Supports both database fields and model methods (e.g., getTotal())
     */
    protected function dbAggregate(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $this->findModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        $aggregate = $params['aggregate'] ?? [];
        $operation = $aggregate['operation'] ?? 'sum';
        $field = $aggregate['field'] ?? null;

        // Get filter config to find amount field if not specified
        $filterConfig = $this->getFilterConfigForModel($modelClass);
        if (!$field && !empty($filterConfig['amount_field'])) {
            $field = $filterConfig['amount_field'];
        }

        if (!$field) {
            return ['success' => false, 'error' => 'No field specified for aggregation'];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass;
            $table = $instance->getTable();

            // Eager load relationships from config for calculated fields
            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            // Apply user filter from config or scope
            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } elseif (!empty($filterConfig['user_field'])) {
                $userField = $filterConfig['user_field'];
                if (\Schema::hasColumn($table, $userField)) {
                    $query->where($userField, $userId);
                }
            }

            // Apply filters from AI decision
            $filters = $params['filters'] ?? [];
            $query = $this->applyFilters($query, $filters, $modelClass, $options);

            // Check if field exists in database or is a model method
            $isDbField = \Schema::hasColumn($table, $field);
            $methodName = 'get' . ucfirst($field); // e.g., 'total' -> 'getTotal'
            $hasMethod = method_exists($instance, $methodName);

            $validOperations = ['sum', 'avg', 'min', 'max', 'count'];
            if (!in_array($operation, $validOperations)) {
                $operation = 'sum';
            }

            $result = null;
            $count = 0;
            $calculationMethod = null;

            if ($isDbField) {
                // Fast path: Use database aggregation
                $result = $query->$operation($field);
                $count = (clone $query)->count();
                $calculationMethod = 'database';

                Log::channel('ai-engine')->info('DB aggregate using database field', [
                    'field' => $field,
                    'operation' => $operation,
                ]);
            } elseif ($hasMethod) {
                // Calculated field: Fetch records and calculate in-memory
                $records = $query->get();
                $count = $records->count();

                if ($count === 0) {
                    $result = 0;
                } else {
                    $values = $records->map(function ($record) use ($methodName) {
                        return $record->$methodName();
                    })->filter(function ($value) {
                        return is_numeric($value);
                    });

                    switch ($operation) {
                        case 'sum':
                            $result = $values->sum();
                            break;
                        case 'avg':
                            $result = $values->avg();
                            break;
                        case 'min':
                            $result = $values->min();
                            break;
                        case 'max':
                            $result = $values->max();
                            break;
                        case 'count':
                            $result = $values->count();
                            break;
                    }
                }

                $calculationMethod = 'model_method';

                Log::channel('ai-engine')->info('DB aggregate using model method', [
                    'field' => $field,
                    'method' => $methodName,
                    'operation' => $operation,
                    'records_fetched' => $count,
                ]);
            } else {
                return [
                    'success' => false,
                    'error' => "Field '{$field}' not found in database and no method '{$methodName}' exists on {$modelName}"
                ];
            }

            // Format response based on operation
            $operationLabels = [
                'sum' => 'Total',
                'avg' => 'Average',
                'min' => 'Minimum',
                'max' => 'Maximum',
                'count' => 'Count',
            ];
            $label = $operationLabels[$operation] ?? 'Result';

            // Format number nicely
            $formattedResult = is_numeric($result) ? number_format($result, 2) : $result;

            Log::channel('ai-engine')->info('Aggregate calculation completed', [
                'operation' => $operation,
                'field' => $field,
                'result' => $result,
                'count' => $count,
                'method' => $calculationMethod,
            ]);

            return [
                'success' => true,
                'response' => "**{$label} {$field}**: \${$formattedResult} (from {$count} {$modelName}s)",
                'tool' => 'db_aggregate',
                'fast_path' => $calculationMethod === 'database',
                'result' => $result,
                'count' => $count,
                'operation' => $operation,
                'field' => $field,
                'calculation_method' => $calculationMethod,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_aggregate failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tool: Execute model CRUD tool (create, update, delete, etc.)
     */
    protected function executeModelTool(array $params, $userId, array $options): array
    {
        $modelName = $params['model'] ?? null;
        $toolName = $params['tool_name'] ?? null;
        $toolParams = $params['tool_params'] ?? [];
        $message = $params['message'] ?? '';
        $conversationHistory = $params['conversation_history'] ?? [];

        Log::channel('ai-engine')->info('AutonomousRAGAgent: executeModelTool called', [
            'model' => $modelName,
            'tool_name' => $toolName,
            'tool_params' => $toolParams,
            'message' => $message,
            'user_id' => $userId,
        ]);

        if (!$modelName || !$toolName) {
            Log::channel('ai-engine')->error('AutonomousRAGAgent: Missing model or tool_name');

            return ['success' => false, 'error' => 'Model and tool_name required'];
        }

        $sessionId = $options['session_id'] ?? null;
        if ($sessionId) {
            $queryState = Cache::get("rag_query_state:{$sessionId}");
            if ($queryState && isset($queryState['from_node'])) {
                Log::channel('ai-engine')->info('Model tool - data from remote node, routing tool execution there', [
                    'model' => $modelName,
                    'tool' => $toolName,
                    'node' => $queryState['from_node'],
                ]);

                return [
                    'success' => false,
                    'error' => "Model {$modelName} data is on remote node",
                    'should_route_to_node' => true,
                ];
            }
        }

        $modelClass = $this->findModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        if (!class_exists($modelClass)) {
            Log::channel('ai-engine')->info('Model tool - model not found locally, should route to remote node', [
                'model' => $modelName,
                'tool' => $toolName,
                'model_class' => $modelClass,
            ]);

            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        $configClass = $this->findModelConfigClass($modelClass);
        if (!$configClass) {
            return ['success' => false, 'error' => "No config found for {$modelName}"];
        }

        try {
            $tools = $configClass::getTools();

            Log::channel('ai-engine')->info('AutonomousRAGAgent: Found config tools', [
                'config' => class_basename($configClass),
                'available_tools' => array_keys($tools),
                'looking_for' => $toolName,
            ]);

            if (!isset($tools[$toolName])) {
                Log::channel('ai-engine')->error('AutonomousRAGAgent: Tool not found', [
                    'tool_name' => $toolName,
                    'available_tools' => array_keys($tools),
                ]);

                return ['success' => false, 'error' => "Tool {$toolName} not found for {$modelName}"];
            }

            Log::channel('ai-engine')->info('AutonomousRAGAgent: FOUND TOOL', [
                'tool_name' => $toolName,
                'model' => $modelName,
            ]);

            $tool = $tools[$toolName];
            $handler = $tool['handler'] ?? null;
            $requiresConfirmation = (bool) ($tool['requires_confirmation'] ?? false);

            if (!$handler || !is_callable($handler)) {
                Log::channel('ai-engine')->error('AutonomousRAGAgent: Tool has no callable handler');

                return ['success' => false, 'error' => "Tool {$toolName} has no handler"];
            }

            $allowedOps = $configClass::getAllowedOperations($userId);
            $requiresCreate = stripos($toolName, 'create') !== false;
            $requiresUpdate = stripos($toolName, 'update') !== false;
            $requiresDelete = stripos($toolName, 'delete') !== false;

            if ($requiresCreate && !in_array('create', $allowedOps)) {
                return ['success' => false, 'error' => 'Permission denied: create'];
            }
            if ($requiresUpdate && !in_array('update', $allowedOps)) {
                return ['success' => false, 'error' => 'Permission denied: update'];
            }
            if ($requiresDelete && !in_array('delete', $allowedOps)) {
                return ['success' => false, 'error' => 'Permission denied: delete'];
            }

            $toolSchema = $tool['parameters'] ?? [];
            $queryState = $sessionId ? Cache::get("rag_query_state:{$sessionId}") : null;

            $extractedParams = \LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor::extract(
                $message,
                $conversationHistory,
                $toolSchema,
                $modelName,
                $queryState
            );

            $finalParams = array_merge($extractedParams, $toolParams);

            $selectedEntity = $options['selected_entity']
                ?? $options['selected_entity_context']
                ?? ($context->metadata['selected_entity_context'] ?? null);
            if ($selectedEntity && !empty($selectedEntity['entity_data'])) {
                $finalParams['entity_data'] = $selectedEntity['entity_data'];

                Log::channel('ai-engine')->info('AutonomousRAGAgent: Added entity data to tool params', [
                    'tool_name' => $toolName,
                    'entity_type' => $selectedEntity['entity_type'] ?? 'unknown',
                    'has_entity_data' => true,
                ]);
            }


            Log::channel('ai-engine')->info('AutonomousRAGAgent: Executing tool handler', [
                'model' => $modelName,
                'tool' => $toolName,
                'user_id' => $userId,
                'extracted_params' => $extractedParams,
                'provided_params' => $toolParams,
                'final_params' => $finalParams,
            ]);

            $result = $handler($finalParams);

            Log::channel('ai-engine')->info('AutonomousRAGAgent: Tool handler executed', [
                'tool_name' => $toolName,
                'result' => $result,
            ]);

            if (is_array($result)) {
                $success = $result['success'] ?? true;
                $message = $result['message'] ?? ($success ? 'Operation completed' : 'Operation failed');

                $response = [
                    'success' => $success,
                    'response' => $message,
                    'tool' => 'model_tool',
                    'tool_name' => $toolName,
                    'fast_path' => true,
                    'data' => $result,
                ];

                if (isset($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                    $response['suggested_actions'] = $result['suggested_actions'];
                }

                return $response;
            }

            return [
                'success' => true,
                'response' => "Tool {$toolName} executed successfully",
                'tool' => 'model_tool',
                'tool_name' => $toolName,
                'fast_path' => true,
                'result' => $result,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Model tool execution failed', [
                'model' => $modelName,
                'tool' => $toolName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tool' => 'model_tool',
                'tool_name' => $toolName,
            ];
        }
    }

    /**
     * Tool: Vector search (RAG)
     */
    protected function vectorSearch(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        if (!$this->ragService) {
            return ['success' => false, 'error' => 'RAG service not available'];
        }

        $modelName = $params['model'] ?? null;
        $query = $params['query'] ?? $message;

        // Filter collections if model specified
        $collections = $options['rag_collections'] ?? [];
        if ($modelName) {
            $modelClass = $this->findModelClass($modelName, $options);
            if ($modelClass) {
                $collections = [$modelClass];
            }
        }

        try {
            $response = $this->ragService->processMessage(
                $query,
                $sessionId,
                $collections, // availableCollections (3rd arg)
                $conversationHistory, // conversationHistory (4th arg)
                array_merge($options, [ // options (5th arg)
                    'user_id' => $userId,
                    'rag_collections' => $collections,
                ]),
                $userId // userId (6th arg)
            );

            return [
                'success' => true,
                'response' => $response->getContent(),
                'tool' => 'vector_search',
                'metadata' => $response->getMetadata(),
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('vector_search failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Route to node that has the requested model
     */
    protected function routeToNodeForModel(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $this->findModelClass($modelName, $options);

        // Find which node has this model
        $nodes = $this->getAvailableNodes();
        $targetNode = null;

        foreach ($nodes as $nodeInfo) {
            $models = $nodeInfo['models'] ?? [];
            foreach ($models as $model) {
                $candidateNames = array_filter([
                    strtolower((string) ($model['name'] ?? '')),
                    strtolower(
                        (string) class_basename((string) ($model['class'] ?? ''))
                    ),
                ]);

                $normalizedRequested = strtolower($modelName);
                $isMatch = in_array($normalizedRequested, $candidateNames, true) ||
                    in_array($normalizedRequested . 's', $candidateNames, true) ||
                    in_array(rtrim($normalizedRequested, 's'), $candidateNames, true);

                if ($isMatch) {
                    $targetNode = $nodeInfo['slug'];
                    break 2;
                }
            }
        }

        // Fallback: use registry ownership lookup by model class/name.
        if (!$targetNode && app()->bound(\LaravelAIEngine\Services\Node\NodeRegistryService::class)) {
            try {
                $registry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);

                if ($modelClass) {
                    $node = $registry->findNodeForCollection($modelClass);
                    if ($node) {
                        $targetNode = $node->slug;
                    }
                }

                if (!$targetNode) {
                    $node = $registry->findNodeForCollection($modelName);
                    if ($node) {
                        $targetNode = $node->slug;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('ai-engine')->warning('Node registry fallback lookup failed', [
                    'model' => $modelName,
                    'model_class' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (!$targetNode) {
            Log::channel('ai-engine')->warning('No node found for model routing', [
                'model' => $modelName,
                'model_class' => $modelClass,
                'available_nodes' => array_map(fn($n) => $n['slug'] ?? 'unknown', $nodes),
            ]);

            return ['success' => false, 'error' => "No node found with model {$modelName}"];
        }

        Log::channel('ai-engine')->info('Routing to node for model', [
            'model' => $modelName,
            'model_class' => $modelClass,
            'node' => $targetNode,
        ]);

        // Route to the node with the model
        return $this->routeToNode(
            ['node' => $targetNode],
            $message,
            $sessionId,
            $userId,
            $conversationHistory,
            $options
        );
    }

    /**
     * Tool: Route to node
     */
    protected function routeToNode(
        array $params,
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $options
    ): array {
        $nodeSlug = $params['node'] ?? null;
        if (!$nodeSlug) {
            return ['success' => false, 'error' => 'No node specified'];
        }

        $node = AINode::where('slug', $nodeSlug)->first();
        if (!$node) {
            return ['success' => false, 'error' => "Node {$nodeSlug} not available"];
        }

        if (!$node->isHealthy() && !app()->environment('local')) {
            return ['success' => false, 'error' => "Node {$nodeSlug} not available"];
        }

        if (!$node->isHealthy() && app()->environment('local')) {
            Log::channel('ai-engine')->warning('Routing to node despite unhealthy status in local environment', [
                'node' => $nodeSlug,
            ]);
        }

        try {
            // Extract user token and forwardable headers for authentication
            $userToken = request()->bearerToken() ?? request()->header('X-User-Token');
            $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

            // Merge authentication headers
            $headers = array_merge($forwardHeaders, [
                'X-Forwarded-From-Node' => config('app.name'),
                'X-User-Token' => $userToken,
            ]);

            $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);

            $response = $router->forwardChat(
                $node,
                $message,
                $sessionId,
                array_merge($options, [
                    'conversation_history' => $conversationHistory,
                    'headers' => $headers,
                    'user_token' => $userToken,
                ]),
                $userId
            );

            if ($response['success']) {
                Cache::put("session_last_node:{$sessionId}", $nodeSlug, now()->addMinutes(30));

                $result = [
                    'success' => true,
                    'response' => $response['response'],
                    'tool' => 'route_to_node',
                    'node' => $nodeSlug,
                    'metadata' => $response['metadata'] ?? [],
                ];

                // Extract entity_ids and entity_type from metadata for follow-up tracking
                if (isset($response['metadata']['entity_ids'])) {
                    $result['entity_ids'] = $response['metadata']['entity_ids'];
                }
                if (isset($response['metadata']['entity_type'])) {
                    $result['entity_type'] = $response['metadata']['entity_type'];
                }

                return $result;
            }

            return ['success' => false, 'error' => $response['error'] ?? 'Routing failed'];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('route_to_node failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find model class from name using discovery
     * Prioritizes AutonomousModelConfig classes for correct namespace
     */
    protected function findModelClass(string $modelName, array $options): ?string
    {
        $modelName = strtolower($modelName);

        // PRIORITY 1: Check AutonomousModelConfig classes first by searching all configs
        $configClass = $this->findModelConfigByName($modelName);
        if ($configClass && method_exists($configClass, 'getModelClass')) {
            $modelClass = $configClass::getModelClass();
            Log::channel('ai-engine')->debug('Found model class from AutonomousModelConfig', [
                'model_name' => $modelName,
                'config_class' => $configClass,
                'model_class' => $modelClass,
            ]);

            return $modelClass;
        }

        // PRIORITY 2: Fall back to RAG collections
        $collections = $options['rag_collections'] ?? [];

        // Discover if not provided
        if (empty($collections) && $this->discovery) {
            $collections = $this->discovery->discover();
        }

        foreach ($collections as $collection) {
            // Handle new format (array with metadata) or legacy format (class string)
            if (is_array($collection)) {
                // New format: check 'name' field
                $collectionName = $collection['name'] ?? '';
                $collectionClass = $collection['class'] ?? '';

                if (
                    $collectionName === $modelName ||
                    $collectionName === $modelName . 's' ||
                    $collectionName . 's' === $modelName ||
                    str_contains($collectionName, $modelName)
                ) {
                    return $collectionClass;
                }
            } else {
                // Legacy format: class string
                $baseName = strtolower(class_basename($collection));
                if (
                    $baseName === $modelName ||
                    $baseName === $modelName . 's' ||
                    $baseName . 's' === $modelName ||
                    str_contains($baseName, $modelName)
                ) {
                    return $collection;
                }
            }
        }

        return null;
    }

    /**
     * Find AutonomousModelConfig by model name (not class)
     * Searches through common config locations and checks getName()
     */
    protected function findModelConfigByName(string $modelName): ?string
    {
        $modelName = strtolower($modelName);

        // Search in App\AI\Configs namespace
        $configPaths = [
            app_path('AI/Configs'),
        ];

        foreach ($configPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $files = glob($path . '/*ModelConfig.php');
            foreach ($files as $file) {
                $className = basename($file, '.php');
                $fullClass = "App\\AI\\Configs\\{$className}";

                if (
                    class_exists($fullClass) &&
                    is_subclass_of($fullClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)
                ) {
                    try {
                        $configModelName = strtolower($fullClass::getName());
                        if ($configModelName === $modelName) {
                            return $fullClass;
                        }
                    } catch (\Exception $e) {
                        // Skip invalid configs
                        continue;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Summarize conversation for AI context
     */
    protected function summarizeConversation(array $history): string
    {
        if (empty($history)) {
            return "No previous conversation.";
        }

        $summary = "Recent conversation:\n";
        $recent = array_slice($history, -6);

        foreach ($recent as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $content = strlen($content) > 200 ? substr($content, 0, 200) . '...' : $content;
            $summary .= "- {$role}: {$content}\n";
        }

        return $summary;
    }

    /**
     * Apply filters to query based on AI decision
     */
    protected function applyFilters($query, array $filters, string $modelClass, array $options = [])
    {
        if (empty($filters)) {
            return $query;
        }

        $instance = new $modelClass;
        $table = $instance->getTable();

        // ID filter - for fetching specific record
        if (!empty($filters['id'])) {
            $resolvedId = $this->resolveIdFilterValue($filters['id'], $options);
            if ($resolvedId !== null) {
                $query->where('id', $resolvedId);

                Log::channel('ai-engine')->info('Applied ID filter', [
                    'id' => $resolvedId,
                    'raw_id' => $filters['id'],
                ]);
            } else {
                Log::channel('ai-engine')->warning('Could not resolve ID filter value, skipping ID filter', [
                    'raw_id' => $filters['id'],
                    'session_id' => $options['session_id'] ?? null,
                ]);
            }

            return $query; // When filtering by ID, ignore other filters
        }

        // Date filter
        if (!empty($filters['date_field']) && !empty($filters['date_value'])) {
            $dateField = $filters['date_field'];
            $dateValue = $filters['date_value'];
            $operator = $filters['date_operator'] ?? '=';

            // Verify field exists
            if (\Schema::hasColumn($table, $dateField)) {
                if ($operator === 'between' && !empty($filters['date_end'])) {
                    $query->whereBetween($dateField, [$dateValue, $filters['date_end']]);
                } else {
                    $query->whereDate($dateField, $operator, $dateValue);
                }

                Log::channel('ai-engine')->info('Applied date filter', [
                    'field' => $dateField,
                    'operator' => $operator,
                    'value' => $dateValue,
                    'end' => $filters['date_end'] ?? null,
                ]);
            }
        }

        // Status filter
        if (!empty($filters['status'])) {
            $statusField = 'status';
            if (\Schema::hasColumn($table, $statusField)) {
                $query->where($statusField, $filters['status']);
            }
        }

        // Amount filters - use AI-provided field name or fallback
        $amountField = $filters['amount_field'] ?? null;

        if (!empty($filters['amount_min'])) {
            if ($amountField && \Schema::hasColumn($table, $amountField)) {
                $query->where($amountField, '>=', $filters['amount_min']);
            }
        }

        if (!empty($filters['amount_max'])) {
            if ($amountField && \Schema::hasColumn($table, $amountField)) {
                $query->where($amountField, '<=', $filters['amount_max']);
            }
        }

        return $query;
    }

    /**
     * Resolve AI-generated ID filter values.
     * Supports raw numeric IDs, ordinals ("2nd"), and placeholders
     * like "[use 2nd ID from ENTITY IDS in context]".
     */
    protected function resolveIdFilterValue($rawId, array $options): ?int
    {
        if (is_int($rawId)) {
            return $rawId > 0 ? $rawId : null;
        }

        if (is_numeric($rawId)) {
            $id = (int) $rawId;

            return $id > 0 ? $id : null;
        }

        if (!is_string($rawId) || trim($rawId) === '') {
            return null;
        }

        $text = strtolower(trim($rawId));

        // Extract explicit numeric ID if present (e.g., "id 25", "#25", "invoice 25")
        if (preg_match('/(?:^|[^\d])(\d{1,10})(?:$|[^\d])/', $text, $m)) {
            $maybeId = (int) $m[1];

            // If this looks like an ordinal placeholder, resolve from entity list instead.
            if (str_contains($text, 'entity ids') || preg_match('/\b(st|nd|rd|th)\b/', $text)) {
                $resolvedFromPosition = $this->resolveIdFromPosition((int) $m[1], $options);
                if ($resolvedFromPosition !== null) {
                    return $resolvedFromPosition;
                }
            }

            if ($maybeId > 0) {
                return $maybeId;
            }
        }

        // Resolve ordinal words ("first", "second", ...)
        $wordToOrdinal = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'sixth' => 6,
            'seventh' => 7,
            'eighth' => 8,
            'ninth' => 9,
            'tenth' => 10,
        ];
        foreach ($wordToOrdinal as $word => $ordinal) {
            if (str_contains($text, $word)) {
                $resolved = $this->resolveIdFromPosition($ordinal, $options);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a 1-based position from current visible entity IDs.
     */
    protected function resolveIdFromPosition(int $position, array $options): ?int
    {
        if ($position <= 0) {
            return null;
        }

        $entityIds = [];
        $startPosition = 1;

        if (!empty($options['last_entity_list']) && is_array($options['last_entity_list'])) {
            $entityIds = $options['last_entity_list']['entity_ids'] ?? [];
            $startPosition = (int) ($options['last_entity_list']['start_position'] ?? 1);
        }

        if (empty($entityIds) && !empty($options['session_id'])) {
            $queryState = Cache::get("rag_query_state:{$options['session_id']}");
            if (is_array($queryState)) {
                $entityIds = $queryState['entity_ids'] ?? [];
                $startPosition = (int) ($queryState['start_position'] ?? 1);
            }
        }

        if (empty($entityIds)) {
            return null;
        }

        // Support absolute positions (e.g., position 12 with start_position 11)
        if ($position >= $startPosition) {
            $absoluteIndex = $position - $startPosition;
            if (isset($entityIds[$absoluteIndex])) {
                return (int) $entityIds[$absoluteIndex];
            }
        }

        // Also support relative positions within current page (1-based)
        $relativeIndex = $position - 1;
        if (isset($entityIds[$relativeIndex])) {
            return (int) $entityIds[$relativeIndex];
        }

        return null;
    }

    /**
     * Get CRUD tools for a model from AutonomousModelConfig classes
     */
    protected function getToolsForModel(string $modelClass): array
    {
        // Try to find AutonomousModelConfig for this model
        $configClass = $this->findModelConfigClass($modelClass);

        if (!$configClass) {
            return [];
        }

        try {
            // Get tools from config
            $tools = $configClass::getTools();

            // Format tools for AI with descriptions
            $formattedTools = [];
            foreach ($tools as $toolName => $toolConfig) {
                $formattedTools[$toolName] = [
                    'description' => $toolConfig['description'] ?? '',
                    'parameters' => $toolConfig['parameters'] ?? [],
                    'requires_confirmation' => $toolConfig['requires_confirmation'] ?? false,
                ];
            }

            return $formattedTools;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to get tools for model', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Find AutonomousModelConfig class for a model
     */
    protected function findModelConfigClass(string $modelClass): ?string
    {
        // Convention: Model at App\Models\Invoice -> Config at App\AI\Configs\InvoiceModelConfig
        $modelName = class_basename($modelClass);
        $namespace = substr($modelClass, 0, strrpos($modelClass, '\\'));
        $baseNamespace = substr($namespace, 0, strpos($namespace, '\\'));

        // Try common config locations
        $possibleConfigs = [
            "{$baseNamespace}\\AI\\Configs\\{$modelName}ModelConfig",
            "{$baseNamespace}\\AI\\Configs\\{$modelName}Config",
            "App\\AI\\Configs\\{$modelName}ModelConfig",
            "App\\AI\\Configs\\{$modelName}Config",
        ];

        foreach ($possibleConfigs as $configClass) {
            if (class_exists($configClass)) {
                // Verify it extends AutonomousModelConfig
                if (is_subclass_of($configClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)) {
                    return $configClass;
                }
            }
        }

        return null;
    }

    /**
     * Get filter config for a model from AutonomousConfig classes
     */
    protected function getFilterConfigForModel(string $modelClass): array
    {
        // First try AutonomousModelConfig
        $configClass = $this->findModelConfigClass($modelClass);
        if ($configClass) {
            try {
                return $configClass::getFilterConfig();
            } catch (\Exception $e) {
                // Fall through to legacy discovery
            }
        }

        // Fallback: Get discovered collectors (legacy)
        $discoveryService = app(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class);
        $collectors = $discoveryService->discoverCollectors();

        // Find collector that matches this model
        foreach ($collectors as $name => $collector) {
            if (($collector['model_class'] ?? null) === $modelClass) {
                return $collector['filter_config'] ?? [];
            }
        }

        return [];
    }

    /**
     * Tool: Exit to orchestrator for CRUD operations
     * Returns control to the orchestrator which will start the appropriate autonomous collector
     */
    protected function exitToOrchestrator(array $params, string $originalMessage): array
    {
        $message = $params['message'] ?? $originalMessage;

        return [
            'success' => true,
            'exit_to_orchestrator' => true,
            'message' => $message,
            'tool' => 'exit_to_orchestrator',
        ];
    }
}
