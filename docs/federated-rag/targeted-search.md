# Targeted Node and Collection Search

This guide explains how to search **specific models on specific nodes** instead of searching all collections across all nodes in a federated network.

## Problem

By default, federated search queries **all nodes** and **all specified collections**. This can be inefficient when:
- You know exactly which node has the data you need
- You want to search only one model (e.g., Products) not all models
- You're building workspace-specific features where data is partitioned by node
- You want to reduce network overhead and improve response times

## Solution: Targeted Search

### 1. Direct Federated Search (Recommended for API endpoints)

Use `FederatedSearchService` directly to target specific nodes and collections:

```php
use LaravelAIEngine\Services\Node\FederatedSearchService;
use App\Models\Product;

public function searchProducts(Request $request, FederatedSearchService $federatedSearch)
{
    $results = $federatedSearch->search(
        query: "budget planning tools",
        nodeIds: [3, 5],  // ✅ Only search nodes 3 and 5
        limit: 20,
        options: [
            'collections' => [Product::class],  // ✅ Only search Product model
            'filters' => [
                'workspace_id' => auth()->user()->workspace_id,
            ],
            'skip_user_filter' => true,
            'threshold' => 0.7,
        ],
        userId: auth()->id()
    );
    
    return response()->json($results);
}
```

### 2. RAG with Targeted Search

Use `IntelligentRAGService` with `node_ids` option:

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

public function ragSearch(Request $request, IntelligentRAGService $rag)
{
    $response = $rag->processMessage(
        message: "What budget products do we have?",
        sessionId: session()->getId(),
        availableCollections: [Product::class],  // ✅ Only Product model
        conversationHistory: [],
        options: [
            'node_ids' => [3, 5],  // ✅ Only nodes 3 and 5
            'filters' => [
                'workspace_id' => auth()->user()->workspace_id,
            ],
            'skip_user_filter' => true,
            'threshold' => 0.7,
        ],
        userId: auth()->id()
    );
    
    return response()->json([
        'response' => $response->getContent(),
        'sources' => $response->getMetadata()['sources'] ?? [],
    ]);
}
```

### 3. Local Search Only (No Federation)

Search only the local node without contacting remote nodes:

```php
$response = $rag->processMessage(
    message: "local products",
    sessionId: session()->getId(),
    availableCollections: [Product::class],
    options: [
        'use_federated' => false,  // ✅ Disable federation
        'filters' => ['workspace_id' => $workspaceId],
        'skip_user_filter' => true,
    ],
    userId: auth()->id()
);
```

## Use Cases

### Use Case 1: Workspace-Specific Product Search

Each workspace has its own node. Search only that workspace's node:

```php
class WorkspaceProductController extends Controller
{
    public function search(Request $request, FederatedSearchService $federatedSearch)
    {
        $workspace = auth()->user()->workspace;
        $nodeId = $workspace->node_id;  // Each workspace has a node
        
        $results = $federatedSearch->search(
            query: $request->input('query'),
            nodeIds: [$nodeId],  // ✅ Only this workspace's node
            limit: 20,
            options: [
                'collections' => [Product::class],
                'filters' => [
                    'workspace_id' => $workspace->id,
                ],
                'skip_user_filter' => true,
            ],
            userId: auth()->id()
        );
        
        return response()->json($results);
    }
}
```

### Use Case 2: Multi-Region Search

Search products across specific regional nodes:

```php
public function searchRegionalProducts(Request $request, FederatedSearchService $federatedSearch)
{
    $region = $request->input('region', 'us-east');
    
    // Get nodes in this region
    $nodeIds = Node::where('region', $region)
        ->where('status', 'active')
        ->pluck('id')
        ->toArray();
    
    $results = $federatedSearch->search(
        query: $request->input('query'),
        nodeIds: $nodeIds,  // ✅ Only regional nodes
        limit: 50,
        options: [
            'collections' => [Product::class],
            'filters' => [
                'is_active' => true,
            ],
            'skip_user_filter' => true,
        ],
        userId: auth()->id()
    );
    
    return response()->json($results);
}
```

### Use Case 3: Single Model on Single Node

Most efficient - search one model on one node:

```php
public function searchProductsOnNode(int $nodeId, string $query, FederatedSearchService $federatedSearch)
{
    $results = $federatedSearch->search(
        query: $query,
        nodeIds: [$nodeId],  // ✅ Single node
        limit: 10,
        options: [
            'collections' => [Product::class],  // ✅ Single model
            'filters' => [
                'workspace_id' => auth()->user()->workspace_id,
            ],
            'skip_user_filter' => true,
        ],
        userId: auth()->id()
    );
    
    return $results;
}
```

### Use Case 4: Dynamic Node Selection

Select nodes based on data characteristics:

```php
public function searchWithDynamicNodes(Request $request, FederatedSearchService $federatedSearch)
{
    $query = $request->input('query');
    
    // Determine which nodes to search based on query
    $nodeIds = $this->selectNodesForQuery($query);
    
    $results = $federatedSearch->search(
        query: $query,
        nodeIds: $nodeIds,  // ✅ Dynamically selected nodes
        limit: 20,
        options: [
            'collections' => [Product::class, Document::class],
            'filters' => [
                'workspace_id' => auth()->user()->workspace_id,
            ],
            'skip_user_filter' => true,
        ],
        userId: auth()->id()
    );
    
    return response()->json($results);
}

