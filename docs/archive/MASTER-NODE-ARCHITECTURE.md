# 🌐 Master-Node Architecture - Laravel AI Engine

## 📋 Overview

Transform the Laravel AI Engine into a **Master-Node distributed system** where:
- **Master Node** = This Laravel AI Engine instance
- **Child Nodes** = Other Laravel applications with AI Engine installed
- **Capabilities**: Cross-node search, remote actions, centralized management

---

## 🏗️ Architecture Design

### **System Architecture**

```
┌─────────────────────────────────────────────────────────────┐
│                      MASTER NODE                             │
│              (Laravel AI Engine - Main App)                  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │           Node Registry & Discovery                   │  │
│  │  - Register nodes                                     │  │
│  │  - Health monitoring                                  │  │
│  │  - Auto-discovery                                     │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │        Federated Search Engine                        │  │
│  │  - Search across all nodes                            │  │
│  │  - Aggregate results                                  │  │
│  │  - Rank & merge                                       │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │        Remote Action Executor                         │  │
│  │  - Execute actions on nodes                           │  │
│  │  - Batch operations                                   │  │
│  │  - Transaction support                                │  │
│  └──────────────────────────────────────────────────────┘  │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │           Admin Dashboard                             │  │
│  │  - Node management UI                                 │  │
│  │  - Monitoring & analytics                             │  │
│  │  - Configuration                                      │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
                            │
                            │ REST API / GraphQL / gRPC
                            │
        ┌───────────────────┼───────────────────┐
        │                   │                   │
        ▼                   ▼                   ▼
┌───────────────┐   ┌───────────────┐   ┌───────────────┐
│  CHILD NODE 1 │   │  CHILD NODE 2 │   │  CHILD NODE N │
│   (E-commerce)│   │     (Blog)    │   │   (CRM)       │
│               │   │               │   │               │
│  - Vector DB  │   │  - Vector DB  │   │  - Vector DB  │
│  - Local AI   │   │  - Local AI   │   │  - Local AI   │
│  - Node API   │   │  - Node API   │   │  - Node API   │
└───────────────┘   └───────────────┘   └───────────────┘
```

---

## 🎯 Core Features

### **1. Node Registry & Discovery**
- Register child nodes
- Auto-discovery via broadcast
- Health checks & status monitoring
- Node metadata (capabilities, version, etc.)

### **2. Federated Search**
- Search across all registered nodes
- Aggregate and rank results
- Parallel query execution
- Result merging & deduplication

### **3. Remote Action Execution**
- Execute actions on specific nodes
- Broadcast actions to all nodes
- Transaction support (all-or-nothing)
- Async execution with callbacks

### **4. Authentication & Security**
- API key authentication
- JWT tokens for inter-node communication
- Role-based access control
- Encrypted communication (TLS)

### **5. Monitoring & Analytics**
- Real-time node status
- Performance metrics
- Error tracking
- Usage analytics

---

## 📦 Implementation Plan

### **Phase 1: Core Infrastructure** (Week 1-2)

#### **Task 1.1: Database Schema**

```php
// Migration: create_ai_nodes_table.php
Schema::create('ai_nodes', function (Blueprint $table) {
    $table->id();
    $table->string('name')->unique();
    $table->string('slug')->unique();
    $table->string('type'); // 'master', 'child'
    $table->string('url'); // Base URL
    $table->string('api_key')->nullable();
    $table->json('capabilities')->nullable(); // ['search', 'actions', 'rag']
    $table->json('metadata')->nullable(); // Custom data
    $table->string('version')->nullable();
    $table->enum('status', ['active', 'inactive', 'maintenance', 'error'])->default('active');
    $table->timestamp('last_ping_at')->nullable();
    $table->integer('ping_failures')->default(0);
    $table->timestamps();
    $table->softDeletes();
    
    $table->index(['status', 'type']);
    $table->index('last_ping_at');
});

// Migration: create_ai_node_requests_table.php
Schema::create('ai_node_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('node_id')->constrained('ai_nodes')->onDelete('cascade');
    $table->string('request_type'); // 'search', 'action', 'sync'
    $table->json('payload')->nullable();
    $table->json('response')->nullable();
    $table->integer('status_code')->nullable();
    $table->integer('duration_ms')->nullable();
    $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
    $table->text('error_message')->nullable();
    $table->timestamps();
    
    $table->index(['node_id', 'created_at']);
    $table->index(['request_type', 'status']);
});

// Migration: create_ai_node_search_cache_table.php
Schema::create('ai_node_search_cache', function (Blueprint $table) {
    $table->id();
    $table->string('query_hash')->unique();
    $table->text('query');
    $table->json('node_ids'); // Which nodes were searched
    $table->json('results');
    $table->timestamp('expires_at');
    $table->timestamps();
    
    $table->index('query_hash');
    $table->index('expires_at');
});
```

