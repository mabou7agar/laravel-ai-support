# Multi-Tenant Support Implementation Plan

## 🎯 Overview

Add multi-tenant support to laravel-ai-engine for SaaS applications.

---

## 🏢 What is Multi-Tenant?

**Multi-tenant** means multiple organizations/companies share the same application but their data is isolated.

**Example:**
- Company A searches only their posts
- Company B searches only their posts
- Data never mixes

---

## 📋 Requirements

### 1. Tenant Isolation in Vector Search

```php
// Company A user searches
$posts = Post::vectorSearch('Laravel tips', filters: [
    'tenant_id' => auth()->user()->tenant_id
]);

// Only returns Company A's posts
```

### 2. Automatic Tenant Filtering

```php
// User shouldn't need to manually add tenant_id
// It should be automatic!

$posts = Post::vectorSearch('Laravel tips');
// Automatically filtered by current tenant
```

### 3. Tenant-Specific Collections (Optional)

```php
// Option 1: Shared collection with tenant filter
Collection: "posts" → Filter by tenant_id

// Option 2: Separate collections per tenant
Collection: "tenant_123_posts"
Collection: "tenant_456_posts"
```

---

## 🛠️ Implementation

### Approach 1: Metadata Filtering (Recommended)

**How it works:**
- All tenants share same vector collection
- Each vector has `tenant_id` in metadata
- Search filters by `tenant_id` automatically

**Pros:**
- ✅ Simple to implement
- ✅ Cost-effective (one collection)
- ✅ Easy to manage

**Cons:**
- ❌ All tenant data in same collection
- ❌ Requires trust in filtering

---

### Approach 2: Separate Collections (More Secure)

**How it works:**
- Each tenant gets their own collection
- Collection name: `tenant_{id}_posts`
- Complete data isolation

**Pros:**
- ✅ Complete data isolation
- ✅ More secure
- ✅ Can delete tenant data easily

**Cons:**
- ❌ More collections to manage
- ❌ Higher cost
- ❌ More complex

---

## 💻 Code Implementation

### Step 1: Add Tenant Scope to Vectorizable Trait

```php
// In Vectorizable trait

/**
 * Get tenant ID for multi-tenant filtering
 */
protected function getTenantId(): ?string
{
    // Check if model has tenant_id
    if (property_exists($this, 'tenant_id') && $this->tenant_id) {
        return (string) $this->tenant_id;
    }
    
    // Check if model has organization_id
    if (property_exists($this, 'organization_id') && $this->organization_id) {
        return (string) $this->organization_id;
    }
    
    // Check if model has company_id
    if (property_exists($this, 'company_id') && $this->company_id) {
        return (string) $this->company_id;
    }
    
    return null;
}

/**
 * Get vector metadata with tenant info
 */
public function getVectorMetadata(): array
{
    $metadata = [
        'model_class' => static::class,
        'model_id' => $this->id,
    ];
    
    // Add tenant ID if available
    $tenantId = $this->getTenantId();
    if ($tenantId) {
        $metadata['tenant_id'] = $tenantId;
    }
    
    // Add custom metadata
    if (method_exists($this, 'toVectorMetadata')) {
        $metadata = array_merge($metadata, $this->toVectorMetadata());
    }
    
    return $metadata;
}
```

---

### Step 2: Auto-Apply Tenant Filter in Vector Search

```php
// In Vectorizable trait

public static function vectorSearch(
    string $query,
    int $limit = 10,
    float $threshold = 0.0,
    array $filters = [],
    ?object $user = null,
    array $options = []
): Collection {
    // Auto-add tenant filter if not disabled
    if (!($options['skip_tenant_filter'] ?? false)) {
        $filters = static::applyTenantFilter($filters, $user);
    }
    
    // Continue with normal vector search...
    $vectorSearch = app(VectorSearchService::class);
    return $vectorSearch->search(static::class, $query, $limit, $threshold, $filters);
}

/**
 * Apply tenant filter automatically
 */
protected static function applyTenantFilter(array $filters, ?object $user = null): array
{
    // Get current tenant ID
    $tenantId = static::getCurrentTenantId($user);
    
    if ($tenantId && !isset($filters['tenant_id'])) {
        $filters['tenant_id'] = $tenantId;
    }
    
    return $filters;
}

/**
 * Get current tenant ID from user or context
 */
protected static function getCurrentTenantId(?object $user = null): ?string
{
    // Try from user
    if ($user) {
        if (property_exists($user, 'tenant_id')) {
            return (string) $user->tenant_id;
        }
        if (property_exists($user, 'organization_id')) {
            return (string) $user->organization_id;
        }
    }
    
    // Try from auth
    if (auth()->check()) {
        $authUser = auth()->user();
        if (property_exists($authUser, 'tenant_id')) {
            return (string) $authUser->tenant_id;
        }
        if (property_exists($authUser, 'organization_id')) {
            return (string) $authUser->organization_id;
        }
    }
    
    // Try from config/session
    if (config('ai-engine.multi_tenant.enabled')) {
        $tenantIdKey = config('ai-engine.multi_tenant.tenant_id_key', 'tenant_id');
        if (session()->has($tenantIdKey)) {
            return (string) session($tenantIdKey);
        }
    }
    
    return null;
}
```

---

### Step 3: Add Configuration