private function selectNodesForQuery(string $query): array
{
    // Example: Search financial nodes for budget queries
    if (str_contains(strtolower($query), 'budget') || str_contains(strtolower($query), 'financial')) {
        return Node::where('type', 'financial')->pluck('id')->toArray();
    }
    
    // Example: Search product nodes for product queries
    if (str_contains(strtolower($query), 'product')) {
        return Node::where('type', 'products')->pluck('id')->toArray();
    }
    
    // Default: search all active nodes
    return Node::where('status', 'active')->pluck('id')->toArray();
}
```

## Performance Benefits

### Before (Search All Nodes + All Collections)
```
Query: "budget products"
Nodes Searched: 10 nodes (all active)
Collections: [Product, Document, Article, User]
Network Calls: 10 HTTP requests
Response Time: 2.5 seconds
Results: 500 items (mostly irrelevant)
```

### After (Targeted Search)
```
Query: "budget products"
Nodes Searched: 1 node (workspace node)
Collections: [Product]
Network Calls: 1 HTTP request
Response Time: 0.3 seconds
Results: 20 items (highly relevant)
```

**Performance Improvement: 8x faster** ⚡

## Architecture

### How It Works

```
Controller
    ↓
FederatedSearchService::search(
    nodeIds: [3, 5],  // ✅ Specific nodes
    options: {
        collections: [Product::class],  // ✅ Specific model
        filters: { workspace_id: 123 }
    }
)
    ↓
    ├─ Local Search (if master node in list)
    │   └─ VectorSearchService::search(Product, filters)
    │
    └─ Remote Nodes (only nodes 3 and 5)
        └─ HTTP POST /api/node/search
            └─ NodeApiController::search()
                └─ VectorSearchService::search(Product, filters)
```

### Data Flow

1. **Controller** specifies target nodes and collections
2. **FederatedSearchService** filters nodes to search
3. **Local Search** runs if master node is in target list
4. **Remote Search** only contacts specified nodes
5. **Each Node** only searches specified collections
6. **Results** merged and returned

## Configuration

### Node Model

Ensure your nodes have proper metadata:

```php
// database/migrations/create_nodes_table.php
Schema::create('nodes', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('api_url');
    $table->string('region')->nullable();  // For regional filtering
    $table->string('type')->nullable();    // For type-based filtering
    $table->integer('workspace_id')->nullable();  // For workspace nodes
    $table->enum('status', ['active', 'inactive', 'maintenance']);
    $table->timestamps();
});
```

### Workspace-Node Relationship

```php
// app/Models/Workspace.php
class Workspace extends Model
{
    public function node()
    {
        return $this->belongsTo(Node::class);
    }
}

// app/Models/Node.php
class Node extends Model
{
    public function workspaces()
    {
        return $this->hasMany(Workspace::class);
    }
}
```

## Best Practices

### 1. Always Specify Collections
```php
// ❌ Bad: Searches all collections
$results = $federatedSearch->search($query, [3], 10, []);