#### **Task 1.2: Node Model**

```php
// app/Models/AINode.php
namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AINode extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'name',
        'slug',
        'type',
        'url',
        'api_key',
        'capabilities',
        'metadata',
        'version',
        'status',
        'last_ping_at',
        'ping_failures',
    ];
    
    protected $casts = [
        'capabilities' => 'array',
        'metadata' => 'array',
        'last_ping_at' => 'datetime',
    ];
    
    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
    
    public function scopeChild($query)
    {
        return $query->where('type', 'child');
    }
    
    public function scopeMaster($query)
    {
        return $query->where('type', 'master');
    }
    
    public function scopeHealthy($query)
    {
        return $query->where('ping_failures', '<', 3);
    }
    
    // Relationships
    public function requests()
    {
        return $this->hasMany(AINodeRequest::class, 'node_id');
    }
    
    // Methods
    public function isHealthy(): bool
    {
        return $this->status === 'active' 
            && $this->ping_failures < 3
            && $this->last_ping_at?->gt(now()->subMinutes(5));
    }
    
    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities ?? []);
    }
    
    public function getApiUrl(string $endpoint = ''): string
    {
        return rtrim($this->url, '/') . '/api/ai-engine/' . ltrim($endpoint, '/');
    }
    
    public function recordPing(bool $success): void
    {
        if ($success) {
            $this->update([
                'last_ping_at' => now(),
                'ping_failures' => 0,
                'status' => 'active',
            ]);
        } else {
            $this->increment('ping_failures');
            
            if ($this->ping_failures >= 3) {
                $this->update(['status' => 'error']);
            }
        }
    }
}
```

#### **Task 1.3: Node Registry Service**

```php
// src/Services/Node/NodeRegistryService.php
namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class NodeRegistryService
{
    /**
     * Register a new node
     */
    public function register(array $data): AINode
    {
        $node = AINode::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? \Str::slug($data['name']),
            'type' => $data['type'] ?? 'child',
            'url' => $data['url'],
            'api_key' => $data['api_key'] ?? \Str::random(64),
            'capabilities' => $data['capabilities'] ?? ['search', 'actions'],
            'metadata' => $data['metadata'] ?? [],
            'version' => $data['version'] ?? '1.0.0',
            'status' => 'active',
        ]);
        
        // Ping the node to verify connectivity
        $this->ping($node);
        
        // Clear cache
        Cache::forget('ai_nodes_active');
        
        Log::channel('ai-engine')->info('Node registered', [
            'node_id' => $node->id,
            'name' => $node->name,
            'url' => $node->url,
        ]);
        
        return $node;
    }
    
    /**
     * Unregister a node
     */
    public function unregister(AINode $node): bool
    {
        $deleted = $node->delete();
        
        Cache::forget('ai_nodes_active');
        
        Log::channel('ai-engine')->info('Node unregistered', [
            'node_id' => $node->id,
            'name' => $node->name,
        ]);
        
        return $deleted;
    }
    
    /**
     * Get all active nodes
     */
    public function getActiveNodes(): Collection
    {
        return Cache::remember('ai_nodes_active', 300, function () {
            return AINode::active()->healthy()->get();
        });
    }
    
    /**
     * Get node by slug
     */
    public function getNode(string $slug): ?AINode
    {
        return AINode::where('slug', $slug)->first();
    }
    
    /**
     * Ping a node to check health
     */
    public function ping(AINode $node): bool
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $node->api_key,
                    'Accept' => 'application/json',
                ])
                ->get($node->getApiUrl('health'));
            
            $success = $response->successful();
            
            $node->recordPing($success);
            
            if ($success) {
                // Update node metadata from response
                $data = $response->json();
                $node->update([
                    'version' => $data['version'] ?? $node->version,
                    'capabilities' => $data['capabilities'] ?? $node->capabilities,
                ]);
            }
            
            return $success;
        } catch (\Exception $e) {
            $node->recordPing(false);
            
            Log::channel('ai-engine')->error('Node ping failed', [
                'node_id' => $node->id,
                'error' => $e->getMessage(),
            ]);
            
            return false;
        }
    }
    
    /**
     * Ping all nodes
     */
    public function pingAll(): array
    {
        $results = [];
        
        foreach (AINode::all() as $node) {
            $results[$node->slug] = $this->ping($node);
        }
        
        return $results;
    }
    
    /**
     * Get node statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => AINode::count(),
            'active' => AINode::active()->count(),
            'inactive' => AINode::where('status', 'inactive')->count(),
            'error' => AINode::where('status', 'error')->count(),
            'healthy' => AINode::healthy()->count(),
            'by_type' => AINode::groupBy('type')->selectRaw('type, count(*) as count')->pluck('count', 'type'),
        ];
    }
}
```

