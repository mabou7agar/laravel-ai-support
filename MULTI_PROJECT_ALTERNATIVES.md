# Multi-Project Communication Alternatives

**Scenario:** You have multiple separate Laravel projects that need to share data/search  
**Current Solution:** Complex multi-node system with federated search  
**Problem:** Over-engineered, buggy, hard to maintain

---

## 🎯 Better Alternatives

### Option 1: Shared Database with Read Replicas ⭐ **RECOMMENDED**

**Best for:** Multiple projects needing to search same data

#### Architecture:
```
Project A (Master) ──┐
                     ├──> Shared Database (Master)
Project B (Slave)  ──┤         │
                     │         ├──> Read Replica 1
Project C (Slave)  ──┘         └──> Read Replica 2
```

#### Implementation:
```php
// config/database.php in each project

'connections' => [
    // Write connection (only Project A uses this)
    'mysql_master' => [
        'driver' => 'mysql',
        'host' => env('DB_MASTER_HOST', '127.0.0.1'),
        'database' => 'shared_ai_data',
        'username' => env('DB_MASTER_USERNAME'),
        'password' => env('DB_MASTER_PASSWORD'),
    ],
    
    // Read connection (all projects use this)
    'mysql_read' => [
        'driver' => 'mysql',
        'host' => env('DB_READ_HOST', '127.0.0.1'),
        'database' => 'shared_ai_data',
        'username' => env('DB_READ_USERNAME'),
        'password' => env('DB_READ_PASSWORD'),
    ],
],

// Usage in your code:
// Write (only in master project)
DB::connection('mysql_master')->table('items')->insert([...]);

// Read (all projects)
$results = DB::connection('mysql_read')->table('items')->where(...)->get();
```

**Pros:**
- ✅ Simple and reliable
- ✅ No custom code needed
- ✅ Laravel built-in support
- ✅ Proven at scale
- ✅ Easy to debug

**Cons:**
- ❌ Single point of failure (mitigated by replicas)
- ❌ Requires database setup

---

### Option 2: Centralized Search Service (Elasticsearch/Meilisearch) ⭐ **BEST FOR SEARCH**

**Best for:** When you need powerful search across projects

#### Architecture:
```
Project A ──┐
            ├──> Elasticsearch/Meilisearch Cluster
Project B ──┤
            │
Project C ──┘
```

#### Implementation with Meilisearch:
```bash
# Install Meilisearch (one instance for all projects)
docker run -d -p 7700:7700 getmeili/meilisearch:latest

# In each project
composer require meilisearch/meilisearch-php
```

```php
// In each Laravel project

// Index data (when creating/updating)
use MeiliSearch\Client;

$client = new Client('http://meilisearch:7700', 'your-master-key');

// Project A indexes invoices
$client->index('invoices')->addDocuments([
    ['id' => 1, 'project' => 'A', 'content' => '...'],
]);

// Project B indexes products
$client->index('products')->addDocuments([
    ['id' => 1, 'project' => 'B', 'content' => '...'],
]);

// Search from any project
$results = $client->index('invoices')->search('query', [
    'filter' => 'project = A',
    'limit' => 10,
]);
```

**Pros:**
- ✅ Built for search
- ✅ Fast and scalable
- ✅ Simple API
- ✅ No custom code
- ✅ Better search quality than SQL

**Cons:**
- ❌ Additional service to maintain
- ❌ Data needs to be synced

---

### Option 3: Message Queue + Shared Cache (Redis) ⭐ **GOOD FOR ASYNC**

**Best for:** Async communication between projects

#### Architecture:
```
Project A ──┐
            ├──> Redis (Queue + Cache)
Project B ──┤
            │
Project C ──┘
```

#### Implementation:
```php
// config/queue.php (same in all projects)
'connections' => [
    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
    ],
],

// Project A: Dispatch job
dispatch(new IndexDataJob($data))->onQueue('search-indexing');

// Project B: Process job
// Just run: php artisan queue:work redis --queue=search-indexing

// Shared cache for search results
Cache::store('redis')->put('search:' . md5($query), $results, 3600);

// Any project can read
$results = Cache::store('redis')->get('search:' . md5($query));
```

**Pros:**
- ✅ Laravel built-in
- ✅ Async processing
- ✅ Shared cache
- ✅ Simple to implement

**Cons:**
- ❌ Not real-time
- ❌ Cache invalidation complexity

---

### Option 4: Simple REST API (Simplified Node System) ⭐ **SIMPLEST**

**Best for:** When you need real-time cross-project queries

