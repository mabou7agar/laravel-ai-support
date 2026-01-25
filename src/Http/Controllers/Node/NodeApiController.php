<?php

namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeAuthService;
use LaravelAIEngine\Services\Vector\VectorSearchService;

class NodeApiController extends Controller
{
    /**
     * Health check endpoint
     */
    public function health()
    {
        return response()->json([
            'status' => 'healthy',
            'version' => config('ai-engine.version', '1.0.0'),
            'capabilities' => config('ai-engine.nodes.capabilities', ['search', 'actions']),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Execute AI action on a model
     */
    public function execute(Request $request)
    {
        try {
            $modelClass = $request->input('model_class');
            $action = $request->input('action', 'create');
            $params = $request->input('params', []);

            if (!$modelClass) {
                return response()->json([
                    'success' => false,
                    'error' => 'Model class is required'
                ], 400);
            }

            if (!class_exists($modelClass)) {
                return response()->json([
                    'success' => false,
                    'error' => "Model class {$modelClass} not found"
                ], 404);
            }

            $reflection = new \ReflectionClass($modelClass);
            
            if (!$reflection->hasMethod('executeAI')) {
                return response()->json([
                    'success' => false,
                    'error' => "Model {$modelClass} does not have executeAI method"
                ], 400);
            }

            $method = $reflection->getMethod('executeAI');

            // Execute the model's AI action
            if ($method->isStatic()) {
                $result = $modelClass::executeAI($action, $params);
            } else {
                $model = new $modelClass();
                $result = $model->executeAI($action, $params);
            }

            // Format response
            if (is_array($result) && isset($result['success'])) {
                return response()->json($result);
            } elseif (is_object($result)) {
                // Model instance returned
                return response()->json([
                    'success' => true,
                    'data' => method_exists($result, 'toArray') ? $result->toArray() : (array) $result,
                    'id' => $result->id ?? null
                ]);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => $result
                ]);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Execute AI action failed', [
                'model' => $request->input('model_class'),
                'action' => $request->input('action'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Collections discovery endpoint
     * Returns all available Vectorizable and HasAIActions models on this node
     */
    public function collections()
    {
        try {
            $discoveryService = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            // Get local collections only (no federated, no cache for fresh results)
            $classNames = $discoveryService->discover(useCache: false, includeFederated: false);
            
            // Also discover models with HasAIActions trait
            $aiActionModels = $this->discoverAIActionModels();
            
            // Merge both lists (remove duplicates)
            $classNames = array_unique(array_merge($classNames, $aiActionModels));
            
            // Format as detailed collection info with RAG descriptions
            $collections = [];
            foreach ($classNames as $className) {
                try {
                    $name = class_basename($className);
                    $description = '';
                    $displayName = $name;
                    $table = 'unknown';
                    
                    // Use reflection to check if methods are static
                    $reflection = new \ReflectionClass($className);
                    
                    if ($reflection->hasMethod('getRAGDescription')) {
                        $method = $reflection->getMethod('getRAGDescription');
                        if ($method->isStatic()) {
                            $description = $className::getRAGDescription();
                        } else {
                            // Fallback to instance method
                            try {
                                $instance = new $className;
                                $description = $instance->getRAGDescription();
                            } catch (\Exception $e) {
                                // Ignore
                            }
                        }
                    }
                    
                    if ($reflection->hasMethod('getRAGDisplayName')) {
                        $method = $reflection->getMethod('getRAGDisplayName');
                        if ($method->isStatic()) {
                            $displayName = $className::getRAGDisplayName();
                        } else {
                            // Fallback to instance method
                            try {
                                if (!isset($instance)) {
                                    $instance = new $className;
                                }
                                $displayName = $instance->getRAGDisplayName();
                            } catch (\Exception $e) {
                                // Ignore
                            }
                        }
                    }
                    
                    // Get table name
                    try {
                        if (!isset($instance)) {
                            $instance = new $className;
                        }
                        $table = $instance->getTable();
                    } catch (\Exception $e) {
                        // Couldn't instantiate, use default
                    }
                    
                    // Check for AI action methods
                    $methods = [];
                    if ($reflection->hasMethod('executeAI')) {
                        $methods[] = 'executeAI';
                    }
                    if ($reflection->hasMethod('initializeAI')) {
                        $methods[] = 'initializeAI';
                    }
                    
                    // Get format from initializeAI if available
                    $format = null;
                    if ($reflection->hasMethod('initializeAI')) {
                        try {
                            $method = $reflection->getMethod('initializeAI');
                            if ($method->isStatic()) {
                                $format = $className::initializeAI();
                            } else {
                                // Call as instance method
                                if (!isset($instance)) {
                                    $instance = new $className;
                                }
                                $format = $instance->initializeAI();
                            }
                        } catch (\Exception $e) {
                            // Ignore errors
                        }
                    }
                    
                    $collections[] = [
                        'class' => $className,
                        'name' => $name,
                        'display_name' => $displayName,
                        'table' => $table,
                        'description' => $description,  // ✅ RAG description for AI
                        'methods' => $methods,  // ✅ AI action methods
                        'format' => $format,  // ✅ Expected format for AI extraction
                    ];
                } catch (\Exception $e) {
                    $collections[] = [
                        'class' => $className,
                        'name' => class_basename($className),
                        'display_name' => class_basename($className),
                        'table' => 'unknown',
                        'description' => '',
                        'methods' => [],
                        'format' => null,
                    ];
                }
            }
            
            return response()->json([
                'collections' => $collections,
                'count' => count($collections),
                'timestamp' => now()->toIso8601String(),
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to discover collections',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Discover models with HasAIActions trait
     */
    protected function discoverAIActionModels(): array
    {
        $models = [];
        $paths = config('ai-engine.intelligent_rag.discovery_paths', [app_path('Models')]);
        
        // Expand glob patterns
        $expandedPaths = [];
        foreach ($paths as $path) {
            if (str_contains($path, '*')) {
                $globbed = glob($path);
                $expandedPaths = array_merge($expandedPaths, $globbed);
            } else {
                $expandedPaths[] = $path;
            }
        }
        
        foreach ($expandedPaths as $path) {
            if (!is_dir($path)) {
                continue;
            }
            
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path)
            );
            
            foreach ($files as $file) {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                
                $content = file_get_contents($file->getPathname());
                
                // Extract namespace
                if (preg_match('/namespace\s+([^;]+);/', $content, $nsMatch)) {
                    $namespace = $nsMatch[1];
                    
                    // Extract class name
                    if (preg_match('/class\s+(\w+)/', $content, $classMatch)) {
                        $className = $namespace . '\\' . $classMatch[1];
                        
                        // Check if class uses HasAIActions trait
                        if (class_exists($className)) {
                            $reflection = new \ReflectionClass($className);
                            $traits = $reflection->getTraitNames();
                            
                            if (in_array('LaravelAIEngine\\Traits\\HasAIActions', $traits)) {
                                $models[] = $className;
                            }
                        }
                    }
                }
            }
        }
        
        return $models;
    }
    
    /**
     * Search endpoint (for remote nodes to call)
     */
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:100',
            'options' => 'array',
        ]);
        
        $startTime = microtime(true);
        
        try {
            $collections = $validated['options']['collections'] ?? [];
            $userId = $validated['options']['user_id'] ?? null;
            $filters = $validated['options']['filters'] ?? [];
            $results = [];
            
            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }
                
                // Use the model's vectorSearch trait method directly
                // This works regardless of whether VectorSearchService is registered
                $searchResults = $collection::vectorSearch(
                    query: $validated['query'],
                    limit: $validated['limit'] ?? 10,
                    threshold: $validated['options']['threshold'] ?? 0.0, // Use 0.0 to get all results, let caller filter
                    filters: $filters,
                    userId: $userId
                );
                
                \Log::info('NodeApiController: vectorSearch returned', [
                    'collection' => $collection,
                    'count' => count($searchResults),
                    'is_collection' => $searchResults instanceof \Illuminate\Support\Collection,
                    'first_item_class' => $searchResults->first() ? get_class($searchResults->first()) : null,
                ]);
                
                foreach ($searchResults as $result) {
                    \Log::info('NodeApiController: Adding result with metadata', [
                        'id' => $result->id,
                        'collection' => $collection,
                    ]);
                    
                    $results[] = [
                        'id' => $result->id ?? null,
                        'content' => $this->extractContent($result),
                        'score' => $result->vector_score ?? 0,
                        'model_class' => $collection,
                        'model_type' => class_basename($collection),
                        // Include metadata for enrichResponseWithSources to use
                        'metadata' => [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                            'model_id' => $result->id ?? null,
                        ],
                        // Also include vector_metadata if it exists on the result
                        'vector_metadata' => $result->vector_metadata ?? [
                            'model_class' => $collection,
                            'model_type' => class_basename($collection),
                        ],
                        // Include additional fields for display
                        'title' => $result->title ?? $result->name ?? $result->subject ?? null,
                        'name' => $result->name ?? null,
                        'body' => $result->body ?? null,
                    ];
                }
            }
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            \Log::info('NodeApiController: About to return response', [
                'results_count' => count($results),
                'results_ids' => array_column($results, 'id'),
                'results_sample' => array_slice($results, 0, 1),
            ]);
            
            $responseData = [
                'results' => $results,
                'count' => count($results),
                'duration_ms' => round($duration, 2),
            ];
            
            \Log::info('NodeApiController: Response data prepared', [
                'response_count' => $responseData['count'],
                'response_results_count' => count($responseData['results']),
                'response_json_preview' => substr(json_encode($responseData), 0, 500),
            ]);
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Aggregate data endpoint
     * Returns counts and stats for specified collections
     */
    public function aggregate(Request $request)
    {
        $validated = $request->validate([
            'collections' => 'required|array',
            'collections.*' => 'string',
            'user_id' => 'nullable|integer',
        ]);
        
        $startTime = microtime(true);
        
        try {
            $collections = $validated['collections'];
            $userId = $validated['user_id'] ?? null;
            $aggregateData = [];
            
            $vectorSearch = app(VectorSearchService::class);
            
            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }
                
                try {
                    $instance = new $collection();
                    $displayName = class_basename($collection);
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
                    
                    // Get count from vector database
                    $vectorCount = $vectorSearch->getIndexedCountWithFilters($collection, $filters);
                    
                    $aggregateData[$collection] = [
                        'count' => $vectorCount,
                        'indexed_count' => $vectorCount,
                        'display_name' => $displayName,
                        'description' => $description,
                        'source' => 'vector_database',
                    ];
                    
                } catch (\Exception $e) {
                    \Log::warning('Failed to get aggregate for collection', [
                        'collection' => $collection,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            return response()->json([
                'success' => true,
                'aggregate_data' => $aggregateData,
                'count' => count($aggregateData),
                'duration_ms' => round($duration, 2),
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Aggregate query failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Chat endpoint - forward entire chat/workflow to this node
     * This allows the master node to delegate chat handling to child nodes
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'session_id' => 'required|string',
            'user_id' => 'nullable',
            'options' => 'array',
        ]);
        
        $startTime = microtime(true);
        
        try {
            // Get ChatService to process the message
            $chatService = app(\LaravelAIEngine\Services\ChatService::class);
            
            $options = $validated['options'] ?? [];
            
            $response = $chatService->processMessage(
                message: $validated['message'],
                sessionId: $validated['session_id'],
                engine: $options['engine'] ?? 'openai',
                model: $options['model'] ?? 'gpt-4o-mini',
                useMemory: $options['use_memory'] ?? true,
                useActions: $options['use_actions'] ?? true,
                useIntelligentRAG: $options['use_intelligent_rag'] ?? true,
                ragCollections: $options['rag_collections'] ?? [],
                userId: $validated['user_id']
            );
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            \Log::channel('ai-engine')->info('NodeApiController: Chat processed', [
                'session_id' => $validated['session_id'],
                'user_id' => $validated['user_id'],
                'duration_ms' => round($duration, 2),
            ]);
            
            // Only return essential metadata to reduce response size and memory usage
            $fullMetadata = $response->getMetadata();
            $essentialMetadata = [
                'workflow_active' => $fullMetadata['workflow_active'] ?? false,
                'workflow_class' => $fullMetadata['workflow_class'] ?? null,
                'workflow_completed' => $fullMetadata['workflow_completed'] ?? false,
                'current_step' => $fullMetadata['current_step'] ?? null,
                'agent_strategy' => $fullMetadata['agent_strategy'] ?? null,
            ];
            
            return response()->json([
                'success' => true,
                'response' => $response->getContent(),
                'metadata' => $essentialMetadata,
                'duration_ms' => round($duration, 2),
            ]);
            
        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('NodeApiController: Chat failed', [
                'session_id' => $validated['session_id'],
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Chat processing failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * Action execution endpoint
     */
    public function executeAction(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string',
            'params' => 'array',
        ]);
        
        try {
            // Execute action based on type
            $result = match($validated['action']) {
                'index' => $this->handleIndexAction($validated['params']),
                'delete' => $this->handleDeleteAction($validated['params']),
                'update' => $this->handleUpdateAction($validated['params']),
                'sync' => $this->handleSyncAction($validated['params']),
                default => throw new \Exception("Unknown action: {$validated['action']}"),
            };
            
            return response()->json([
                'success' => true,
                'action' => $validated['action'],
                'result' => $result,
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Node registration endpoint
     */
    public function register(Request $request, NodeRegistryService $registry, NodeAuthService $authService)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'capabilities' => 'array',
            'metadata' => 'array',
            'version' => 'string',
        ]);
        
        try {
            $node = $registry->register($validated);
            $authResponse = $authService->generateAuthResponse($node);
            
            return response()->json([
                'success' => true,
                'node' => [
                    'id' => $node->id,
                    'slug' => $node->slug,
                    'name' => $node->name,
                ],
                'auth' => $authResponse,
            ], 201);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        }
    }
    
    /**
     * Node status endpoint
     */
    public function status(Request $request, NodeRegistryService $registry)
    {
        $node = $request->attributes->get('node');
        
        if (!$node) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        return response()->json($registry->getHealthReport($node));
    }
    
    /**
     * Refresh token endpoint
     */
    public function refreshToken(Request $request, NodeAuthService $authService)
    {
        $validated = $request->validate([
            'refresh_token' => 'required|string',
        ]);
        
        $result = $authService->refreshAccessToken($validated['refresh_token']);
        
        if (!$result) {
            return response()->json([
                'error' => 'Invalid refresh token',
            ], 401);
        }
        
        return response()->json($result);
    }
    
    // Action handlers
    protected function handleIndexAction(array $params): array
    {
        return ['message' => 'Index action executed', 'params' => $params];
    }
    
    protected function handleDeleteAction(array $params): array
    {
        return ['message' => 'Delete action executed', 'params' => $params];
    }
    
    protected function handleUpdateAction(array $params): array
    {
        return ['message' => 'Update action executed', 'params' => $params];
    }
    
    protected function handleSyncAction(array $params): array
    {
        return ['message' => 'Sync action executed', 'params' => $params];
    }
    
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
}
