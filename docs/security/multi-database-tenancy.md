# Multi-Database Tenancy

This guide covers how to use Laravel AI Engine with multi-database tenant architectures where each tenant has their own database.

## Overview

In multi-database tenancy, each tenant has a completely separate database. The Laravel AI Engine supports this architecture by creating **tenant-specific vector collections**, providing complete data isolation at the vector database level.

## Architecture Comparison

### Single-Database Tenancy (Default)

```
┌─────────────────────────────────────┐
│  vec_emails (single collection)     │
│  ├─ tenant_id: 1  (Acme Corp)       │
│  ├─ tenant_id: 2  (Globex Inc)      │
│  └─ tenant_id: 3  (Initech)         │
└─────────────────────────────────────┘
         ↓ Filter by tenant_id
```

All tenants share the same vector collection, with data isolation enforced by filtering on `tenant_id`.

### Multi-Database Tenancy

```
┌─────────────────────────────────────┐
│  acme_vec_emails                    │
│  └─ (all Acme Corp data)            │
├─────────────────────────────────────┤
│  globex_vec_emails                  │
│  └─ (all Globex Inc data)           │
├─────────────────────────────────────┤
│  initech_vec_emails                 │
│  └─ (all Initech data)              │
└─────────────────────────────────────┘
         ↓ Separate collections
```

Each tenant gets their own vector collection, providing complete isolation.

## Configuration

### Enable Multi-Database Tenancy

```bash
# .env
AI_ENGINE_MULTI_DB_TENANCY=true
```

### Collection Naming Strategy

Choose how tenant collections are named:

```bash
# Options: prefix, suffix, separate
AI_ENGINE_MULTI_DB_COLLECTION_STRATEGY=prefix
```

| Strategy | Base Collection | Tenant Slug | Result |
|----------|-----------------|-------------|--------|
| `prefix` | `vec_emails` | `acme` | `acme_vec_emails` |
| `suffix` | `vec_emails` | `acme` | `vec_emails_acme` |
| `separate` | `vec_emails` | `acme` | `acme/vec_emails` |

### Tenant Resolution

Configure how the current tenant is determined:

```bash
# Options: session, config, database, custom
AI_ENGINE_TENANT_RESOLVER=session

# For session resolver
AI_ENGINE_TENANT_SESSION_KEY=tenant_id

# For config resolver
AI_ENGINE_TENANT_CONFIG_KEY=database.default
```

## Tenant Resolvers

### Session Resolver (Default)

Reads tenant ID from the session:

```php
// In your middleware or controller
session(['tenant_id' => $tenant->slug]);
```

### Config Resolver

Uses a config value (useful for database connection-based tenancy):

```php
// config/vector-access-control.php
'tenant_config_key' => 'database.default',
```

### Database Resolver

Uses the current database connection name:

```php
// Automatically detects tenant from DB connection
// Skips default connections: mysql, sqlite, pgsql, sqlsrv
```

### Custom Resolver

Implement your own resolver for complex setups:

```php
<?php

namespace App\Services;

use LaravelAIEngine\Contracts\TenantResolverInterface;

class MyTenantResolver implements TenantResolverInterface
{
    public function getCurrentTenantId(): ?string
    {
        // Your logic to get tenant ID
        return tenant()?->id;
    }

    public function getCurrentTenantSlug(): ?string
    {
        // URL-safe slug for collection naming
        return tenant()?->slug;
    }

    public function getTenantConnection(): ?string
    {
        // Database connection name
        return tenant()?->database;
    }

    public function hasTenant(): bool
    {
        return tenant() !== null;
    }
}
```

Configure in `config/vector-access-control.php`:

```php
'tenant_resolver' => 'custom',
'custom_tenant_resolver' => \App\Services\MyTenantResolver::class,
```

## Supported Multi-Tenancy Packages

The package auto-detects these popular multi-tenancy packages:

### Spatie Laravel Multitenancy

```php
// Automatically detected
$tenant = \Spatie\Multitenancy\Models\Tenant::current();
```

### Stancl Tenancy

```php
// Automatically detected
$tenant = tenant();
```

### Tenancy for Laravel (Hyn)

```php
// Automatically detected
$env = app(\Hyn\Tenancy\Environment::class);
$tenant = $env->tenant();
```

## Usage Examples

### Indexing Models

Models are automatically indexed to tenant-specific collections:

```php
use LaravelAIEngine\Traits\Vectorizable;

class Email extends Model
{
    use Vectorizable;

    protected $fillable = ['user_id', 'subject', 'body'];
}

// When multi-db tenancy is enabled:
// - Tenant: acme
// - Base collection: email
// - Actual collection: acme_email
$email->index(); // Indexed to acme_email
```