#### Architecture:
```
Project A (Master) ──> Exposes API
                       │
Project B ──> HTTP ────┤
                       │
Project C ──> HTTP ────┘
```

#### Implementation:

**Project A (Master - exposes API):**
```php
// routes/api.php
Route::middleware('api-key')->group(function () {
    Route::post('/search', [SearchController::class, 'search']);
    Route::get('/data/{id}', [DataController::class, 'show']);
});

// app/Http/Middleware/ApiKeyAuth.php
public function handle($request, Closure $next)
{
    if ($request->header('X-API-Key') !== config('app.api_key')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    return $next($request);
}

// app/Http/Controllers/SearchController.php
public function search(Request $request)
{
    $query = $request->input('query');
    $results = YourModel::search($query)->get();
    
    return response()->json([
        'results' => $results,
        'count' => $results->count(),
    ]);
}
```

**Project B & C (Clients):**
```php
// app/Services/MasterApiClient.php
class MasterApiClient
{
    protected $baseUrl;
    protected $apiKey;
    
    public function __construct()
    {
        $this->baseUrl = config('services.master.url');
        $this->apiKey = config('services.master.api_key');
    }
    
    public function search(string $query, array $options = []): array
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-API-Key' => $this->apiKey])
            ->post($this->baseUrl . '/api/search', [
                'query' => $query,
                'options' => $options,
            ]);
            
        if ($response->successful()) {
            return $response->json();
        }
        
        // Fallback to local search
        return $this->localSearch($query);
    }
    
    protected function localSearch(string $query): array
    {
        // Search local data only
        return ['results' => [], 'count' => 0];
    }
}

// Usage
$client = new MasterApiClient();
$results = $client->search('invoice');
```

**Pros:**
- ✅ Simple HTTP calls
- ✅ No complex dependencies
- ✅ Easy to debug
- ✅ Can add caching easily

**Cons:**
- ❌ Network latency
- ❌ Need to handle failures

---

### Option 5: Hybrid Approach (Recommended for Your Case) ⭐⭐⭐

**Combine the best of multiple approaches:**

```
┌─────────────────────────────────────────────────┐
│  Shared Infrastructure                          │
│  ┌──────────────┐  ┌──────────────┐            │
│  │ Meilisearch  │  │    Redis     │            │
│  │   (Search)   │  │ (Cache+Queue)│            │
│  └──────────────┘  └──────────────┘            │
└─────────────────────────────────────────────────┘
         ▲                    ▲
         │                    │
    ┌────┴────┐          ┌────┴────┐
    │         │          │         │
┌───▼───┐ ┌──▼────┐ ┌───▼───┐ ┌───▼───┐
│Project│ │Project│ │Project│ │Project│
│   A   │ │   B   │ │   C   │ │   D   │
└───────┘ └───────┘ └───────┘ └───────┘
```

#### Implementation:

**1. Each project indexes to Meilisearch:**
```php
// app/Observers/ModelObserver.php (in each project)
class InvoiceObserver
{
    public function created(Invoice $invoice)
    {
        // Index to Meilisearch
        app(MeilisearchService::class)->index('invoices', [
            'id' => $invoice->id,
            'project' => config('app.name'), // 'Project A'
            'content' => $invoice->getSearchableContent(),
            'created_at' => $invoice->created_at->timestamp,
        ]);
        
        // Invalidate cache
        Cache::tags(['search'])->flush();
    }
}
```

**2. Search service (same in all projects):**
```php
// app/Services/UnifiedSearchService.php
class UnifiedSearchService
{
    protected $meilisearch;
    
    public function search(string $query, array $options = []): array
    {
        // Try cache first
        $cacheKey = 'search:' . md5($query . json_encode($options));
        
        return Cache::remember($cacheKey, 300, function () use ($query, $options) {
            // Search Meilisearch
            $results = $this->meilisearch
                ->index('invoices')
                ->search($query, [
                    'limit' => $options['limit'] ?? 10,
                    'filter' => $this->buildFilters($options),
                ]);
            
            return [
                'results' => $results['hits'],
                'count' => $results['estimatedTotalHits'],
                'processing_time_ms' => $results['processingTimeMs'],
            ];
        });
    }
    
    protected function buildFilters(array $options): ?string
    {
        $filters = [];
        
        // Filter by projects if specified
        if (!empty($options['projects'])) {
            $projects = implode(', ', array_map(fn($p) => "'$p'", $options['projects']));
            $filters[] = "project IN [$projects]";
        }
        
        // Filter by date range
        if (!empty($options['from_date'])) {
            $filters[] = "created_at >= {$options['from_date']}";
        }
        
        return !empty($filters) ? implode(' AND ', $filters) : null;
    }
}
```