---

### **Phase 2: Federated Search** (Week 2-3)

#### **Task 2.1: Federated Search Service**

```php
// src/Services/Node/FederatedSearchService.php
namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class FederatedSearchService
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected VectorSearchService $localSearch,
    ) {}
    
    /**
     * Search across all nodes
     */
    public function search(
        string $query,
        ?array $nodeIds = null,
        int $limit = 10,
        array $options = []
    ): array {
        // Generate cache key
        $cacheKey = $this->getCacheKey($query, $nodeIds, $options);
        
        // Check cache
        if ($cached = $this->getCached($cacheKey)) {
            return $cached;
        }
        
        // Get nodes to search
        $nodes = $this->getSearchableNodes($nodeIds);
        
        // Search local node first
        $localResults = $this->searchLocal($query, $limit, $options);
        
        // Search remote nodes in parallel
        $remoteResults = $this->searchRemoteNodes($nodes, $query, $limit, $options);
        
        // Merge and rank results
        $mergedResults = $this->mergeResults($localResults, $remoteResults, $limit);
        
        // Cache results
        $this->cacheResults($cacheKey, $mergedResults);
        
        return $mergedResults;
    }
    
    /**
     * Search local node
     */
    protected function searchLocal(string $query, int $limit, array $options): array
    {
        try {
            $results = $this->localSearch->search($query, $limit, $options);
            
            return [
                'node' => 'master',
                'results' => $results,
                'count' => count($results),
                'duration_ms' => 0, // Will be calculated
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Local search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'node' => 'master',
                'results' => [],
                'count' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Search remote nodes in parallel
     */
    protected function searchRemoteNodes(
        Collection $nodes,
        string $query,
        int $limit,
        array $options
    ): array {
        $promises = [];
        
        foreach ($nodes as $node) {
            $promises[$node->slug] = Http::async()
                ->timeout(10)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $node->api_key,
                    'Accept' => 'application/json',
                ])
                ->post($node->getApiUrl('search'), [
                    'query' => $query,
                    'limit' => $limit,
                    'options' => $options,
                ]);
        }
        
        // Wait for all responses
        $responses = Http::pool(fn ($pool) => array_map(
            fn ($promise) => $promise,
            $promises
        ));
        
        $results = [];
        
        foreach ($responses as $slug => $response) {
            $node = $nodes->firstWhere('slug', $slug);
            
            if ($response->successful()) {
                $data = $response->json();
                
                $results[] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'results' => $data['results'] ?? [],
                    'count' => count($data['results'] ?? []),
                    'duration_ms' => $data['duration_ms'] ?? 0,
                ];
                
                // Record successful request
                $this->recordRequest($node, 'search', true, $response);
            } else {
                Log::channel('ai-engine')->warning('Node search failed', [
                    'node' => $slug,
                    'status' => $response->status(),
                ]);
                
                $results[] = [
                    'node' => $slug,
                    'node_name' => $node->name,
                    'results' => [],
                    'count' => 0,
                    'error' => 'Request failed',
                ];
                
                // Record failed request
                $this->recordRequest($node, 'search', false, $response);
            }
        }
        
        return $results;
    }
    
    /**
     * Merge and rank results from all nodes
     */
    protected function mergeResults(array $local, array $remote, int $limit): array
    {
        $allResults = [];
        
        // Add local results
        foreach ($local['results'] as $result) {
            $allResults[] = array_merge($result, [
                'source_node' => 'master',
                'source_node_name' => 'Master Node',
            ]);
        }
        
        // Add remote results
        foreach ($remote as $nodeResults) {
            foreach ($nodeResults['results'] as $result) {
                $allResults[] = array_merge($result, [
                    'source_node' => $nodeResults['node'],
                    'source_node_name' => $nodeResults['node_name'],
                ]);
            }
        }
        
        // Sort by score (descending)
        usort($allResults, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        
        // Deduplicate by content hash
        $allResults = $this->deduplicateResults($allResults);
        
        // Limit results
        $allResults = array_slice($allResults, 0, $limit);
        
        return [
            'query' => $local['query'] ?? '',
            'total_results' => count($allResults),
            'results' => $allResults,
            'nodes_searched' => count($remote) + 1,
            'node_breakdown' => $this->getNodeBreakdown($allResults),
        ];
    }
    
    /**
     * Deduplicate results by content similarity
     */
    protected function deduplicateResults(array $results): array
    {
        $unique = [];
        $hashes = [];
        
        foreach ($results as $result) {
            $hash = md5($result['content'] ?? '');
            
            if (!in_array($hash, $hashes)) {
                $unique[] = $result;
                $hashes[] = $hash;
            }
        }
        
        return $unique;
    }
    
    /**
     * Get searchable nodes
     */
    protected function getSearchableNodes(?array $nodeIds): Collection
    {
        $query = AINode::active()->healthy()->child();
        
        if ($nodeIds) {
            $query->whereIn('id', $nodeIds);
        }
        
        return $query->get();
    }
    
    /**
     * Record node request
     */
    protected function recordRequest(AINode $node, string $type, bool $success, $response): void
    {
        AINodeRequest::create([
            'node_id' => $node->id,
            'request_type' => $type,
            'status' => $success ? 'success' : 'failed',
            'status_code' => $response->status(),
            'duration_ms' => $response->transferStats?->getTransferTime() * 1000,
            'error_message' => $success ? null : $response->body(),
        ]);
    }
    
    /**
     * Cache results
     */
    protected function cacheResults(string $key, array $results): void
    {
        Cache::put($key, $results, now()->addMinutes(15));
    }
    
    /**
     * Get cached results
     */
    protected function getCached(string $key): ?array
    {
        return Cache::get($key);
    }
    
    /**
     * Generate cache key
     */
    protected function getCacheKey(string $query, ?array $nodeIds, array $options): string
    {
        return 'federated_search:' . md5($query . json_encode($nodeIds) . json_encode($options));
    }
}
```

