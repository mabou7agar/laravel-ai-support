# 🌐 Master-Node Architecture - Implementation Tasks

## 📋 Task Breakdown (16 Tasks)

Complete step-by-step implementation guide for the master-node distributed system.

---

## **Phase 1: Database Layer** (Tasks 1-3)

### ✅ **Task 1: Create ai_nodes Migration**
**Priority:** High  
**Effort:** 30 minutes  
**Dependencies:** None

**What to do:**
```bash
php artisan make:migration create_ai_nodes_table
```

**File:** `database/migrations/YYYY_MM_DD_create_ai_nodes_table.php`

**Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            
            // Indexes
            $table->index(['status', 'type']);
            $table->index('last_ping_at');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_nodes');
    }
};
```

**Success Criteria:**
- ✅ Migration file created
- ✅ All columns defined
- ✅ Indexes added
- ✅ Migration runs successfully

---

### ✅ **Task 2: Create ai_node_requests Migration**
**Priority:** High  
**Effort:** 20 minutes  
**Dependencies:** Task 1

**What to do:**
```bash
php artisan make:migration create_ai_node_requests_table
```

**File:** `database/migrations/YYYY_MM_DD_create_ai_node_requests_table.php`

**Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('ai_nodes')->onDelete('cascade');
            $table->string('request_type'); // 'search', 'action', 'sync', 'ping'
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->integer('status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['node_id', 'created_at']);
            $table->index(['request_type', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_requests');
    }
};
```

**Success Criteria:**
- ✅ Migration file created
- ✅ Foreign key to ai_nodes
- ✅ Indexes added
- ✅ Migration runs successfully

---

### ✅ **Task 3: Create ai_node_search_cache Migration**
**Priority:** Medium  
**Effort:** 15 minutes  
**Dependencies:** None

**What to do:**
```bash
php artisan make:migration create_ai_node_search_cache_table
```

**File:** `database/migrations/YYYY_MM_DD_create_ai_node_search_cache_table.php`

**Code:**
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_node_search_cache', function (Blueprint $table) {
            $table->id();
            $table->string('query_hash', 64)->unique();
            $table->text('query');
            $table->json('node_ids'); // Which nodes were searched
            $table->json('results');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            // Indexes
            $table->index('query_hash');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_node_search_cache');
    }
};
```

**Success Criteria:**
- ✅ Migration file created
- ✅ Cache structure defined
- ✅ Indexes added
- ✅ Migration runs successfully

---

## **Phase 2: Models** (Tasks 4-5)

### ✅ **Task 4: Create AINode Model**
**Priority:** High  
**Effort:** 45 minutes  
**Dependencies:** Task 1

**What to do:**
```bash
# Model will be created manually
```

**File:** `src/Models/AINode.php`

**Code:**
```php
<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AINode extends Model
{
    use SoftDeletes;
    
    protected $table = 'ai_nodes';
    
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
        'ping_failures' => 'integer',
    ];
    
    protected $hidden = [
        'api_key',
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
        return $query->where('ping_failures', '<', 3)
                     ->where('last_ping_at', '>=', now()->subMinutes(10));
    }
    
    public function scopeWithCapability($query, string $capability)
    {
        return $query->whereJsonContains('capabilities', $capability);
    }
    
    // Relationships
    public function requests(): HasMany
    {
        return $this->hasMany(AINodeRequest::class, 'node_id');
    }
    
    // Methods
    public function isHealthy(): bool
    {
        return $this->status === 'active' 
            && $this->ping_failures < 3
            && $this->last_ping_at?->gt(now()->subMinutes(10));
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
    
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'active' => 'green',
            'inactive' => 'gray',
            'maintenance' => 'yellow',
            'error' => 'red',
            default => 'gray',
        };
    }
}
```

**Success Criteria:**
- ✅ Model created
- ✅ All scopes implemented
- ✅ Relationships defined
- ✅ Helper methods added

---

### ✅ **Task 5: Create AINodeRequest Model**
**Priority:** Medium  
**Effort:** 20 minutes  
**Dependencies:** Task 2, Task 4

**File:** `src/Models/AINodeRequest.php`

**Code:**
```php
<?php

namespace LaravelAIEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AINodeRequest extends Model
{
    protected $table = 'ai_node_requests';
    
    protected $fillable = [
        'node_id',
        'request_type',
        'payload',
        'response',
        'status_code',
        'duration_ms',
        'status',
        'error_message',
    ];
    
