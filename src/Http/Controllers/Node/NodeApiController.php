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
     * Collections discovery endpoint
     * Returns all available Vectorizable collections on this node
     */
    public function collections()
    {
        try {
            $discoveryService = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            // Get local collections only (no federated, no cache for fresh results)
            $classNames = $discoveryService->discover(useCache: false, includeFederated: false);
            
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
                    
                    $collections[] = [
                        'class' => $className,
                        'name' => $name,
                        'display_name' => $displayName,
                        'table' => $table,
                        'description' => $description,  // ✅ RAG description for AI
                    ];
                } catch (\Exception $e) {
                    $collections[] = [
                        'class' => $className,
                        'name' => class_basename($className),
                        'display_name' => class_basename($className),
                        'table' => 'unknown',
                        'description' => '',
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
                    threshold: $validated['options']['threshold'] ?? 0.3,
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