### Searching

Searches automatically use the current tenant's collection:

```php
use LaravelAIEngine\Services\ChatService;

$response = $chatService->processMessage(
    message: 'Show me recent emails',
    sessionId: 'user-session',
    ragCollections: [Email::class],
    userId: auth()->id()
);

// Searches acme_email (not vec_emails)
```

### Manual Collection Access

```php
use LaravelAIEngine\Services\Tenant\MultiTenantVectorService;

$tenantService = app(MultiTenantVectorService::class);

// Get tenant-specific collection name
$collection = $tenantService->getTenantCollectionName('vec_emails');
// Returns: acme_vec_emails

// Check if in tenant context
if ($tenantService->hasTenant()) {
    $tenantId = $tenantService->getCurrentTenantId();
    $tenantSlug = $tenantService->getCurrentTenantSlug();
}
```

## Vector Metadata

When multi-db tenancy is enabled, tenant information is added to vector metadata:

```php
$email->getVectorMetadata();

// Returns:
[
    'user_id' => 1,
    'tenant_id' => 'acme',
    'tenant_slug' => 'acme',
    'tenant_connection' => 'tenant_acme',
    // ... other metadata
]
```

## Best Practices

### 1. Consistent Tenant Slugs

Use URL-safe, consistent slugs for tenant identification:

```php
// Good
'acme-corp'
'globex-inc'

// Avoid
'Acme Corp'  // Spaces
'acme/corp'  // Special characters
```

### 2. Index During Tenant Context

Always index models within the correct tenant context:

```php
// In a tenant-aware job
class IndexEmailJob implements ShouldQueue
{
    public function handle()
    {
        // Ensure tenant context is set
        tenancy()->initialize($this->tenant);
        
        $email = Email::find($this->emailId);
        $email->index();
    }
}
```

### 3. Migration Strategy

When migrating from single-db to multi-db tenancy:

1. Enable multi-db tenancy
2. Re-index all models for each tenant
3. Optionally delete old shared collections

```bash
# Re-index for each tenant
php artisan ai-engine:vector-index --force
```

### 4. Collection Cleanup

Periodically clean up orphaned tenant collections:

```php
use LaravelAIEngine\Services\Vector\VectorDriverManager;

$driver = app(VectorDriverManager::class)->driver();
$collections = $driver->listCollections();

foreach ($collections as $collection) {
    if ($this->isOrphanedTenantCollection($collection)) {
        $driver->deleteCollection($collection);
    }
}
```

## Troubleshooting

### Tenant Not Detected

```php
// Check if tenant is resolved
$tenantService = app(MultiTenantVectorService::class);
dd([
    'has_tenant' => $tenantService->hasTenant(),
    'tenant_id' => $tenantService->getCurrentTenantId(),
    'tenant_slug' => $tenantService->getCurrentTenantSlug(),
]);
```

### Wrong Collection Used

Verify the collection name:

```php
$email = new Email();
dd($email->getVectorCollectionName());
// Should show: acme_email (not just email)
```

### Session Tenant Not Persisting

Ensure session is started before setting tenant:

```php
// In middleware
public function handle($request, $next)
{
    if ($tenant = $this->resolveTenant($request)) {
        session(['tenant_id' => $tenant->slug]);
    }
    
    return $next($request);
}
```

## Configuration Reference

```php
// config/vector-access-control.php

return [
    // Enable multi-database tenant mode
    'multi_db_tenancy' => env('AI_ENGINE_MULTI_DB_TENANCY', false),

    // Collection naming strategy: prefix, suffix, separate
    'multi_db_collection_strategy' => env('AI_ENGINE_MULTI_DB_COLLECTION_STRATEGY', 'prefix'),

    // Tenant resolver: session, config, database, custom
    'tenant_resolver' => env('AI_ENGINE_TENANT_RESOLVER', 'session'),

    // Config key for tenant (when resolver = config)
    'tenant_config_key' => 'database.default',

    // Session key for tenant (when resolver = session)
    'tenant_session_key' => 'tenant_id',

    // Custom resolver class (when resolver = custom)
    'custom_tenant_resolver' => null,
];
```

## See Also

- [Multi-Tenant RAG Access Control](MULTI_TENANT_RAG_ACCESS_CONTROL.md)
- [Simplified Access Control](SIMPLIFIED_ACCESS_CONTROL.md)
- [Vector Search Configuration](vector-search.md)
