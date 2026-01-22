# Tenant/Workspace-Based Credits Setup

By default, the AI Engine tracks credits per user. However, you can configure it to track credits at the **tenant**, **workspace**, or **organization** level instead.

## Configuration

### 1. Update Your Config

In your `config/ai-engine.php` or `.env` file:

```php
// For Tenant-based credits
'credits' => [
    'owner_model' => 'App\\Models\\Tenant',
    'owner_id_column' => 'id', // or 'tenant_id' if using a different column
],
```

Or in `.env`:
```env
AI_CREDITS_OWNER_MODEL="App\\Models\\Tenant"
AI_CREDITS_OWNER_ID_COLUMN="id"
```

### 2. Ensure Your Model Has Credit Columns

Your Tenant/Workspace model must have these columns:

```php
Schema::table('tenants', function (Blueprint $table) {
    $table->decimal('my_credits', 10, 2)->default(100.0);
    $table->boolean('has_unlimited_credits')->default(false);
});
```

### 3. Usage Examples

#### For Tenant-Based Credits

```php
use LaravelAIEngine\Services\CreditManager;

$creditManager = app(CreditManager::class);

// Get tenant ID (from current context, session, etc.)
$tenantId = auth()->user()->tenant_id;

// Check if tenant has credits
$hasCredits = $creditManager->hasCredits($tenantId, $aiRequest);

// Deduct credits from tenant
$creditManager->deductCredits($tenantId, $aiRequest);

// Get tenant's credit balance
$credits = $creditManager->getUserCredits($tenantId);
// Returns: ['balance' => 100.0, 'is_unlimited' => false, 'currency' => 'MyCredits']

// Add credits to tenant
$creditManager->addCredits($tenantId, 50.0);

// Set unlimited credits for tenant
$creditManager->setUnlimitedCredits($tenantId, true);
```

#### For Workspace-Based Credits

```php
// In config/ai-engine.php
'credits' => [
    'owner_model' => 'App\\Models\\Workspace',
    'owner_id_column' => 'id',
],

// Usage
$workspaceId = auth()->user()->current_workspace_id;
$creditManager->hasCredits($workspaceId, $aiRequest);
$creditManager->deductCredits($workspaceId, $aiRequest);
```

#### For Organization-Based Credits

```php
// In config/ai-engine.php
'credits' => [
    'owner_model' => 'App\\Models\\Organization',
    'owner_id_column' => 'id',
],

// Usage
$orgId = auth()->user()->organization_id;
$creditManager->hasCredits($orgId, $aiRequest);
```

## Multi-Tenancy Integration

### With Stancl/Tenancy Package

```php
use Stancl\Tenancy\Facades\Tenancy;

// Get current tenant ID
$tenantId = Tenancy::getTenant()->id;

// Use with CreditManager
$creditManager->hasCredits($tenantId, $aiRequest);
$creditManager->deductCredits($tenantId, $aiRequest);
```

### With Spatie/Multitenancy Package

```php
use Spatie\Multitenancy\Models\Tenant;

$tenantId = Tenant::current()->id;
$creditManager->hasCredits($tenantId, $aiRequest);
```

## Custom ID Column

If your model uses a different ID column (e.g., `tenant_id` instead of `id`):

```php
'credits' => [
    'owner_model' => 'App\\Models\\Tenant',
    'owner_id_column' => 'tenant_id', // Custom column name
],
```

Then pass the tenant_id value:

```php
$tenantId = auth()->user()->tenant->tenant_id;
$creditManager->hasCredits($tenantId, $aiRequest);
```

## Migration Example

Create a migration to add credit columns to your tenant/workspace table:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->decimal('my_credits', 10, 2)->default(100.0)->after('id');
            $table->boolean('has_unlimited_credits')->default(false)->after('my_credits');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['my_credits', 'has_unlimited_credits']);
        });
    }
};
```

## Automatic Tenant Detection in ChatService

If you want the ChatService to automatically use the current tenant's credits:

```php
// In your controller or service
$tenantId = Tenancy::getTenant()->id; // or however you get tenant ID

$response = $chatService->sendMessage(
    message: 'Hello AI',
    userId: $tenantId, // Pass tenant ID instead of user ID
    sessionId: $sessionId,
    engine: EngineEnum::OPENAI,
    model: EntityEnum::GPT_4O_MINI
);
```

## Benefits of Tenant/Workspace Credits

✅ **Shared Pool**: All users in a tenant/workspace share the same credit pool  
✅ **Centralized Billing**: Bill at the organization level, not per user  
✅ **Simplified Management**: Admins manage one credit balance per tenant  
✅ **Fair Usage**: Prevent individual users from exhausting credits  
✅ **Enterprise Ready**: Better for B2B SaaS applications  

## Example: Tenant Model

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $fillable = [
        'name',
        'my_credits',
        'has_unlimited_credits',
    ];

    protected $casts = [
        'my_credits' => 'decimal:2',
        'has_unlimited_credits' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function hasCredits(float $amount): bool
    {
        if ($this->has_unlimited_credits) {
            return true;
        }

        return $this->my_credits >= $amount;
    }

    public function deductCredits(float $amount): void
    {
        if (!$this->has_unlimited_credits) {
            $this->decrement('my_credits', $amount);
        }
    }

    public function addCredits(float $amount): void
    {
        $this->increment('my_credits', $amount);
    }
}
```

## Switching Between User and Tenant Credits

You can dynamically switch based on your application logic:

```php
// In a service provider or middleware
if (config('app.multi_tenant_mode')) {
    config([
        'ai-engine.credits.owner_model' => 'App\\Models\\Tenant',
        'ai-engine.credits.owner_id_column' => 'id',
    ]);
} else {
    config([
        'ai-engine.credits.owner_model' => 'App\\Models\\User',
        'ai-engine.credits.owner_id_column' => 'id',
    ]);
}
```
