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
        $decision = $this->getAIDecision($message, $context);
        
        Log::channel('ai-engine')->info('AutonomousRAGAgent: AI decision', [
            'session_id' => $sessionId,
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
    protected function getAIDecision(string $message, array $context): array
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
   Supports filters based on model schema fields

3. **db_count** - Use for count/aggregate queries (fast 1-20ms)
   Supports filters based on model schema fields

4. **vector_search** - Use for semantic search (slow 2-5s)
   Only when semantic understanding is needed

5. **route_to_node** - Use when data is on remote node (slow 5-10s)

FILTER RULES:
- If model has "filter_config", USE those field names directly (they are correct)
- If no filter_config, look at "schema" to find field names and types
- Convert user date formats (DD-MM-YYYY) to YYYY-MM-DD

RESPOND WITH JSON ONLY:
{
  "tool": "answer_from_context|db_query|db_count|vector_search|route_to_node",
  "reasoning": "brief explanation",
  "parameters": {
    "model": "model name",
    "query": "search query (for vector_search)",
    "node": "node slug (for route_to_node)",
    "answer": "direct answer (for answer_from_context)",
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
                ->model('gpt-4o-mini')
                ->withTemperature(0.1)
                ->withMaxTokens(500)
                ->generate($prompt);
            
            $content = trim($response->getContent());
            
            // Parse JSON
            if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
                $decision = json_decode($matches[0], true);
                if ($decision && isset($decision['tool'])) {
                    return $decision;
                }
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

    /**
     * Tool: Direct DB query
     */
    protected function dbQuery(array $params, $userId, array $options): array
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
            
            $items = $query->latest()->limit(10)->get();
            
            if ($items->isEmpty()) {
                return [
                    'success' => true,
                    'response' => "No {$modelName}s found matching your criteria.",
                    'tool' => 'db_query',
                    'fast_path' => true,
                    'count' => 0,
                ];
            }
            
            // Format response - use model's own formatting method
            $response = "Here are your {$modelName}s:\n\n";
            foreach ($items as $i => $item) {
                $num = $i + 1;
                
                // Always prefer model's own formatting
                if (method_exists($item, 'toRAGContent')) {
                    $response .= "{$num}. " . $item->toRAGContent() . "\n\n";
                } elseif (method_exists($item, '__toString')) {
                    $response .= "{$num}. " . $item->__toString() . "\n";
                } else {
                    // Last resort: show as JSON (let AI format it nicely)
                    $response .= "{$num}. " . json_encode($item->toArray()) . "\n";
                }
            }
            
            return [
                'success' => true,
                'response' => trim($response),
                'tool' => 'db_query',
                'fast_path' => true,
                'count' => $items->count(),
            ];
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_query failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
