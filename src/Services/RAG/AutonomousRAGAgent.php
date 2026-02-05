<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
        $this->discovery = $discovery ?? (app()->bound(RAGCollectionDiscovery::class) ? app(RAGCollectionDiscovery::class) : null);
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
        Log::channel('ai-engine')->info('Autonomus RAG User ID:'.$userId);
        $startTime = microtime(true);

        // Build context for AI with available tools and models
        $context = $this->buildAIContext($message, $conversationHistory, $userId, $options);

        // Detect if we should use function calling (OpenAI) or prompt-based routing
        $model = $options['model'] ?? 'gpt-4o-mini';
        $useOpenAIFunctions = false;

        if ($useOpenAIFunctions) {
            // Use OpenAI function calling - AI has direct access to all tools
            return $this->processWithFunctionCalling($message, $sessionId, $userId, $conversationHistory, $context, $options, $model);
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
                prompt:       $message,
                engine:       \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model:        \LaravelAIEngine\Enums\EntityEnum::from($model),
                userId:       $userId,
                messages:     $messages,
                metadata:     ['session_id' => $sessionId],
                functions:    $functions,
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
                return $this->executeFunction($functionName, $functionArgs, $userId, $sessionId, $conversationHistory, $options);
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
                    'description' => "Model for {$name} data",
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
        if (!config('ai-engine.nodes.enabled', false)) {
            return [];
        }

        $nodes = AINode::active()->healthy()->child()->get();

        return $nodes->map(function ($node) {
            return [
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'models' => collect($node->collections)->map(fn($c) => strtolower(class_basename($c)))->toArray(),
            ];
        })->toArray();
    }

    /**
     * Let AI decide which tool to use and how
     */
    protected function getAIDecision(string $message, array $context, string $model = 'gpt-4o-mini'): array
    {
        $conversationSummary = $context['conversation'];
        $modelsJson = json_encode($context['models'], JSON_PRETTY_PRINT);
        $nodesJson = json_encode($context['nodes'], JSON_PRETTY_PRINT);
        $isMaster = $context['is_master'] ? 'YES' : 'NO';

        // Add last entity list context if available
        $lastListContext = '';
        if (!empty($context['last_entity_list'])) {
            $entityType = $context['last_entity_list']['entity_type'] ?? 'item';
            $entityData = $context['last_entity_list']['entity_data'] ?? [];

            if (!empty($entityData)) {
                $lastListContext = "\nPREVIOUS RESULTS ({$entityType}):\n";
                $lastListContext .= json_encode($entityData, JSON_PRETTY_PRINT) . "\n";
            }
        }

        $prompt = <<<PROMPT
You are an intelligent query router. Analyze the user's request and choose the BEST tool to handle it.

USER MESSAGE: "{$message}"

CONVERSATION HISTORY:
{$conversationSummary}
{$lastListContext}
AVAILABLE MODELS (with schema):
{$modelsJson}

AVAILABLE NODES (for routing):
{$nodesJson}

IS MASTER NODE: {$isMaster}

TOOL SELECTION PRIORITY (choose the FASTEST appropriate tool):

1. **answer_from_context** - Use ONLY when:
   - Simple clarification or follow-up question about previous response
   - Explaining what was just shown
   - NEVER use for ANY data queries (list, show, get, find, search)
   - NEVER use when user asks for invoices, customers, products, or any model data
   - NEVER use when a specific ID is mentioned or requested
   - NEVER use when user says "list", "show", "get", "find", "search"
   - NEVER use when user wants to update, change, modify, create, or delete anything

2. **db_query** - Use for ALL data list/fetch queries (fast 20-50ms)
   Supports filters and pagination (page parameter, default page 1)
   ONLY if model exists in AVAILABLE MODELS list above
   
   ALWAYS use db_query when user asks to:
   - "list invoices" → ALWAYS fetch fresh from DB
   - "show invoices" → ALWAYS fetch fresh from DB
   - "get invoices" → ALWAYS fetch fresh from DB
   - Even if data exists in conversation history, ALWAYS fetch fresh
   
   CRITICAL: When fetching details for a specific ID (e.g., "Show full details for invoice with ID 217"):
   - ALWAYS use db_query with "id" filter - NEVER use answer_from_context
   - Add filter: "id" => the specific ID
   - This will fetch the FULL record with ALL relationships and item details
   - The model's toRAGContent() method will format it with complete information
   - Even if the ID was mentioned in conversation history, ALWAYS fetch fresh from DB

3. **db_query_next** - Use when user asks for "more", "next", "next page", "show more"
   Continues from last query with next page

4. **db_count** - Use for "how many" count queries (fast 1-20ms)
   Supports filters based on model schema fields
   ONLY if model exists in AVAILABLE MODELS list above

5. **db_aggregate** - Use for sum/avg/min/max queries (fast 1-20ms)
   Example: "total invoice amount", "average order value", "highest bill"
   Requires: operation (sum|avg|min|max) and field from filter_config.amount_field or schema
   ONLY if model exists in AVAILABLE MODELS list above

6. **vector_search** - Use for semantic search (slow 2-5s)
   Only when semantic understanding is needed
   ONLY if model exists in AVAILABLE MODELS list above

7. **model_tool** - Use for CRUD operations (create, update, delete) (fast 50-200ms)
   Check if model has "tools" in AVAILABLE MODELS
   Use when user wants to create, update, delete, or perform actions on records
   Each tool has description and parameters - follow them carefully
   Tools with requires_confirmation=true need user confirmation first

8. **route_to_node** - Use when data is on remote node (slow 5-10s)
   Use this when requested model is NOT in AVAILABLE MODELS but IS in a node's models list

CRITICAL ROUTING RULE (ALWAYS FOLLOW THIS ORDER):
1. Check if requested model exists in AVAILABLE MODELS list
2. If model EXISTS in AVAILABLE MODELS:
   - Use db_query, db_count, db_aggregate, or model_tool (depending on request)
3. If model NOT in AVAILABLE MODELS:
   - Check AVAILABLE NODES for the model
   - If found in a node's models list, use route_to_node with that node's slug
   - NEVER use db_query for a model that doesn't exist locally
4. If model not found anywhere, respond with error

EXAMPLE:
- User asks for "invoice details"
- Check: Is "invoice" in AVAILABLE MODELS? NO
- Check: Is "invoice" in any node's models? YES (node: inbusiness-node)
- Action: Use route_to_node with node="inbusiness-node"
- WRONG: Using db_query when model not in AVAILABLE MODELS

FILTER RULES:
- If model has "filter_config", USE those field names directly (they are correct)
- If no filter_config, look at "schema" to find field names and types
- Convert user date formats (DD-MM-YYYY) to YYYY-MM-DD
- When fetching by ID, add "id" to filters object

RESPOND WITH JSON ONLY:
{
  "tool": "answer_from_context|db_query|db_query_next|db_count|db_aggregate|vector_search|model_tool|route_to_node",
  "reasoning": "brief explanation",
  "parameters": {
    "model": "model name",
    "query": "search query (for vector_search)",
    "node": "node slug (for route_to_node)",
    "answer": "direct answer (for answer_from_context)",
    "tool_name": "tool name from model's tools (for model_tool)",
    "tool_params": {
      "parameter_name": "value matching tool's parameter requirements"
    },
    "aggregate": {
      "operation": "sum|avg|min|max",
      "field": "field name from filter_config.amount_field or schema"
    },
    "filters": {
      "id": "specific ID when fetching single record",
      "date_field": "from filter_config.date_field or schema",
      "date_value": "YYYY-MM-DD format",
      "date_operator": "= | >= | <= | between",
      "date_end": "YYYY-MM-DD for between",
      "status": "status value"
    }
  }
}
PROMPT;

        try {
            $response = $this->ai
                ->model($model)
                ->withTemperature(0.1)
                ->withMaxTokens(500)
                ->generate($prompt);

            $content = trim($response->getContent());

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

            // Fallback: try to extract key info from content
            if (stripos($content, 'db_aggregate') !== false || stripos($content, 'sum') !== false) {
                return [
                    'tool' => 'db_aggregate',
                    'reasoning' => 'AI suggested aggregate operation from content',
                    'parameters' => ['model' => 'invoice', 'aggregate' => ['operation' => 'sum', 'field' => 'amount']],
                ];
            }

            // Fallback
            return [
                'tool' => 'db_query',
                'reasoning' => 'Could not parse AI decision, defaulting to db_query',
                'parameters' => [],
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

        switch ($tool) {
            case 'answer_from_context':
                return $this->answerFromContext($params, $conversationHistory);

            case 'db_query':
                return $this->dbQuery($params, $userId, $options);

            case 'db_count':
                return $this->dbCount($params, $userId, $options);

            case 'db_query_next':
                return $this->dbQueryNext($params, $userId, $options);

            case 'db_aggregate':
                return $this->dbAggregate($params, $userId, $options);

            case 'vector_search':
                return $this->vectorSearch($params, $message, $sessionId, $userId, $conversationHistory, $options);

            case 'model_tool':
                return $this->executeModelTool($params, $userId, $options);

            case 'route_to_node':
                return $this->routeToNode($params, $message, $sessionId, $userId, $conversationHistory, $options);

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

    /** @var array Last query state for pagination */
    protected static array $lastQueryState = [];

    /**
     * Tool: Direct DB query with pagination
     */
    protected function dbQuery(array $params, $userId, array $options, int $page = 1): array
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
            Log::info('dbQuery: '.$query->latest()->skip(0)->take($this->perPage)->toSql());
            Log::info('dbQuery: '.json_encode($query->latest()->skip(0)->take($this->perPage)->getBindings()));

            $query = $this->applyFilters($query, $filters, $modelClass);

            // Get total count for pagination info
            $totalCount = (clone $query)->count();
            $totalPages = (int) ceil($totalCount / $this->perPage);

            // Apply pagination
            $offset = ($page - 1) * $this->perPage;
            Log::info('dbQuery: '.$query->latest()->skip($offset)->take($this->perPage)->toSql());
            Log::info('dbQuery: '.json_encode($filters));
            Log::info('dbQuery: '.json_encode($query->latest()->skip($offset)->take($this->perPage)->getBindings()));
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

            // Store query state for pagination
            static::$lastQueryState = [
                'model' => $modelName,
                'model_class' => $modelClass,
                'filters' => $filters,
                'user_id' => $userId,
                'options' => $options,
                'page' => $page,
                'total_pages' => $totalPages,
                'total_count' => $totalCount,
            ];

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
            Log::channel('ai-engine')->error('db_query failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tool: Get next page of results
     */
    protected function dbQueryNext(array $params, $userId, array $options): array
    {
        // Check if we have a previous query state
        if (empty(static::$lastQueryState)) {
            return [
                'success' => false,
                'error' => 'No previous query to continue. Please make a query first.',
                'tool' => 'db_query_next',
            ];
        }

        $state = static::$lastQueryState;
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

        // Re-run the query with the next page
        $queryParams = [
            'model' => $state['model'],
            'filters' => $state['filters'] ?? [],
        ];

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
            $query = $this->applyFilters($query, $filters, $modelClass);

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
            $query = $this->applyFilters($query, $filters, $modelClass);

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
                    $values = $records->map(function($record) use ($methodName) {
                        return $record->$methodName();
                    })->filter(function($value) {
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

        if (!$modelName || !$toolName) {
            return ['success' => false, 'error' => 'Model and tool_name required'];
        }

        // Find model class
        $modelClass = $this->findModelClass($modelName, $options);
        if (!$modelClass) {
            return ['success' => false, 'error' => "Model {$modelName} not found"];
        }

        // Find config class
        $configClass = $this->findModelConfigClass($modelClass);
        if (!$configClass) {
            return ['success' => false, 'error' => "No config found for {$modelName}"];
        }

        try {
            // Get all tools
            $tools = $configClass::getTools();

            if (!isset($tools[$toolName])) {
                return ['success' => false, 'error' => "Tool {$toolName} not found for {$modelName}"];
            }

            $tool = $tools[$toolName];
            $handler = $tool['handler'] ?? null;

            if (!$handler || !is_callable($handler)) {
                return ['success' => false, 'error' => "Tool {$toolName} has no handler"];
            }

            // Check permissions
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

            // Execute tool handler
            Log::channel('ai-engine')->info('Executing model tool', [
                'model' => $modelName,
                'tool' => $toolName,
                'user_id' => $userId,
            ]);

            $result = $handler($toolParams);

            // Format response
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

                // Include suggested actions if provided by the tool
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
                array_merge($options, [
                    'user_id' => $userId,
                    'conversation_history' => $conversationHistory,
                    'rag_collections' => $collections,
                ])
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
        if (!$node || !$node->isHealthy()) {
            return ['success' => false, 'error' => "Node {$nodeSlug} not available"];
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
            $baseName = strtolower(class_basename($collection));
            if ($baseName === $modelName ||
                $baseName === $modelName . 's' ||
                $baseName . 's' === $modelName ||
                str_contains($baseName, $modelName)) {
                return $collection;
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

                if (class_exists($fullClass) &&
                    is_subclass_of($fullClass, \LaravelAIEngine\Contracts\AutonomousModelConfig::class)) {

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
    protected function applyFilters($query, array $filters, string $modelClass)
    {
        if (empty($filters)) {
            return $query;
        }

        $instance = new $modelClass;
        $table = $instance->getTable();

        // ID filter - for fetching specific record
        if (!empty($filters['id'])) {
            $query->where('id', $filters['id']);

            Log::channel('ai-engine')->info('Applied ID filter', [
                'id' => $filters['id'],
            ]);

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

}
