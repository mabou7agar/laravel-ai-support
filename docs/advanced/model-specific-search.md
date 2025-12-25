# Model-Specific Search Configuration

This guide explains how to define **custom search filters and configuration** directly in your models, so each model can control its own search behavior across all nodes.

## The Problem

When searching for "Milk" in Products:
- **Products** should filter by `workspace_id` (all team members see same products)
- **Documents** should filter by `organization_id` (company-wide access)
- **Emails** should filter by `user_id` (strictly personal)
- **Articles** should have no user filtering (public content)

You want each model to define its own rules, not configure this in every controller.

## The Solution: Model-Specific Methods

Add two methods to your model:

### 1. `getVectorSearchFilters()` - Define Custom Filters

```php
/**
 * Define custom search filters for this model
 *
 * @param int|string|null $userId Current user ID
 * @param array $baseFilters Additional filters from search request
 * @return array Final filters to apply
 */
public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
{
    // Your custom logic here
}
```

### 2. `getVectorSearchConfig()` - Define Search Configuration

```php
/**
 * Define search configuration for this model
 *
 * @return array Configuration options
 */
public static function getVectorSearchConfig(): array
{
    return [
        'skip_user_filter' => true,  // Skip default user_id filtering
        'threshold' => 0.7,           // Minimum relevance score
    ];
}
```

## Complete Examples

### Example 1: Products (Workspace-Scoped)

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\Vectorizable;

class Product extends Model
{
    use Vectorizable;
    
    /**
     * Metadata stored in Qdrant (must include workspace_id)
     */
    public function getVectorMetadata(): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'category' => $this->category,
            'is_public' => $this->is_public,
            'status' => $this->status,
        ];
    }
    
    /**
     * ✅ Products are workspace-scoped, not user-scoped
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            // No user = only public products
            return array_merge($baseFilters, [
                'is_public' => true,
                'status' => 'active',
            ]);
        }
        
        // All workspace members see same products
        return array_merge($baseFilters, [
            'workspace_id' => $user->workspace_id,
            'status' => 'active',
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,  // Don't add user_id filter
            'threshold' => 0.7,
        ];
    }
}
```

### Example 2: Documents (Organization-Scoped)

```php
class Document extends Model
{
    use Vectorizable;
    
    public function getVectorMetadata(): array
    {
        return [
            'organization_id' => $this->organization_id,
            'user_id' => $this->user_id,
            'is_confidential' => $this->is_confidential,
            'status' => $this->status,
        ];
    }
    
    /**
     * ✅ Documents are organization-scoped with confidentiality rules
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = $userId ? \App\Models\User::find($userId) : null;
        
        if (!$user) {
            return array_merge($baseFilters, [
                'is_confidential' => false,
                'status' => 'published',
            ]);
        }
        
        // Organization-level access
        // Confidential docs require user to be the owner
        return array_merge($baseFilters, [
            'organization_id' => $user->organization_id,
            'status' => 'published',
            '$or' => [
                ['is_confidential' => false],
                ['user_id' => $userId],
            ],
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.75,  // Higher threshold for documents
        ];
    }
}
```

### Example 3: Emails (Strictly User-Scoped)

```php
class Email extends Model
{
    use Vectorizable;
    
    public function getVectorMetadata(): array
    {
        return [
            'user_id' => $this->user_id,
            'mailbox_id' => $this->mailbox_id,
            'folder_name' => $this->folder_name,
        ];
    }
    
    /**
     * ✅ Emails are strictly personal - only user's emails
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        if (!$userId) {
            // No access without user
            return array_merge($baseFilters, [
                'user_id' => '__no_access__',
            ]);
        }
        
        // Strict user filtering
        return array_merge($baseFilters, [
            'user_id' => $userId,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => false,  // Keep default user filtering
            'threshold' => 0.65,
        ];
    }
}
```

### Example 4: Articles (Public Content)

```php
class Article extends Model
{
    use Vectorizable;
    
    public function getVectorMetadata(): array
    {
        return [
            'author_id' => $this->author_id,
            'is_published' => $this->is_published,
            'category' => $this->category,
        ];
    }
    
    /**
     * ✅ Articles are public - no user restrictions
     */
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        // Public content - no user filtering
        return array_merge($baseFilters, [
            'is_published' => true,
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return [
            'skip_user_filter' => true,
            'threshold' => 0.6,  // Lower threshold for public content
        ];
    }
}
```

## How It Works

### Flow Diagram

```
User searches for "Milk" in Products
    ↓
