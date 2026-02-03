<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
        $startTime = microtime(true);

        // Build context for AI with available tools and models
        $context = $this->buildAIContext($message, $conversationHistory, $userId, $options);

        // Single AI call to decide what to do
        $model = $options['model'] ?? 'gpt-4o-mini';
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

                $models[] = [
                    'name' => strtolower($name),
                    'class' => $collection,
                    'table' => $instance->getTable() ?? strtolower($name) . 's',
                    'schema' => $schema, // AI uses this to decide filter fields
                    'filter_config' => $filterConfig, // From AutonomousConfig class
                    'capabilities' => [
                        'db_query' => true, // All models support direct DB
                        'db_count' => true,
                        'vector_search' => $hasVectorSearch, // Only if vectorized
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

        $prompt = <<<PROMPT
You are an intelligent query router. Analyze the user's request and choose the BEST tool to handle it.

USER MESSAGE: "{$message}"

CONVERSATION HISTORY:
{$conversationSummary}

AVAILABLE MODELS (with schema):
{$modelsJson}

AVAILABLE NODES (for routing):
{$nodesJson}

IS MASTER NODE: {$isMaster}

AVAILABLE TOOLS:

1. **answer_from_context** - Use when answer is in conversation history (instant)

2. **db_query** - Use for list/fetch queries (fast 20-50ms)
   Supports filters and pagination (page parameter, default page 1)
   ONLY if model exists in AVAILABLE MODELS list above

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

7. **route_to_node** - Use when data is on remote node (slow 5-10s)
   Use this when requested model is NOT in AVAILABLE MODELS but IS in a node's models list

CRITICAL ROUTING RULE:
- First check if requested model exists in AVAILABLE MODELS list
- If model NOT found in AVAILABLE MODELS, check AVAILABLE NODES
- If model found in a node's models list, use route_to_node with that node's slug
- If model not found anywhere, respond with error

FILTER RULES:
- If model has "filter_config", USE those field names directly (they are correct)
- If no filter_config, look at "schema" to find field names and types
- Convert user date formats (DD-MM-YYYY) to YYYY-MM-DD

RESPOND WITH JSON ONLY:
{
  "tool": "answer_from_context|db_query|db_query_next|db_count|db_aggregate|vector_search|route_to_node",
  "reasoning": "brief explanation",
  "parameters": {
    "model": "model name",
    "query": "search query (for vector_search)",
    "node": "node slug (for route_to_node)",
    "answer": "direct answer (for answer_from_context)",
    "aggregate": {
      "operation": "sum|avg|min|max",
      "field": "field name from filter_config.amount_field or schema"
    },
    "filters": {
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

            // Verify field exists
            if (!\Schema::hasColumn($table, $field)) {
                return ['success' => false, 'error' => "Field {$field} not found in {$modelName}"];
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

            // Execute aggregation
            $validOperations = ['sum', 'avg', 'min', 'max'];
            if (!in_array($operation, $validOperations)) {
                $operation = 'sum';
            }

            $result = $query->$operation($field);
            $count = $query->count();

            // Format response based on operation
            $operationLabels = [
                'sum' => 'Total',
                'avg' => 'Average',
                'min' => 'Minimum',
                'max' => 'Maximum',
            ];
            $label = $operationLabels[$operation] ?? 'Result';

            // Format number nicely
            $formattedResult = is_numeric($result) ? number_format($result, 2) : $result;

            Log::channel('ai-engine')->info('Applied aggregation', [
                'operation' => $operation,
                'field' => $field,
                'result' => $result,
                'count' => $count,
            ]);

            return [
                'success' => true,
                'response' => "**{$label} {$field}**: \${$formattedResult} (from {$count} {$modelName}s)",
                'tool' => 'db_aggregate',
                'fast_path' => true,
                'result' => $result,
                'count' => $count,
                'operation' => $operation,
                'field' => $field,
            ];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_aggregate failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
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
            $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);

            $response = $router->forwardChat(
                $node,
                $message,
                $sessionId,
                array_merge($options, [
                    'conversation_history' => $conversationHistory,
                ]),
                $userId
            );

            if ($response['success']) {
                Cache::put("session_last_node:{$sessionId}", $nodeSlug, now()->addMinutes(30));

                return [
                    'success' => true,
                    'response' => $response['response'],
                    'tool' => 'route_to_node',
                    'node' => $nodeSlug,
                    'metadata' => $response['metadata'] ?? [],
                ];
            }

            return ['success' => false, 'error' => $response['error'] ?? 'Routing failed'];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('route_to_node failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Find model class from name using discovery
     */
    protected function findModelClass(string $modelName, array $options): ?string
    {
        $collections = $options['rag_collections'] ?? [];

        // Discover if not provided
        if (empty($collections) && $this->discovery) {
            $collections = $this->discovery->discover();
        }

        $modelName = strtolower($modelName);

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
     * Get filter config for a model from AutonomousConfig classes
     */
    protected function getFilterConfigForModel(string $modelClass): array
    {
        // Get discovered collectors
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
