---
name: laravel-multi-tenant
description: Set up multi-tenant architecture with data isolation and security. Use this when the user wants to build a SaaS application, implement workspace isolation, or needs tenant-based data separation.
---

# Laravel Multi-Tenant Setup

Configure multi-tenant architecture with AI-powered security and complete data isolation for SaaS applications.

## When to Use This Skill

- User is building a SaaS application
- User needs workspace/organization isolation
- User wants tenant-based data separation
- User needs subdomain-based tenancy
- User wants database-per-tenant architecture

## Tenancy Types

### 1. Workspace-Based (Recommended)
- Single database
- `workspace_id` column on tables
- Fast and efficient
- Easy to manage

### 2. Database-Per-Tenant
- Separate database for each tenant
- Complete isolation
- More complex
- Better for compliance

### 3. Subdomain-Based
- Each tenant gets subdomain (tenant1.app.com)
- Works with workspace or database tenancy
- Professional appearance

### 4. Hybrid
- Combination of above approaches
- Flexible for different tenant tiers

## Setup Process

### Step 1: Choose Tenancy Type

```bash
# Interactive setup
php artisan ai-engine:setup-tenancy

# Quick setup
php artisan ai-engine:setup-tenancy --type=workspace
```

### Step 2: Database Setup

#### Workspace-Based Tenancy

```php
// Migration: create_workspaces_table
Schema::create('workspaces', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->json('settings')->nullable();
    $table->timestamps();
});

// Migration: add workspace_id to tables
Schema::table('products', function (Blueprint $table) {
    $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
    $table->index('workspace_id');
});
```

### Step 3: Model Configuration

```php
use LaravelAIEngine\Traits\BelongsToWorkspace;

class Product extends Model
{
    use BelongsToWorkspace;

    // Automatically scopes all queries to current workspace
    // Automatically sets workspace_id on create
}

// Usage - automatically scoped
$products = Product::all(); // Only current workspace products
$product = Product::create([...]); // Automatically sets workspace_id
```

### Step 4: Middleware Setup

```php
// app/Http/Kernel.php
protected $middlewareGroups = [
    'web' => [
        \LaravelAIEngine\Middleware\SetWorkspaceFromSession::class,
    ],
    'api' => [
        \LaravelAIEngine\Middleware\SetWorkspaceFromToken::class,
    ],
];
```

### Step 5: User-Workspace Relationship

```php
// User model
class User extends Authenticatable
{
    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function currentWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function switchWorkspace(Workspace $workspace)
    {
        if (!$this->workspaces->contains($workspace)) {
            throw new UnauthorizedException();
        }

        $this->update(['current_workspace_id' => $workspace->id]);
    }
}
```

## Complete Example: Workspace Tenancy

### 1. Models

```php
// Workspace Model
class Workspace extends Model
{
    protected $fillable = ['name', 'slug', 'settings'];

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}

// Product Model
class Product extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['name', 'price', 'workspace_id'];
}
```

### 2. Controllers

```php
class ProductController extends Controller
{
    public function index()
    {
        // Automatically scoped to current workspace
        $products = Product::paginate(20);
        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request)
    {
        // workspace_id automatically set
        $product = Product::create($request->validated());
        return new ProductResource($product);
    }
}

class WorkspaceController extends Controller
{
    public function switch(Workspace $workspace)
    {
        auth()->user()->switchWorkspace($workspace);
        return response()->json(['message' => 'Workspace switched']);
    }
}
```

### 3. API Routes

```php
Route::middleware(['auth:sanctum'])->group(function () {
    // Workspace management
    Route::post('/workspaces/switch/{workspace}', [WorkspaceController::class, 'switch']);
    Route::get('/workspaces', [WorkspaceController::class, 'index']);

    // Tenant-scoped resources
    Route::apiResource('products', ProductController::class);
    Route::apiResource('customers', CustomerController::class);
});
```

## Database-Per-Tenant Setup

```php
// config/database.php
'connections' => [
    'tenant' => [
        'driver' => 'mysql',
        'host' => env('DB_HOST'),
        'database' => null, // Set dynamically
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
    ],
],

// Middleware
class SetTenantDatabase
{
    public function handle($request, Closure $next)
    {
        $tenant = Tenant::where('domain', $request->getHost())->firstOrFail();
        
        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        
        return $next($request);
    }
}
```

## Subdomain Tenancy

```php
// routes/web.php
Route::domain('{workspace}.'.config('app.domain'))->group(function () {
    Route::get('/', [DashboardController::class, 'index']);
    // Other routes...
});

// Middleware
class SetWorkspaceFromSubdomain
{
    public function handle($request, Closure $next)
    {
        $subdomain = explode('.', $request->getHost())[0];
        
        $workspace = Workspace::where('slug', $subdomain)->firstOrFail();
        
        app()->instance('current_workspace', $workspace);
        
        return $next($request);
    }
}
```

## Security Features

### 1. Automatic Scoping
```php
// All queries automatically scoped
Product::all(); // Only current workspace
Product::find(1); // Only if belongs to current workspace
```

### 2. Cross-Tenant Protection
```php
// Throws exception if product belongs to different workspace
$product = Product::findOrFail($id);
```

### 3. AI-Powered Security Checks
```php
// Automatic security validation
$product->update($data); // Validates workspace ownership
```

## Testing Multi-Tenancy

```php
class ProductTest extends TestCase
{
    public function test_products_are_scoped_to_workspace()
    {
        $workspace1 = Workspace::factory()->create();
        $workspace2 = Workspace::factory()->create();

        $product1 = Product::factory()->for($workspace1)->create();
        $product2 = Product::factory()->for($workspace2)->create();

        // Switch to workspace1
        $this->actingAs($user)->withWorkspace($workspace1);

        $products = Product::all();

        $this->assertTrue($products->contains($product1));
        $this->assertFalse($products->contains($product2));
    }
}
```

## Artisan Commands

```bash
# Setup tenancy
php artisan ai-engine:setup-tenancy --type=workspace

# Create new tenant
php artisan tenant:create "Acme Corp" --slug=acme

# Migrate tenant database
php artisan tenant:migrate --tenant=acme

# List all tenants
php artisan tenant:list

# Switch tenant (for testing)
php artisan tenant:use acme
```

## Best Practices

1. **Always Use Traits**: Use `BelongsToWorkspace` trait on all tenant-scoped models
2. **Test Isolation**: Write tests to verify data isolation
3. **Index workspace_id**: Always index workspace_id columns
4. **Cascade Deletes**: Use `cascadeOnDelete()` on foreign keys
5. **Validate Access**: Always validate user has access to workspace
6. **Audit Logging**: Log workspace switches and access attempts

## Example Prompts

- "Set up workspace-based multi-tenancy for my SaaS application"
- "Implement database-per-tenant architecture with automatic provisioning"
- "Create subdomain-based tenancy with workspace isolation"
- "Add multi-tenant support to existing Laravel application"