// ✅ Good: Specific collections
$results = $federatedSearch->search(
    $query, 
    [3], 
    10, 
    ['collections' => [Product::class]]
);
```

### 2. Use Node IDs for Known Data Location
```php
// ✅ Good: You know products are on node 5
$nodeIds = [5];

// ❌ Bad: Searching all nodes when you know the location
$nodeIds = null;
```

### 3. Combine with Custom Filters
```php
$results = $federatedSearch->search(
    query: $query,
    nodeIds: [$nodeId],
    limit: 20,
    options: [
        'collections' => [Product::class],
        'filters' => [
            'workspace_id' => $workspaceId,  // ✅ Workspace filter
            'is_active' => true,              // ✅ Status filter
        ],
        'skip_user_filter' => true,
    ],
    userId: $userId
);
```

### 4. Handle Empty Node Lists
```php
$nodeIds = $this->getWorkspaceNodes($workspaceId);

if (empty($nodeIds)) {
    // Fallback to local search
    return $this->searchLocal($query);
}

return $federatedSearch->search($query, $nodeIds, 10, $options);
```

### 5. Cache Node Selections
```php
use Illuminate\Support\Facades\Cache;

$nodeIds = Cache::remember(
    "workspace_{$workspaceId}_nodes",
    3600,
    fn() => Node::where('workspace_id', $workspaceId)
        ->where('status', 'active')
        ->pluck('id')
        ->toArray()
);
```

## API Examples

### REST API Endpoint

```php
// routes/api.php
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/search/products/node/{nodeId}', function (
        int $nodeId,
        Request $request,
        FederatedSearchService $federatedSearch
    ) {
        $results = $federatedSearch->search(
            query: $request->input('query'),
            nodeIds: [$nodeId],
            limit: $request->input('limit', 20),
            options: [
                'collections' => [Product::class],
                'filters' => [
                    'workspace_id' => auth()->user()->workspace_id,
                ],
                'skip_user_filter' => true,
            ],
            userId: auth()->id()
        );
        
        return response()->json($results);
    });
});
```

### GraphQL Resolver

```php
// app/GraphQL/Queries/SearchProducts.php
class SearchProducts
{
    public function __invoke($_, array $args, FederatedSearchService $federatedSearch)
    {
        $results = $federatedSearch->search(
            query: $args['query'],
            nodeIds: $args['nodeIds'] ?? null,
            limit: $args['limit'] ?? 20,
            options: [
                'collections' => [Product::class],
                'filters' => [
                    'workspace_id' => $args['workspaceId'],
                ],
                'skip_user_filter' => true,
            ],
            userId: auth()->id()
        );
        
        return $results['results'] ?? [];
    }
}
```

## Troubleshooting

### Issue: No Results from Specific Node

**Problem**: Targeting a specific node but getting no results.

**Solution**: Check if the model is indexed on that node.

```bash
# On the target node
php artisan vector:status "App\Models\Product"
```

### Issue: Node Not Found

**Problem**: Node ID doesn't exist or is inactive.

**Solution**: Validate node IDs before searching.

```php
$validNodeIds = Node::whereIn('id', $requestedNodeIds)
    ->where('status', 'active')
    ->pluck('id')
    ->toArray();

if (empty($validNodeIds)) {
    return response()->json(['error' => 'No active nodes found'], 404);
}
```

### Issue: Slow Performance

**Problem**: Still slow even with targeted search.

**Solution**: Check collection indexing and filters.

```php
// Ensure model has proper indexes
public function getVectorMetadata(): array
{
    return [
        'workspace_id' => $this->workspace_id,  // ✅ Indexed
        'is_active' => $this->is_active,        // ✅ Indexed
    ];
}
```

## Summary

✅ **Use `nodeIds` parameter** to target specific nodes  
✅ **Use `collections` option** to search specific models  
✅ **Combine with `filters`** for precise results  
✅ **Use `skip_user_filter`** for workspace-level searches  
✅ **Cache node selections** for better performance  
✅ **Validate node IDs** before searching  
✅ **Handle empty results** gracefully  

**Result**: 8x faster searches with more relevant results! 🚀
