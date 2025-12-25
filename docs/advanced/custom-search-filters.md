# Custom Search Filters for Federated Search

This guide shows how to override default `user_id` filtering with custom filters like `workspace_id` for multi-tenant scenarios.

## Problem

By default, the system filters vector searches by `user_id`. But sometimes you need different filtering:
- **Products**: Filter by `workspace_id` (shared across team)
- **Documents**: Filter by `organization_id` (company-wide)
- **Public Content**: No filtering at all

## Solution 1: Pass Custom Filters (Recommended)

### Step 1: Pass Filters in Options

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

$rag = app(IntelligentRAGService::class);

// Search products by workspace_id instead of user_id
$response = $rag->processMessage(
    message: "Find budget planning products",
    sessionId: $sessionId,
    availableCollections: [Product::class],
    conversationHistory: [],
    options: [
        'filters' => [
            'workspace_id' => auth()->user()->workspace_id,  // Custom filter
            // Don't include user_id here - it will be added automatically
        ],
        'skip_user_filter' => false,  // Set to true to skip user_id filtering
    ],
    userId: auth()->id()
);
```

### Step 2: Update VectorAccessControl to Merge Filters

The `buildSearchFilters()` method already merges `$baseFilters` with user filters:

```php
// In VectorAccessControl.php
public function buildSearchFilters($userId, array $baseFilters = []): array
{
    // Your custom filters from options['filters'] are in $baseFilters
    // They get merged with user_id filter
    
    return array_merge($baseFilters, ['user_id' => $userId]);
}
```

### Step 3: Pass Filters Through the Chain

The filters flow through the entire chain automatically:

```php
// Controller
$options = [
    'filters' => ['workspace_id' => $workspaceId]
];

// ↓ IntelligentRAGService
$context = $this->retrieveRelevantContext(
    $searchQueries,
    $collections,
    $options,  // Contains filters
    $userId
);

// ↓ FederatedSearchService
$federatedResults = $this->federatedSearch->search(
    query: $query,
    nodeIds: null,
    limit: $maxResults,
    options: array_merge($options, [  // Merges your filters
        'collections' => $collections,
        'threshold' => $threshold,
    ]),
    userId: $userId
);

// ↓ VectorSearchService
$results = $this->vectorSearch->search(
    $collection,
    $query,
    $limit,
    $threshold,
    $options['filters'] ?? [],  // Your custom filters
    $userId
);
```

## Solution 2: Skip User Filtering Entirely

For public content or workspace-wide searches:

```php
$response = $rag->processMessage(
    message: "Find public products",
    sessionId: $sessionId,
    availableCollections: [Product::class],
    options: [
        'filters' => [
            'workspace_id' => $workspaceId,
            'is_public' => true,
        ],
        'skip_user_filter' => true,  // Skip user_id filtering
    ],
    userId: null  // Or pass userId for logging only
);
```

### Update VectorAccessControl for Skip Option

```php
public function buildSearchFilters($userId, array $baseFilters = []): array
{
    // Check if user filtering should be skipped
    if (isset($baseFilters['skip_user_filter']) && $baseFilters['skip_user_filter']) {
        unset($baseFilters['skip_user_filter']);  // Remove the flag
        Log::debug('Skipping user_id filter as requested');
        return $baseFilters;  // Return only custom filters
    }
    
    // Normal flow with user_id filtering
    if (!$userId) {
        $userId = config('ai-engine.demo_user_id', '1');
    }
    
    $user = $this->getUserById($userId);
    
    if (!$user) {
        return array_merge($baseFilters, ['user_id' => config('ai-engine.demo_user_id', '1')]);
    }
    
    // Admin users get no filtering
    if ($this->canAccessAllData($user)) {
        return $baseFilters;
    }
    
    // Regular users get user_id filter
    return array_merge($baseFilters, ['user_id' => $userId]);
}
```

## Solution 3: Model-Specific Filter Logic

For more complex scenarios, implement custom logic in your model:

```php
// app/Models/Product.php
use LaravelAIEngine\Traits\Vectorizable;