---

### **Phase 3: Remote Action Execution** (Week 3-4)

#### **Task 3.1: Remote Action Service**

```php
// src/Services/Node/RemoteActionService.php
namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;

class RemoteActionService
{
    public function __construct(
        protected NodeRegistryService $registry,
    ) {}
    
    /**
     * Execute action on specific node
     */
    public function executeOn(string $nodeSlug, string $action, array $params = []): array
    {
        $node = $this->registry->getNode($nodeSlug);
        
        if (!$node) {
            throw new \Exception("Node not found: {$nodeSlug}");
        }
        
        if (!$node->hasCapability('actions')) {
            throw new \Exception("Node does not support actions: {$nodeSlug}");
        }
        
        return $this->sendAction($node, $action, $params);
    }
    
    /**
     * Execute action on all nodes
     */
    public function executeOnAll(string $action, array $params = [], bool $parallel = true): array
    {
        $nodes = $this->registry->getActiveNodes();
        
        if ($parallel) {
            return $this->executeParallel($nodes, $action, $params);
        }
        
        return $this->executeSequential($nodes, $action, $params);
    }
    
    /**
     * Execute action on multiple nodes (parallel)
     */
    protected function executeParallel(Collection $nodes, string $action, array $params): array
    {
        $promises = [];
        
        foreach ($nodes as $node) {
            if (!$node->hasCapability('actions')) {
                continue;
            }
            
            $promises[$node->slug] = Http::async()
                ->timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $node->api_key,
                    'Accept' => 'application/json',
                ])
                ->post($node->getApiUrl('actions'), [
                    'action' => $action,
                    'params' => $params,
                ]);
        }
        
        $responses = Http::pool(fn ($pool) => array_map(
            fn ($promise) => $promise,
            $promises
        ));
        
        $results = [];
        
        foreach ($responses as $slug => $response) {
            $node = $nodes->firstWhere('slug', $slug);
            
            $results[$slug] = [
                'node' => $slug,
                'node_name' => $node->name,
                'success' => $response->successful(),
                'status_code' => $response->status(),
                'data' => $response->json(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        }
        
        return $results;
    }
    
    /**
     * Execute action on multiple nodes (sequential)
     */
    protected function executeSequential(Collection $nodes, string $action, array $params): array
    {
        $results = [];
        
        foreach ($nodes as $node) {
            if (!$node->hasCapability('actions')) {
                continue;
            }
            
            try {
                $result = $this->sendAction($node, $action, $params);
                $results[$node->slug] = array_merge($result, ['success' => true]);
            } catch (\Exception $e) {
                $results[$node->slug] = [
                    'node' => $node->slug,
                    'node_name' => $node->name,
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Send action to node
     */
    protected function sendAction(AINode $node, string $action, array $params): array
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $node->api_key,
                'Accept' => 'application/json',
            ])
            ->post($node->getApiUrl('actions'), [
                'action' => $action,
                'params' => $params,
            ]);
        
        if (!$response->successful()) {
            throw new \Exception("Action failed on node {$node->slug}: " . $response->body());
        }
        
        return [
            'node' => $node->slug,
            'node_name' => $node->name,
            'status_code' => $response->status(),
            'data' => $response->json(),
        ];
    }
    
    /**
     * Execute transaction across nodes (all-or-nothing)
     */
    public function executeTransaction(array $actions): array
    {
        $results = [];
        $rollbacks = [];
        
        try {
            // Execute all actions
            foreach ($actions as $nodeSlug => $actionData) {
                $result = $this->executeOn($nodeSlug, $actionData['action'], $actionData['params']);
                $results[$nodeSlug] = $result;
                
                // Store rollback action if provided
                if (isset($actionData['rollback'])) {
                    $rollbacks[$nodeSlug] = $actionData['rollback'];
                }
            }
            
            return [
                'success' => true,
                'results' => $results,
            ];
        } catch (\Exception $e) {
            // Rollback all successful actions
            foreach ($rollbacks as $nodeSlug => $rollbackAction) {
                try {
                    $this->executeOn($nodeSlug, $rollbackAction['action'], $rollbackAction['params']);
                } catch (\Exception $rollbackError) {
                    Log::channel('ai-engine')->error('Rollback failed', [
                        'node' => $nodeSlug,
                        'error' => $rollbackError->getMessage(),
                    ]);
                }
            }
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'partial_results' => $results,
            ];
        }
    }
}
```