    protected $casts = [
        'payload' => 'array',
        'response' => 'array',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];
    
    // Relationships
    public function node(): BelongsTo
    {
        return $this->belongsTo(AINode::class, 'node_id');
    }
    
    // Scopes
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
    
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }
    
    public function scopeOfType($query, string $type)
    {
        return $query->where('request_type', $type);
    }
    
    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }
    
    // Methods
    public function isSuccessful(): bool
    {
        return $this->status === 'success';
    }
    
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
```

**Success Criteria:**
- ✅ Model created
- ✅ Relationship to AINode
- ✅ Scopes implemented
- ✅ Helper methods added

---

## **Phase 3: Services** (Tasks 6-8)

### ✅ **Task 6: Create NodeRegistryService**
**Priority:** High  
**Effort:** 1 hour  
**Dependencies:** Task 4, Task 5

**File:** `src/Services/Node/NodeRegistryService.php`

**Code:** See MASTER-NODE-ARCHITECTURE.md (Task 1.3)

**Key Methods:**
- `register(array $data): AINode`
- `unregister(AINode $node): bool`
- `getActiveNodes(): Collection`
- `getNode(string $slug): ?AINode`
- `ping(AINode $node): bool`
- `pingAll(): array`
- `getStatistics(): array`

**Success Criteria:**
- ✅ Service created
- ✅ All methods implemented
- ✅ Logging added
- ✅ Cache implemented

---

### ✅ **Task 7: Create FederatedSearchService**
**Priority:** High  
**Effort:** 2 hours  
**Dependencies:** Task 6

**File:** `src/Services/Node/FederatedSearchService.php`

**Code:** See MASTER-NODE-ARCHITECTURE.md (Task 2.1)

**Key Methods:**
- `search(string $query, ?array $nodeIds, int $limit, array $options): array`
- `searchLocal(string $query, int $limit, array $options): array`
- `searchRemoteNodes(Collection $nodes, ...): array`
- `mergeResults(array $local, array $remote, int $limit): array`
- `deduplicateResults(array $results): array`

**Success Criteria:**
- ✅ Service created
- ✅ Parallel search working
- ✅ Result merging implemented
- ✅ Caching working
- ✅ Deduplication working

---

### ✅ **Task 8: Create RemoteActionService**
**Priority:** High  
**Effort:** 1.5 hours  
**Dependencies:** Task 6

**File:** `src/Services/Node/RemoteActionService.php`

**Code:** See MASTER-NODE-ARCHITECTURE.md (Task 3.1)

**Key Methods:**
- `executeOn(string $nodeSlug, string $action, array $params): array`
- `executeOnAll(string $action, array $params, bool $parallel): array`
- `executeParallel(Collection $nodes, ...): array`
- `executeSequential(Collection $nodes, ...): array`
- `executeTransaction(array $actions): array`

**Success Criteria:**
- ✅ Service created
- ✅ Single node execution working
- ✅ Parallel execution working
- ✅ Transaction support working
- ✅ Rollback working

---

## **Phase 4: API Layer** (Tasks 9-11)

### ✅ **Task 9: Create NodeApiController**
**Priority:** High  
**Effort:** 1 hour  
**Dependencies:** Task 6, Task 7, Task 8

**File:** `src/Http/Controllers/Node/NodeApiController.php`

**Code:** See MASTER-NODE-ARCHITECTURE.md (Task 4.1)

**Endpoints:**
- `GET /api/ai-engine/health` - Health check
- `POST /api/ai-engine/search` - Search endpoint
- `POST /api/ai-engine/actions` - Action execution
- `POST /api/ai-engine/register` - Node registration

**Success Criteria:**
- ✅ Controller created
- ✅ All endpoints implemented
- ✅ Validation added
- ✅ Error handling added

---

### ✅ **Task 10: Create NodeAuthMiddleware**
**Priority:** High  
**Effort:** 30 minutes  
**Dependencies:** Task 4

**File:** `src/Http/Middleware/NodeAuthMiddleware.php`

**Code:**
```php
<?php

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LaravelAIEngine\Models\AINode;

class NodeAuthMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $apiKey = $request->bearerToken();
        
        if (!$apiKey) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'API key required',
            ], 401);
        }
        
        $node = AINode::where('api_key', $apiKey)->first();
        
        if (!$node) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid API key',
            ], 401);
        }
        
        if ($node->status !== 'active') {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'Node is not active',
            ], 403);
        }
        
        // Attach node to request
        $request->attributes->set('node', $node);
        
        return $next($request);
    }
}
```

**Success Criteria:**
- ✅ Middleware created
- ✅ API key validation working
- ✅ Node status check working
- ✅ Node attached to request

---

### ✅ **Task 11: Add Node API Routes**
**Priority:** High  
**Effort:** 20 minutes  
**Dependencies:** Task 9, Task 10

**File:** `routes/node-api.php`

**Code:**
```php
<?php

use Illuminate\Support\Facades\Route;
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
        Route::get('status', [NodeApiController::class, 'status']);
    });
});
```

**Success Criteria:**
- ✅ Routes file created
- ✅ Public routes defined
- ✅ Protected routes defined
- ✅ Middleware applied

---

## **Phase 5: Integration** (Tasks 12-13)

### ✅ **Task 12: Register Services in Provider**
**Priority:** High  
**Effort:** 30 minutes  
**Dependencies:** Task 6, Task 7, Task 8

**File:** `src/AIEngineServiceProvider.php`

**Add to `registerEnterpriseServices()` method:**
```php
// Node Management Services
$this->app->singleton(\LaravelAIEngine\Services\Node\NodeRegistryService::class, function ($app) {
    return new \LaravelAIEngine\Services\Node\NodeRegistryService();
});

$this->app->singleton(\LaravelAIEngine\Services\Node\FederatedSearchService::class, function ($app) {
    return new \LaravelAIEngine\Services\Node\FederatedSearchService(
        $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class),
        $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class)
    );
});

