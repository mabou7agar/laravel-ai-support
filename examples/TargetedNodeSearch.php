<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\Node\FederatedSearchService;
use App\Models\Product;

/**
 * Example: Targeted Node and Collection Search
 * 
 * This example shows how to search a specific model on a specific node
 * instead of searching all collections across all nodes.
 */
class TargetedNodeSearchController extends Controller
{
    /**
     * Search specific model on specific node
     */
    public function searchProductsOnNode(Request $request, FederatedSearchService $federatedSearch)
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'node_id' => 'required|integer',
        ]);
        
        $user = auth()->user();
        $nodeId = $request->input('node_id');
        
        // Direct federated search to specific node and collection
        $results = $federatedSearch->search(
            query: $request->input('query'),
            nodeIds: [$nodeId],  // ✅ Target specific node
            limit: 10,
            options: [
                'collections' => [Product::class],  // ✅ Only search Product model
                'filters' => [
                    'workspace_id' => $user->workspace_id,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'results' => $results['results'] ?? [],
            'node' => $results['node'] ?? null,
            'count' => $results['count'] ?? 0,
        ]);
    }
    
    /**
     * Search multiple specific models on specific nodes
     */
    public function searchMultipleModelsOnNodes(Request $request, FederatedSearchService $federatedSearch)
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'node_ids' => 'required|array',
            'node_ids.*' => 'integer',
            'models' => 'required|array',
            'models.*' => 'string',
        ]);
        
        $user = auth()->user();
        
        // Map model names to classes
        $modelMap = [
            'products' => Product::class,
            'documents' => \App\Models\Document::class,
            'articles' => \App\Models\Article::class,
        ];
        
        $collections = array_map(
            fn($model) => $modelMap[$model] ?? null,
            $request->input('models')
        );
        $collections = array_filter($collections);
        
        // Search specific models on specific nodes
        $results = $federatedSearch->search(
            query: $request->input('query'),
            nodeIds: $request->input('node_ids'),  // ✅ Multiple specific nodes
            limit: 20,
            options: [
                'collections' => $collections,  // ✅ Only specified models
                'filters' => [
                    'workspace_id' => $user->workspace_id,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'results' => $results['results'] ?? [],
            'nodes_searched' => $results['nodes'] ?? [],
            'count' => $results['count'] ?? 0,
        ]);
    }
    
    /**
     * Use RAG with targeted search
     */
    public function ragWithTargetedSearch(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
            'node_ids' => 'array',
            'node_ids.*' => 'integer',
        ]);
        
        $user = auth()->user();
        
        // Use RAG but limit to specific nodes and collections
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "targeted_" . session()->getId(),
            availableCollections: [Product::class],  // ✅ Only Product model
            conversationHistory: [],
            options: [
                'node_ids' => $request->input('node_ids', []),  // ✅ Specific nodes
                'filters' => [
                    'workspace_id' => $user->workspace_id,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
                'max_context' => 10,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
        ]);
    }
    
    /**
     * Search local node only (no federation)
     */
    public function searchLocalOnly(Request $request, IntelligentRAGService $rag)
    {
        $request->validate([
            'query' => 'required|string|max:500',
        ]);
        
        $user = auth()->user();
        
        // Disable federated search in config or use local search directly
        $response = $rag->processMessage(
            message: $request->input('query'),
            sessionId: "local_" . session()->getId(),
            availableCollections: [Product::class],  // ✅ Single model
            conversationHistory: [],
            options: [
                'use_federated' => false,  // ✅ Force local search only
                'filters' => [
                    'workspace_id' => $user->workspace_id,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
            ],
            userId: $user->id
        );
        
        return response()->json([
            'success' => true,
            'response' => $response->getContent(),
            'sources' => $response->getMetadata()['sources'] ?? [],
            'note' => 'Searched local node only',
        ]);
    }
}

/**
 * Node-Specific Product Search Service
 * 
 * For more control, create a dedicated service
 */
class NodeSpecificSearchService
{
    public function __construct(
        private FederatedSearchService $federatedSearch
    ) {}
    
    /**
     * Search products on a specific node
     */
    public function searchProductsOnNode(
        string $query,
        int $nodeId,
        int $workspaceId,
        ?int $userId = null
    ): array {
        return $this->federatedSearch->search(
            query: $query,
            nodeIds: [$nodeId],
            limit: 20,
            options: [
                'collections' => [Product::class],
                'filters' => [
                    'workspace_id' => $workspaceId,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
            ],
            userId: $userId
        );
    }
    
    /**
     * Search products across specific workspace nodes
     */
    public function searchProductsInWorkspace(
        string $query,
        int $workspaceId,
        ?int $userId = null
    ): array {
        // Get nodes that belong to this workspace
        $workspaceNodes = \LaravelAIEngine\Models\Node::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->pluck('id')
            ->toArray();
        
        if (empty($workspaceNodes)) {
            // Fallback to local search
            return [
                'results' => [],
                'count' => 0,
                'note' => 'No active nodes found for workspace',
            ];
        }
        
        return $this->federatedSearch->search(
            query: $query,
            nodeIds: $workspaceNodes,  // ✅ Only workspace nodes
            limit: 50,
            options: [
                'collections' => [Product::class],
                'filters' => [
                    'workspace_id' => $workspaceId,
                ],
                'skip_user_filter' => true,
                'threshold' => 0.7,
            ],
            userId: $userId
        );
    }
}

/**
 * Routes
 */
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    // Search specific model on specific node
    Route::post('/search/node/{node_id}/products', [TargetedNodeSearchController::class, 'searchProductsOnNode']);
    
    // Search multiple models on multiple nodes
    Route::post('/search/targeted', [TargetedNodeSearchController::class, 'searchMultipleModelsOnNodes']);
    
    // RAG with targeted search
    Route::post('/search/rag/targeted', [TargetedNodeSearchController::class, 'ragWithTargetedSearch']);
    
    // Local search only
    Route::post('/search/local', [TargetedNodeSearchController::class, 'searchLocalOnly']);
});

/**
 * Usage Examples
 */

// Example 1: Search products on node 5
$response = Http::post('/api/search/node/5/products', [
    'query' => 'budget planning tools',
]);

// Example 2: Search products and documents on nodes 3 and 5
$response = Http::post('/api/search/targeted', [
    'query' => 'budget planning',
    'node_ids' => [3, 5],
    'models' => ['products', 'documents'],
]);

// Example 3: RAG search limited to specific nodes
$response = Http::post('/api/search/rag/targeted', [
    'query' => 'What budget products do we have?',
    'node_ids' => [3, 5],
]);

// Example 4: Local search only (no federation)
$response = Http::post('/api/search/local', [
    'query' => 'local products only',
]);
