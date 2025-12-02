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
            $collections = $this->discoverCollections();
            
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
     * Discover all Vectorizable models in the application
     */
    protected function discoverCollections(): array
    {
        $collections = [];
        $modelsPath = app_path('Models');
        
        if (!is_dir($modelsPath)) {
            return $collections;
        }
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($modelsPath, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
        } catch (\Exception $e) {
            \Log::error('Failed to iterate models directory', [
                'path' => $modelsPath,
                'error' => $e->getMessage(),
            ]);
            return $collections;
        }
        
        foreach ($files as $file) {
            try {
                if ($file->isDir() || $file->getExtension() !== 'php') {
                    continue;
                }
                
                $relativePath = str_replace($modelsPath . '/', '', $file->getPathname());
                $className = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $relativePath);
                
                // Try to check if class exists without triggering fatal errors
                try {
                    if (!class_exists($className, true)) {
                        continue;
                    }
                } catch (\Error $e) {
                    // Fatal error during class loading (e.g., static method conflicts)
                    \Log::debug('Cannot load class during collection discovery', [
                        'class' => $className,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                
                // Check if class uses Vectorizable trait
                try {
                    $uses = class_uses_recursive($className);
                    $hasVectorizable = in_array(\LaravelAIEngine\Traits\Vectorizable::class, $uses) ||
                                      in_array(\LaravelAIEngine\Traits\VectorizableWithMedia::class, $uses);
                } catch (\Error $e) {
                    \Log::debug('Cannot check traits for class', [
                        'class' => $className,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
                
                if ($hasVectorizable) {
                    // Safely get table name
                    try {
                        $instance = new $className;
                        $tableName = $instance->getTable();
                    } catch (\Exception | \Error $e) {
                        $tableName = 'unknown';
                        \Log::debug('Cannot instantiate class for table name', [
                            'class' => $className,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    
                    $collections[] = [
                        'class' => $className,
                        'name' => class_basename($className),
                        'table' => $tableName,
                    ];
                }
            } catch (\Exception | \Error $e) {
                // Skip problematic files
                \Log::debug('Skipped model file during collection discovery', [
                    'file' => $file->getPathname(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }
        
        return $collections;
    }
    
    /**
     * Search endpoint (for remote nodes to call)
     */
    public function search(Request $request, VectorSearchService $searchService)
    {
        $validated = $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:100',
            'options' => 'array',
        ]);
        
        $startTime = microtime(true);
        
        try {
            $collections = $validated['options']['collections'] ?? [];
            $results = [];
            
            foreach ($collections as $collection) {
                if (!class_exists($collection)) {
                    continue;
                }
                
                $searchResults = $searchService->search(
                    $collection,
                    $validated['query'],
                    $validated['limit'] ?? 10,
                    $validated['options']['threshold'] ?? 0.7
                );
                
                foreach ($searchResults as $result) {
                    $results[] = [
                        'id' => $result->id ?? null,
                        'content' => $this->extractContent($result),
                        'score' => $result->vector_score ?? 0,
                        'model_class' => $collection,
                        'model_type' => class_basename($collection),
                    ];
                }
            }
            
            $duration = (microtime(true) - $startTime) * 1000;
            
            return response()->json([
                'results' => $results,
                'count' => count($results),
                'duration_ms' => round($duration, 2),
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Search failed',
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