---

### **Phase 4: Node API Endpoints** (Week 4)

#### **Task 4.1: Node API Controller**

```php
// src/Http/Controllers/Node/NodeApiController.php
namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\FederatedSearchService;
use LaravelAIEngine\Services\Node\RemoteActionService;

class NodeApiController
{
    /**
     * Health check endpoint
     */
    public function health()
    {
        return response()->json([
            'status' => 'healthy',
            'version' => config('ai-engine.version', '1.0.0'),
            'capabilities' => ['search', 'actions', 'rag'],
            'timestamp' => now()->toIso8601String(),
        ]);
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
        
        $results = $searchService->search(
            $validated['query'],
            $validated['limit'] ?? 10,
            $validated['options'] ?? []
        );
        
        $duration = (microtime(true) - $startTime) * 1000;
        
        return response()->json([
            'results' => $results,
            'count' => count($results),
            'duration_ms' => round($duration, 2),
        ]);
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
    }
    
    /**
     * Node registration endpoint (for child nodes to register themselves)
     */
    public function register(Request $request, NodeRegistryService $registry)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'url' => 'required|url',
            'capabilities' => 'array',
            'metadata' => 'array',
        ]);
        
        $node = $registry->register($validated);
        
        return response()->json([
            'success' => true,
            'node' => $node,
            'api_key' => $node->api_key,
        ], 201);
    }
}
```