Controller calls RAG or FederatedSearch
    ↓
VectorSearchService::search(Product::class, "Milk", ...)
    ↓
VectorAccessControl::buildSearchFilters($userId, $filters)
    ↓
    Checks: Does Product have getVectorSearchFilters()?
    ↓
    YES → Product::getVectorSearchFilters($userId, $baseFilters)
    ↓
    Product returns: ['workspace_id' => 5, 'status' => 'active']
    ↓
    Checks: Does Product have getVectorSearchConfig()?
    ↓
    YES → Product::getVectorSearchConfig()
    ↓
    Config says: skip_user_filter = true
    ↓
    Final filters: ['workspace_id' => 5, 'status' => 'active']
    (No user_id added)
    ↓
Search executed with model-specific filters
```

### On Remote Nodes

**The same logic applies automatically!**

```
Master Node → Remote Node
    ↓
POST /api/node/search
{
    "query": "Milk",
    "options": {
        "collections": ["App\\Models\\Product"],
        "user_id": 123
    }
}
    ↓
Remote Node: NodeApiController::search()
    ↓
VectorSearchService::search(Product::class, "Milk", userId: 123)
    ↓
Product::getVectorSearchFilters(123, [])
    ↓
Returns: ['workspace_id' => 5, 'status' => 'active']
    ↓
✅ Same filters applied on remote node!
```

## Usage in Controllers

### Simple Search

```php
use LaravelAIEngine\Services\RAG\IntelligentRAGService;

public function searchProducts(Request $request, IntelligentRAGService $rag)
{
    // Just specify the model - it handles its own filters!
    $response = $rag->processMessage(
        message: "Find Milk products",
        sessionId: session()->getId(),
        availableCollections: [Product::class],  // ✅ Product defines its own filters
        options: [],  // No need to specify filters!
        userId: auth()->id()
    );
    
    return response()->json([
        'response' => $response->getContent(),
    ]);
}
```

### Multi-Model Search

```php
public function searchAll(Request $request, IntelligentRAGService $rag)
{
    // Each model applies its own configuration!
    $response = $rag->processMessage(
        message: $request->input('query'),
        sessionId: session()->getId(),
        availableCollections: [
            Product::class,   // workspace_id filtering
            Document::class,  // organization_id filtering
            Email::class,     // user_id filtering
            Article::class,   // no filtering (public)
        ],
        options: [],
        userId: auth()->id()
    );
    
    return response()->json([
        'response' => $response->getContent(),
    ]);
}
```

### Override Model Filters

You can still override if needed:

```php
$response = $rag->processMessage(
    message: "Find Milk",
    sessionId: session()->getId(),
    availableCollections: [Product::class],
    options: [
        'filters' => [
            'category' => 'dairy',  // ✅ Additional filter
        ],
    ],
    userId: auth()->id()
);