**3. Usage (same in all projects):**
```php
// In any controller
$searchService = app(UnifiedSearchService::class);

$results = $searchService->search('invoice', [
    'projects' => ['Project A', 'Project B'], // Search specific projects
    'limit' => 20,
    'from_date' => now()->subDays(30)->timestamp,
]);
```

**Pros:**
- ✅ Fast search (Meilisearch)
- ✅ Cached results (Redis)
- ✅ Simple implementation
- ✅ Each project is independent
- ✅ Easy to scale

**Cons:**
- ❌ Two services to maintain (Meilisearch + Redis)
- ❌ Data sync needed

---

## 📊 Comparison Table

| Solution | Complexity | Performance | Scalability | Maintenance | Cost |
|----------|-----------|-------------|-------------|-------------|------|
| **Shared Database** | Low | Good | Medium | Low | Low |
| **Meilisearch** | Low | Excellent | High | Medium | Medium |
| **Redis Queue** | Medium | Good | High | Medium | Low |
| **Simple API** | Low | Medium | Medium | Low | Low |
| **Hybrid** | Medium | Excellent | High | Medium | Medium |
| **Current Multi-Node** | Very High | Poor | Low | Very High | High |

---

## 🎯 Recommended Solution for Your Case

Based on your scenario (multiple separate Laravel projects):

### **Use Hybrid Approach:**

1. **Meilisearch for search** (fast, reliable, simple)
2. **Redis for caching** (reduce load, improve speed)
3. **Simple API for real-time data** (when needed)

### Implementation Steps:

#### Step 1: Setup Meilisearch (5 minutes)
```bash
docker run -d \
  --name meilisearch \
  -p 7700:7700 \
  -v $(pwd)/meili_data:/meili_data \
  getmeili/meilisearch:latest
```

#### Step 2: Setup Redis (if not already)
```bash
docker run -d \
  --name redis \
  -p 6379:6379 \
  redis:alpine
```

#### Step 3: Install in each project
```bash
composer require meilisearch/meilisearch-php
composer require predis/predis
```

#### Step 4: Create UnifiedSearchService (copy to each project)
```php
// app/Services/UnifiedSearchService.php
// (code from hybrid approach above)
```

#### Step 5: Index your data
```php
// In each project's models
use LaravelAIEngine\Traits\Vectorizable;

class Invoice extends Model
{
    use Vectorizable;
    
    protected static function booted()
    {
        static::created(function ($invoice) {
            app(UnifiedSearchService::class)->index($invoice);
        });
        
        static::updated(function ($invoice) {
            app(UnifiedSearchService::class)->index($invoice);
        });
        
        static::deleted(function ($invoice) {
            app(UnifiedSearchService::class)->remove($invoice);
        });
    }
}
```

#### Step 6: Search from anywhere
```php
// In any project
$results = app(UnifiedSearchService::class)->search('invoice 12345', [
    'projects' => ['Project A', 'Project B'],
    'limit' => 10,
]);
```

---

## 🚀 Migration from Current Multi-Node

### Step 1: Setup new infrastructure
```bash
# Start Meilisearch
docker-compose up -d meilisearch

# Start Redis (if needed)
docker-compose up -d redis
```

### Step 2: Index existing data
```bash
# In each project
php artisan search:reindex
```

### Step 3: Update search calls
```php
// Old (complex multi-node)
$results = app(FederatedSearchService::class)->search($query, $nodeIds, $limit);

// New (simple unified)
$results = app(UnifiedSearchService::class)->search($query, [
    'projects' => ['A', 'B'],
    'limit' => $limit,
]);
```

### Step 4: Remove old multi-node code
```bash
# Remove all node services
rm -rf src/Services/Node/
rm -rf src/Console/Commands/Node/
rm -rf src/Http/Controllers/Node/
```

---

## 💡 Why This is Better

### Current Multi-Node System:
- ❌ 3,500+ lines of complex code
- ❌ Race conditions and bugs
- ❌ Hard to debug
- ❌ Requires custom infrastructure
- ❌ No proven reliability

### Recommended Hybrid Approach:
- ✅ ~200 lines of simple code
- ✅ Uses proven tools (Meilisearch, Redis)
- ✅ Easy to debug
- ✅ Standard infrastructure
- ✅ Battle-tested at scale

---

## 📞 Need Help?

If you need help implementing this, I can:
1. Create the UnifiedSearchService for you
2. Write migration scripts
3. Set up docker-compose for infrastructure
4. Create documentation

Just let me know what you need!