$this->app->singleton(\LaravelAIEngine\Services\Node\RemoteActionService::class, function ($app) {
    return new \LaravelAIEngine\Services\Node\RemoteActionService(
        $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class)
    );
});
```

**Add to `boot()` method:**
```php
// Load node API routes
$this->loadRoutesFrom(__DIR__.'/../routes/node-api.php');
```

**Success Criteria:**
- ✅ Services registered
- ✅ Dependencies injected
- ✅ Routes loaded

---

### ✅ **Task 13: Add Node Configuration**
**Priority:** High  
**Effort:** 20 minutes  
**Dependencies:** None

**File:** `config/ai-engine.php`

**Add to config array:**
```php
/*
|--------------------------------------------------------------------------
| Node Management
|--------------------------------------------------------------------------
|
| Configure the master-node distributed architecture.
|
*/
'nodes' => [
    // Enable node management
    'enabled' => env('AI_ENGINE_NODES_ENABLED', true),
    
    // Is this the master node?
    'is_master' => env('AI_ENGINE_IS_MASTER', true),
    
    // Master node URL (for child nodes)
    'master_url' => env('AI_ENGINE_MASTER_URL'),
    
    // API key for this node (auto-generated if not set)
    'api_key' => env('AI_ENGINE_NODE_API_KEY'),
    
    // Auto-register with master on boot
    'auto_register' => env('AI_ENGINE_AUTO_REGISTER', false),
    
    // Health check interval (seconds)
    'health_check_interval' => env('AI_ENGINE_HEALTH_CHECK_INTERVAL', 300),
    
    // Request timeout (seconds)
    'request_timeout' => env('AI_ENGINE_REQUEST_TIMEOUT', 30),
    
    // Search cache TTL (seconds)
    'cache_ttl' => env('AI_ENGINE_CACHE_TTL', 900),
    
    // Max parallel requests
    'max_parallel_requests' => env('AI_ENGINE_MAX_PARALLEL_REQUESTS', 10),
],
```

**Success Criteria:**
- ✅ Configuration added
- ✅ Environment variables documented
- ✅ Sensible defaults set

---

## **Phase 6: Commands** (Task 14)

### ✅ **Task 14: Create Artisan Commands**
**Priority:** Medium  
**Effort:** 1.5 hours  
**Dependencies:** Task 6, Task 7, Task 8

**Commands to create:**

#### **14.1: RegisterNodeCommand**
```bash
php artisan make:command Node/RegisterNodeCommand
```

**File:** `src/Console/Commands/Node/RegisterNodeCommand.php`

**Signature:** `ai-engine:node-register`

**Code:**
```php
<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class RegisterNodeCommand extends Command
{
    protected $signature = 'ai-engine:node-register
                            {name : Node name}
                            {url : Node URL}
                            {--capabilities=* : Node capabilities}
                            {--type=child : Node type (master/child)}';
    
    protected $description = 'Register a new node';
    
    public function handle(NodeRegistryService $registry)
    {
        $node = $registry->register([
            'name' => $this->argument('name'),
            'url' => $this->argument('url'),
            'type' => $this->option('type'),
            'capabilities' => $this->option('capabilities') ?: ['search', 'actions'],
        ]);
        
        $this->info("Node registered successfully!");
        $this->table(
            ['ID', 'Name', 'URL', 'API Key'],
            [[$node->id, $node->name, $node->url, $node->api_key]]
        );
        
        $this->warn("Save this API key - it won't be shown again!");
    }
}
```

#### **14.2: ListNodesCommand**
**Signature:** `ai-engine:node-list`

#### **14.3: PingNodesCommand**
**Signature:** `ai-engine:node-ping {--all}`

#### **14.4: SearchNodesCommand**
**Signature:** `ai-engine:node-search {query} {--nodes=*}`

**Success Criteria:**
- ✅ All 4 commands created
- ✅ Commands registered in provider
- ✅ Help text added
- ✅ Commands working

---

## **Phase 7: Testing & Documentation** (Tasks 15-16)

### ✅ **Task 15: Add Tests**
**Priority:** Medium  
**Effort:** 2 hours  
**Dependencies:** All previous tasks

**Tests to create:**

**File:** `tests/Unit/Services/Node/NodeRegistryServiceTest.php`
**File:** `tests/Unit/Services/Node/FederatedSearchServiceTest.php`
**File:** `tests/Unit/Services/Node/RemoteActionServiceTest.php`
**File:** `tests/Feature/Node/NodeApiTest.php`

**Success Criteria:**
- ✅ Unit tests for services
- ✅ Feature tests for API
- ✅ All tests passing
- ✅ 80%+ coverage

---

### ✅ **Task 16: Create Documentation**
**Priority:** Medium  
**Effort:** 1 hour  
**Dependencies:** All previous tasks

**Files to create:**
- `docs/NODES.md` - Complete node documentation
- `docs/FEDERATED-SEARCH.md` - Search documentation
- `docs/REMOTE-ACTIONS.md` - Actions documentation

**Update:**
- `README.md` - Add node features
- `DOCUMENTATION-INDEX.md` - Add node docs

**Success Criteria:**
- ✅ All docs created
- ✅ Examples added
- ✅ README updated
- ✅ Index updated

---

## 📊 Summary

### **Total Tasks: 16**

| Phase | Tasks | Effort | Status |
|-------|-------|--------|--------|
| **Phase 1: Database** | 3 | 1h 5min | Pending |
| **Phase 2: Models** | 2 | 1h 5min | Pending |
| **Phase 3: Services** | 3 | 4h 30min | Pending |
| **Phase 4: API** | 3 | 1h 50min | Pending |
| **Phase 5: Integration** | 2 | 50min | Pending |
| **Phase 6: Commands** | 1 | 1h 30min | Pending |
| **Phase 7: Testing** | 2 | 3h | Pending |
| **TOTAL** | **16** | **~14 hours** | **0% Complete** |

---

## 🚀 Getting Started

### **Start with Task 1:**
```bash
cd /Volumes/M.2/Work/laravel-ai-demo/packages/laravel-ai-engine
php artisan make:migration create_ai_nodes_table
```

Then follow the code in Task 1 above!

---

## ✅ Progress Tracking

- [ ] Task 1: ai_nodes migration
- [ ] Task 2: ai_node_requests migration
- [ ] Task 3: ai_node_search_cache migration
- [ ] Task 4: AINode model
- [ ] Task 5: AINodeRequest model
- [ ] Task 6: NodeRegistryService
- [ ] Task 7: FederatedSearchService
- [ ] Task 8: RemoteActionService
- [ ] Task 9: NodeApiController
- [ ] Task 10: NodeAuthMiddleware
- [ ] Task 11: Node API routes
- [ ] Task 12: Register services
- [ ] Task 13: Node configuration
- [ ] Task 14: Artisan commands
- [ ] Task 15: Tests
- [ ] Task 16: Documentation

---

**Ready to start? Let's begin with Task 1!** 🎯