// Final filters will be:
// ['workspace_id' => 5, 'status' => 'active', 'category' => 'dairy']
```

## Advanced Patterns

### Pattern 1: Dynamic Filters Based on User Role

```php
public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
{
    $user = $userId ? \App\Models\User::find($userId) : null;
    
    if (!$user) {
        return array_merge($baseFilters, ['is_public' => true]);
    }
    
    // Admins see everything
    if ($user->isAdmin()) {
        return $baseFilters;  // No restrictions
    }
    
    // Managers see their department
    if ($user->isManager()) {
        return array_merge($baseFilters, [
            'department_id' => $user->department_id,
        ]);
    }
    
    // Regular users see their workspace
    return array_merge($baseFilters, [
        'workspace_id' => $user->workspace_id,
    ]);
}
```

### Pattern 2: Time-Based Filtering

```php
public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
{
    $user = $userId ? \App\Models\User::find($userId) : null;
    
    $filters = array_merge($baseFilters, [
        'workspace_id' => $user->workspace_id ?? null,
    ]);
    
    // Only show recent products (last 30 days)
    $filters['created_at'] = [
        '$gte' => now()->subDays(30)->toIso8601String(),
    ];
    
    return $filters;
}
```

### Pattern 3: Complex Access Rules

```php
public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
{
    $user = $userId ? \App\Models\User::with('teams')->find($userId) : null;
    
    if (!$user) {
        return array_merge($baseFilters, ['is_public' => true]);
    }
    
    // User can see:
    // 1. Their own products
    // 2. Their workspace products
    // 3. Products shared with their teams
    $teamIds = $user->teams->pluck('id')->toArray();
    
    return array_merge($baseFilters, [
        '$or' => [
            ['user_id' => $userId],
            ['workspace_id' => $user->workspace_id],
            ['team_id' => ['$in' => $teamIds]],
        ],
    ]);
}
```

## Configuration Options

### Available Config Keys

```php
public static function getVectorSearchConfig(): array
{
    return [
        // Skip default user_id filtering
        'skip_user_filter' => true,
        
        // Minimum relevance score (0.0 to 1.0)
        'threshold' => 0.7,
        
        // Boost specific fields (future feature)
        'boost_fields' => [
            'name' => 2.0,
            'category' => 1.5,
        ],
        
        // Maximum results (future feature)
        'max_results' => 50,
    ];
}
```

## Testing

### Test Model Filters

```php
// Test Product filters
$filters = Product::getVectorSearchFilters(auth()->id(), []);
dd($filters);
// Output: ['workspace_id' => 5, 'status' => 'active']

// Test with additional filters
$filters = Product::getVectorSearchFilters(auth()->id(), ['category' => 'dairy']);
dd($filters);
// Output: ['workspace_id' => 5, 'status' => 'active', 'category' => 'dairy']

// Test config
$config = Product::getVectorSearchConfig();
dd($config);
// Output: ['skip_user_filter' => true, 'threshold' => 0.7]
```

### Test Search

```php
use LaravelAIEngine\Services\Vector\VectorSearchService;

$searchService = app(VectorSearchService::class);

$results = $searchService->search(
    modelClass: Product::class,
    query: "Milk",
    limit: 10,
    threshold: 0.7,
    filters: [],  // Model will add its own filters
    userId: auth()->id()
);

// Check what filters were applied
Log::info('Search results', [
    'count' => $results->count(),
    'filters_applied' => 'Check ai-engine.log',
]);
```

## Migration Guide

### Before (Manual Filters in Controller)

```php
public function search(Request $request)
{
    $response = $rag->processMessage(
        message: $request->input('query'),
        sessionId: session()->getId(),
        availableCollections: [Product::class],
        options: [
            'filters' => [
                'workspace_id' => auth()->user()->workspace_id,
                'status' => 'active',
            ],
            'skip_user_filter' => true,
        ],
        userId: auth()->id()
    );
}
```

### After (Model Handles Filters)

```php
// 1. Add methods to Product model
class Product extends Model
{
    public static function getVectorSearchFilters($userId = null, array $baseFilters = []): array
    {
        $user = User::find($userId);
        return array_merge($baseFilters, [
            'workspace_id' => $user->workspace_id,
            'status' => 'active',
        ]);
    }
    
    public static function getVectorSearchConfig(): array
    {
        return ['skip_user_filter' => true];
    }
}

// 2. Simplify controller
public function search(Request $request)
{
    $response = $rag->processMessage(
        message: $request->input('query'),
        sessionId: session()->getId(),
        availableCollections: [Product::class],  // ✅ That's it!
        options: [],
        userId: auth()->id()
    );
}
```

## Benefits

✅ **Centralized Logic** - Filter rules in one place (the model)  
✅ **Consistent Behavior** - Same filters on all nodes  
✅ **Cleaner Controllers** - No filter logic in controllers  
✅ **Easy Testing** - Test model methods directly  
✅ **Flexible** - Each model defines its own rules  
✅ **Maintainable** - Change filters in one place  
✅ **Type-Safe** - Static methods with clear signatures

## Summary

1. Add `getVectorSearchFilters()` to your model
2. Add `getVectorSearchConfig()` to your model
3. Ensure `getVectorMetadata()` includes all filterable fields
4. Remove filter logic from controllers
5. Let models handle their own access control

**Result: Clean, maintainable, model-specific search configuration that works across all nodes!** 🎯