```php
// In config/ai-engine.php

'multi_tenant' => [
    'enabled' => env('AI_ENGINE_MULTI_TENANT', false),
    
    // How to identify tenant
    'tenant_id_key' => env('AI_ENGINE_TENANT_ID_KEY', 'tenant_id'),
    
    // Approach: 'metadata' or 'separate_collections'
    'approach' => env('AI_ENGINE_TENANT_APPROACH', 'metadata'),
    
    // Collection naming for separate collections
    'collection_prefix' => env('AI_ENGINE_TENANT_COLLECTION_PREFIX', 'tenant_'),
    
    // Auto-apply tenant filter
    'auto_filter' => env('AI_ENGINE_TENANT_AUTO_FILTER', true),
],
```

---

### Step 4: Update VectorSearchService

```php
// In VectorSearchService

public function search(
    string $modelClass,
    string $query,
    int $limit = 10,
    float $threshold = 0.0,
    array $filters = []
): Collection {
    // Get collection name (tenant-aware if needed)
    $collectionName = $this->getCollectionName($modelClass);
    
    // Generate embedding
    $embedding = $this->generateEmbedding($query);
    
    // Search with filters (including tenant_id)
    $results = $this->qdrant->search(
        collection: $collectionName,
        vector: $embedding,
        limit: $limit,
        filter: $this->buildQdrantFilter($filters),
        scoreThreshold: $threshold
    );
    
    // Hydrate models
    return $this->hydrateModels($modelClass, $results);
}

protected function getCollectionName(string $modelClass): string
{
    $baseName = $this->getBaseCollectionName($modelClass);
    
    // If using separate collections per tenant
    if (config('ai-engine.multi_tenant.approach') === 'separate_collections') {
        $tenantId = $this->getCurrentTenantId();
        if ($tenantId) {
            $prefix = config('ai-engine.multi_tenant.collection_prefix', 'tenant_');
            return $prefix . $tenantId . '_' . $baseName;
        }
    }
    
    return $baseName;
}

protected function buildQdrantFilter(array $filters): array
{
    $qdrantFilter = [];
    
    foreach ($filters as $key => $value) {
        $qdrantFilter[] = [
            'key' => $key,
            'match' => ['value' => $value]
        ];
    }
    
    return ['must' => $qdrantFilter];
}
```

---

## 📝 Usage Examples

### Example 1: Basic Multi-Tenant Search

```php
// In your model
class Post extends Model
{
    use Vectorizable;
    
    // Model has tenant_id column
    protected $fillable = ['title', 'content', 'tenant_id'];
    
    public array $vectorizable = ['title', 'content'];
}

// Search (automatically filtered by tenant)
$posts = Post::vectorSearch('Laravel tips');
// Only returns posts for current user's tenant
```

---

### Example 2: Manual Tenant Override

```php
// Search for specific tenant (admin use case)
$posts = Post::vectorSearch('Laravel tips', filters: [
    'tenant_id' => '123'
]);

// Skip tenant filter (super admin)
$posts = Post::vectorSearch('Laravel tips', options: [
    'skip_tenant_filter' => true
]);
```

---

### Example 3: Custom Tenant Field

```php
class Post extends Model
{
    use Vectorizable;
    
    // Using organization_id instead of tenant_id
    protected $fillable = ['title', 'content', 'organization_id'];
    
    public array $vectorizable = ['title', 'content'];
    
    // Override tenant ID getter
    protected function getTenantId(): ?string
    {
        return $this->organization_id;
    }
}
```

---

## 🧪 Testing Multi-Tenant

```php
// Test tenant isolation
public function test_vector_search_filters_by_tenant()
{
    // Create posts for tenant 1
    $tenant1Post = Post::create([
        'title' => 'Tenant 1 Post',
        'content' => 'Laravel tips',
        'tenant_id' => '1',
    ]);
    
    // Create posts for tenant 2
    $tenant2Post = Post::create([
        'title' => 'Tenant 2 Post',
        'content' => 'Laravel tips',
        'tenant_id' => '2',
    ]);
    
    // Index both
    $tenant1Post->indexVector();
    $tenant2Post->indexVector();
    
    // Search as tenant 1 user
    $this->actingAs($tenant1User);
    $results = Post::vectorSearch('Laravel tips');
    
    // Should only return tenant 1 posts
    $this->assertCount(1, $results);
    $this->assertEquals('1', $results->first()->tenant_id);
}
```

---

## 📊 Implementation Checklist

### Phase 1: Core Multi-Tenant (4 hours)

- [ ] Add `getTenantId()` to Vectorizable trait
- [ ] Add tenant_id to vector metadata
- [ ] Add `applyTenantFilter()` method
- [ ] Add `getCurrentTenantId()` method
- [ ] Update `vectorSearch()` to auto-filter
- [ ] Add multi_tenant config
- [ ] Write tests

### Phase 2: Advanced Features (2 hours)

- [ ] Support separate collections per tenant
- [ ] Add tenant collection management
- [ ] Add tenant data deletion
- [ ] Add admin bypass option
- [ ] Document usage

### Phase 3: Documentation (1 hour)

- [ ] Multi-tenant setup guide
- [ ] Security best practices
- [ ] Migration guide
- [ ] Troubleshooting

**Total:** 7 hours

---

## 🎯 Priority

**Priority:** P1 (High Priority)

**Why:** Essential for SaaS applications

**When:** Implement in Phase 2 (after relationship indexing)

---

## ✅ Recommendation

**Implement metadata-based filtering first** (simpler, faster)

Then optionally add separate collections for users who need it.

**Effort:** 7 hours  
**Impact:** HIGH - Critical for SaaS apps