class Product extends Model
{
    use Vectorizable;
    
    /**
     * Override default vector search filters
     */
    public function getVectorSearchFilters($userId = null): array
    {
        // Products are filtered by workspace, not user
        $user = User::find($userId);
        
        if (!$user) {
            return ['is_public' => true];  // Public products only
        }
        
        return [
            'workspace_id' => $user->workspace_id,  // Workspace-level access
            // No user_id filter
        ];
    }
    
    /**
     * Metadata for vector indexing
     */
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'is_public' => $this->is_public,
            'category' => $this->category,
        ];
    }
}
```

Then update VectorAccessControl to check for custom method:

```php
public function buildSearchFilters($userId, array $baseFilters = []): array
{
    // Check if model has custom filter logic
    if (isset($baseFilters['model_class'])) {
        $modelClass = $baseFilters['model_class'];
        
        if (method_exists($modelClass, 'getVectorSearchFilters')) {
            $customFilters = $modelClass::getVectorSearchFilters($userId);
            return array_merge($baseFilters, $customFilters);
        }
    }
    
    // Default user_id filtering
    // ... existing logic
}
```

## Complete Example: Multi-Tenant Product Search

```php
// Controller
public function searchProducts(Request $request)
{
    $user = auth()->user();
    $workspaceId = $user->workspace_id;
    
    $rag = app(IntelligentRAGService::class);
    
    $response = $rag->processMessage(
        message: $request->input('query'),
        sessionId: "workspace_{$workspaceId}_" . $request->session()->getId(),
        availableCollections: [Product::class, Document::class],
        options: [
            'filters' => [
                'workspace_id' => $workspaceId,  // Workspace-level filtering
                'status' => 'active',
            ],
            'threshold' => 0.7,
            'max_context' => 10,
        ],
        userId: $user->id  // For logging and admin checks
    );
    
    return response()->json([
        'success' => true,
        'response' => $response->getContent(),
        'sources' => $response->getMetadata()['sources'] ?? [],
    ]);
}
```

## Remote Node Behavior

When searching remote nodes, all filters are passed automatically:

```php
// Master Node
FederatedSearchService::search($userId)
    ↓
    $options['user_id'] = $userId;
    $options['filters'] = ['workspace_id' => 123];
    ↓
    HTTP POST to remote node with options

// Remote Node (NodeApiController)
$userId = $options['user_id'] ?? null;
$filters = $options['filters'] ?? [];  // Contains workspace_id

$searchResults = $searchService->search(
    $collection,
    $query,
    $limit,
    $threshold,
    $filters,  // ✅ workspace_id passed to remote node
    $userId
);
```

## Best Practices

1. **Use `filters` option** for simple overrides (workspace_id, organization_id)
2. **Use `skip_user_filter`** for public/shared content
3. **Use model methods** for complex, model-specific logic
4. **Always index metadata** you want to filter by:
   ```php
   public function getVectorMetadata(): array
   {
       return [
           'workspace_id' => $this->workspace_id,
           'user_id' => $this->user_id,
           'is_public' => $this->is_public,
       ];
   }
   ```

## Security Considerations

- **Admin users** bypass all filtering (check `canAccessAllData()`)
- **Demo users** get fallback filtering
- **Custom filters** should validate user has access to workspace/organization
- **Remote nodes** enforce same filtering rules

## Testing

```php
// Test workspace-level search
$response = $rag->processMessage(
    message: "budget products",
    sessionId: "test",
    availableCollections: [Product::class],
    options: [
        'filters' => ['workspace_id' => 1],
        'debug' => true,  // Enable debug logging
    ],
    userId: 1
);

// Check logs for filter application
// ai-engine.log will show: "Applying filters: ['workspace_id' => 1, 'user_id' => 1]"
```