#### **Task 4.2: API Routes**

```php
// routes/node-api.php
use LaravelAIEngine\Http\Controllers\Node\NodeApiController;
use LaravelAIEngine\Http\Middleware\NodeAuthMiddleware;

Route::prefix('api/ai-engine')->group(function () {
    // Public endpoints
    Route::get('health', [NodeApiController::class, 'health']);
    Route::post('register', [NodeApiController::class, 'register']);
    
    // Protected endpoints (require API key)
    Route::middleware(NodeAuthMiddleware::class)->group(function () {
        Route::post('search', [NodeApiController::class, 'search']);
        Route::post('actions', [NodeApiController::class, 'executeAction']);
    });
});
```

---

## 📊 Usage Examples

### **Example 1: Register a Child Node**

```php
use LaravelAIEngine\Services\Node\NodeRegistryService;

$registry = app(NodeRegistryService::class);

$node = $registry->register([
    'name' => 'E-commerce Store',
    'slug' => 'ecommerce',
    'url' => 'https://shop.example.com',
    'capabilities' => ['search', 'actions'],
    'metadata' => [
        'type' => 'ecommerce',
        'products_count' => 10000,
    ],
]);

// API key is auto-generated
echo "API Key: {$node->api_key}";
```

### **Example 2: Federated Search**

```php
use LaravelAIEngine\Services\Node\FederatedSearchService;

$search = app(FederatedSearchService::class);

// Search across all nodes
$results = $search->search('Laravel tutorials', limit: 20);

echo "Found {$results['total_results']} results from {$results['nodes_searched']} nodes\n";

foreach ($results['results'] as $result) {
    echo "- {$result['title']} (from {$result['source_node_name']})\n";
}
```

### **Example 3: Remote Action Execution**

```php
use LaravelAIEngine\Services\Node\RemoteActionService;

$actions = app(RemoteActionService::class);

// Execute on specific node
$result = $actions->executeOn('ecommerce', 'index', [
    'model' => 'Product',
    'batch_size' => 100,
]);

// Execute on all nodes
$results = $actions->executeOnAll('sync', [
    'force' => true,
]);

foreach ($results as $nodeSlug => $result) {
    if ($result['success']) {
        echo "✅ {$result['node_name']}: Success\n";
    } else {
        echo "❌ {$result['node_name']}: {$result['error']}\n";
    }
}
```

### **Example 4: Transaction Across Nodes**

```php
$result = $actions->executeTransaction([
    'ecommerce' => [
        'action' => 'update_inventory',
        'params' => ['product_id' => 123, 'quantity' => -1],
        'rollback' => [
            'action' => 'update_inventory',
            'params' => ['product_id' => 123, 'quantity' => 1],
        ],
    ],
    'crm' => [
        'action' => 'create_order',
        'params' => ['product_id' => 123, 'customer_id' => 456],
        'rollback' => [
            'action' => 'cancel_order',
            'params' => ['order_id' => '{{order_id}}'],
        ],
    ],
]);

if ($result['success']) {
    echo "Transaction completed successfully!\n";
} else {
    echo "Transaction failed and rolled back: {$result['error']}\n";
}
```

---

## 🎯 Next Steps

1. **Implement Phase 1** - Core infrastructure (database, models, registry)
2. **Implement Phase 2** - Federated search
3. **Implement Phase 3** - Remote actions
4. **Implement Phase 4** - API endpoints
5. **Add monitoring dashboard**
6. **Add comprehensive tests**
7. **Write documentation**

---

## 📝 Configuration

```php
// config/ai-engine.php
'nodes' => [
    'enabled' => env('AI_ENGINE_NODES_ENABLED', true),
    'master' => env('AI_ENGINE_IS_MASTER', true),
    'master_url' => env('AI_ENGINE_MASTER_URL'),
    'api_key' => env('AI_ENGINE_NODE_API_KEY'),
    'auto_register' => env('AI_ENGINE_AUTO_REGISTER', false),
    'health_check_interval' => 300, // 5 minutes
    'request_timeout' => 30, // seconds
    'cache_ttl' => 900, // 15 minutes
],
```

---

**Ready to implement the master-node architecture!** 🚀
