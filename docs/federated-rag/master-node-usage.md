# 🌐 Master Node Client Usage Guide

## Overview

The `MasterNodeClient` service allows child nodes to communicate with the master node easily and securely.

---

## 🚀 Quick Start

### 1. Register Your Node

On the **master node**, register your child node:

```bash
php artisan ai-engine:node-register "My App" "https://my-app.com" \
    --capabilities=search,actions,rag
```

Copy the credentials to your child node's `.env`:

```bash
AI_ENGINE_NODE_ID=7
AI_ENGINE_NODE_SLUG=my-app
AI_ENGINE_NODE_API_KEY=fH1hZj0BfjI1eylr5Ic4g1PleSsZGDiivf2SfMBSlRbeD9hlhKxFqC7bUokKXrRL
AI_ENGINE_ACCESS_TOKEN=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
AI_ENGINE_REFRESH_TOKEN=8b2b545a311bcc4a8bdd0c10ec013475a52d7ab594fcca523c379f78b4f278c3
```

### 2. Test Connection

```bash
php artisan ai-engine:test-master --health
```

---

## 📖 Usage Examples

### Using Dependency Injection

```php
use LaravelAIEngine\Services\Node\MasterNodeClient;

class ProductController extends Controller
{
    public function __construct(
        protected MasterNodeClient $masterNode
    ) {}
    
    public function search(Request $request)
    {
        $response = $this->masterNode->search($request->query, [
            'limit' => 20,
            'min_score' => 0.7,
        ]);
        
        if ($response->successful()) {
            return response()->json($response->json());
        }
        
        return response()->json(['error' => 'Search failed'], 500);
    }
}
```

### Using Facade

```php
use LaravelAIEngine\Facades\MasterNode;

// Search across all nodes
$response = MasterNode::search('Laravel tutorials', [
    'limit' => 20,
    'include_nodes' => true,
]);

if ($response->successful()) {
    $results = $response->json('results');
    // Process results
}
```

### Using App Helper

```php
$client = app(\LaravelAIEngine\Services\Node\MasterNodeClient::class);

$response = $client->search('query');
```

---

## 🔧 Available Methods

### Search

Search across the master node (federated search):

```php
$response = $client->search('Laravel AI', [
    'limit' => 20,
    'min_score' => 0.7,
    'collections' => ['App\Models\Post', 'App\Models\Document'],
]);

if ($response->successful()) {
    $results = $response->json('results');
    
    foreach ($results as $result) {
        echo "Title: {$result['title']}\n";
        echo "Score: {$result['score']}\n";
        echo "Source: {$result['node_name']}\n";
    }
}
```

### Execute Remote Action

Execute an action on the master or other nodes:

```php
$response = $client->executeAction('sync-products', [
    'category' => 'electronics',
    'limit' => 100,
    'force' => true,
]);

if ($response->successful()) {
    $result = $response->json();
    echo "Status: {$result['status']}\n";
    echo "Processed: {$result['count']} items\n";
}
```

### Report Health

Send health metrics to the master:

```php
$response = $client->reportHealth([
    'memory_usage' => memory_get_usage(true),
    'cpu_load' => sys_getloadavg()[0] ?? 0,
    'disk_free' => disk_free_space('/'),
    'active_connections' => DB::connection()->select('show status like "Threads_connected"')[0]->Value ?? 0,
]);

if ($response->successful()) {
    echo "Health reported successfully\n";
}
```

### Get Node Info

Retrieve information about this node from the master:

```php
$response = $client->getNodeInfo();

if ($response->successful()) {
    $node = $response->json('node');
    
    echo "Node ID: {$node['id']}\n";
    echo "Status: {$node['status']}\n";
    echo "Last Ping: {$node['last_ping_at']}\n";
}
```

### Update Metadata

Update node metadata on the master:

```php
$response = $client->updateMetadata([
    'version' => '2.0.0',
    'environment' => app()->environment(),
    'features' => ['search', 'rag', 'actions'],
    'custom_data' => [
        'total_products' => Product::count(),
        'total_users' => User::count(),
    ],
]);
```

### Custom Request

Make a custom request to any master endpoint:

```php
$response = $client->request('post', '/api/ai-engine/custom-endpoint', [
    'param1' => 'value1',
    'param2' => 'value2',
]);
```

---

## 🔐 Authentication

The client automatically handles authentication using the best available method:

### Priority Order:
1. **JWT Access Token** (most secure, auto-refreshes)
2. **API Key** (fallback, never expires)
3. **No auth** (logs warning)

### Auto Token Refresh

