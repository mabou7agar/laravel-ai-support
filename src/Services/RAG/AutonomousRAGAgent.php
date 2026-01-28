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
                
                $models[] = [
                    'name' => strtolower($name),
                    'class' => $collection,
                    'table' => $instance->getTable() ?? strtolower($name) . 's',
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

AVAILABLE MODELS:
{$modelsJson}

AVAILABLE NODES (for routing):
{$nodesJson}

IS MASTER NODE: {$isMaster}

AVAILABLE TOOLS:

1. **answer_from_context**
   Use when: Answer is already in conversation history
   Speed: Instant (0ms)
   Example: User asks "1" after seeing a numbered list

2. **db_query**
   Use when: Simple list/fetch query, model supports db_query
   Speed: Very fast (20-50ms)
   Example: "list my invoices", "show my emails"
   
3. **db_count**
   Use when: Count/aggregate query
   Speed: Very fast (1-20ms)
   Example: "how many invoices", "count my emails"

4. **vector_search**
   Use when: Semantic search needed, model supports vector_search
   Speed: Slow (2-5s)
   Example: "find emails about marketing", "invoices from last month"
   
5. **route_to_node**
   Use when: Data is on a remote node
   Speed: Slow (5-10s)
   Example: Query about model that exists on another node

DECISION RULES:
- Prefer faster tools when possible (answer_from_context > db_query > db_count > vector_search)
- Use db_query for simple "list X" queries
- Use db_count for "how many X" queries
- Only use vector_search when semantic understanding is needed
- Check model capabilities before choosing tool
- If model doesn't support vector_search, use db_query instead

RESPOND WITH JSON ONLY:
{
  "tool": "answer_from_context|db_query|db_count|vector_search|route_to_node",
  "reasoning": "brief explanation of why this tool is best",
  "parameters": {
    "model": "model name (for db_query/db_count/vector_search)",
    "query": "search query (for vector_search)",
    "node": "node slug (for route_to_node)",
    "answer": "direct answer (for answer_from_context)"
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
            
            // Apply user filter
            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } else {
                $instance = new $modelClass;
                if (in_array('user_id', $instance->getFillable()) || 
                    \Schema::hasColumn($instance->getTable(), 'user_id')) {
                    $query->where('user_id', $userId);
                }
            }
            
            $items = $query->latest()->limit(10)->get();
            
            if ($items->isEmpty()) {
                return [
                    'success' => true,
                    'response' => "You don't have any {$modelName}s yet.",
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
            
            // Apply user filter
            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } else {
                $instance = new $modelClass;
                if (in_array('user_id', $instance->getFillable()) || 
                    \Schema::hasColumn($instance->getTable(), 'user_id')) {
                    $query->where('user_id', $userId);
                }
            }
            
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
}