The client automatically refreshes JWT tokens:
- Checks expiration before each request
- Refreshes if token expires in < 5 minutes
- Retries failed requests with new token
- Falls back to API key if refresh fails

---

## 📊 Scheduled Tasks

### Heartbeat Command

Create a scheduled task to send periodic health checks:

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('ai-engine:test-master --health')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
```

### Custom Health Reporter

```php
use LaravelAIEngine\Services\Node\MasterNodeClient;

class ReportHealthToMaster
{
    public function __invoke(MasterNodeClient $client)
    {
        $client->reportHealth([
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg()[0] ?? 0,
            'queue_size' => Queue::size(),
            'cache_hit_rate' => $this->getCacheHitRate(),
        ]);
    }
    
    protected function getCacheHitRate(): float
    {
        // Your cache hit rate calculation
        return 0.95;
    }
}
```

---

## 🧪 Testing

### Test Master Connection

```bash
# Test all features
php artisan ai-engine:test-master

# Test health check only
php artisan ai-engine:test-master --health

# Test node info
php artisan ai-engine:test-master --info

# Test search
php artisan ai-engine:test-master --search="Laravel tutorials"
```

### Unit Test Example

```php
use LaravelAIEngine\Services\Node\MasterNodeClient;
use Illuminate\Support\Facades\Http;

class MasterNodeClientTest extends TestCase
{
    public function test_search_returns_results()
    {
        Http::fake([
            '*/api/ai-engine/search' => Http::response([
                'success' => true,
                'results' => [
                    ['title' => 'Test Result', 'score' => 0.95],
                ],
            ], 200),
        ]);
        
        $client = app(MasterNodeClient::class);
        $response = $client->search('test query');
        
        $this->assertTrue($response->successful());
        $this->assertCount(1, $response->json('results'));
    }
}
```

---

## 🔧 Configuration

### Child Node .env

```bash
# Node Configuration
AI_ENGINE_NODES_ENABLED=true
AI_ENGINE_IS_MASTER=false
AI_ENGINE_MASTER_URL=https://master.example.com

# JWT Secret (must match master)
AI_ENGINE_JWT_SECRET=base64:your-secret-key

# Node Credentials (from registration)
AI_ENGINE_NODE_ID=7
AI_ENGINE_NODE_SLUG=my-app
AI_ENGINE_NODE_API_KEY=fH1hZj0BfjI1eylr5Ic4g1PleSsZGDiivf2SfMBSlRbeD9hlhKxFqC7bUokKXrRL
AI_ENGINE_ACCESS_TOKEN=eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...
AI_ENGINE_REFRESH_TOKEN=8b2b545a311bcc4a8bdd0c10ec013475a52d7ab594fcca523c379f78b4f278c3
```

---

## 🚨 Error Handling

```php
use LaravelAIEngine\Services\Node\MasterNodeClient;

try {
    $response = $client->search('query');
    
    if ($response->successful()) {
        // Handle success
        $results = $response->json('results');
    } else {
        // Handle HTTP error
        Log::error('Master search failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
    }
} catch (\Exception $e) {
    // Handle exception
    Log::error('Master connection exception', [
        'error' => $e->getMessage(),
    ]);
}
```

---

## 📝 Best Practices

### ✅ DO:
- ✅ Use dependency injection for better testability
- ✅ Handle response errors gracefully
- ✅ Log failed requests for debugging
- ✅ Use scheduled tasks for periodic health checks
- ✅ Keep JWT_SECRET synchronized with master

### ❌ DON'T:
- ❌ Hardcode credentials in code
- ❌ Ignore failed responses
- ❌ Make synchronous requests in web requests (use queues)
- ❌ Share API keys between nodes
- ❌ Commit credentials to version control

---

## 🎯 Common Use Cases

### 1. Federated Product Search

```php
public function searchProducts(string $query)
{
    $response = $this->masterNode->search($query, [
        'collections' => ['App\Models\Product'],
        'limit' => 50,
        'min_score' => 0.6,
    ]);
    
    if ($response->successful()) {
        return $response->json('results');
    }
    
    return [];
}
```

### 2. Sync Data Across Nodes

```php
public function syncToMaster(Product $product)
{
    $this->masterNode->executeAction('product.sync', [
        'product_id' => $product->id,
        'data' => $product->toArray(),
    ]);
}
```

### 3. Distributed Cache Invalidation

```php
public function invalidateCacheAcrossNodes(string $key)
{
    $this->masterNode->executeAction('cache.invalidate', [
        'key' => $key,
        'broadcast' => true, // Invalidate on all nodes
    ]);
}
```

---

**🎉 The MasterNodeClient is now fully integrated and ready to use!**
