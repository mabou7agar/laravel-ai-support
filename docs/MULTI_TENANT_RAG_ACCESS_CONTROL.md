# Multi-Tenant RAG Access Control

## Overview

The Laravel AI Engine now includes a comprehensive **Role-Based Access Control (RBAC)** system for RAG (Retrieval-Augmented Generation) searches. This ensures proper data isolation in multi-tenant applications while allowing flexible access patterns for different user types.

## Access Levels

### 1. **Admin/Super User** - Access ALL Data
Admins can search across ALL vectorized data regardless of ownership.

**Use Cases:**
- Super administrators
- Support staff troubleshooting
- Data analysts
- System moderators

**Configuration:**
```php
// User model with roles
if ($user->hasRole(['super-admin', 'admin', 'support'])) {
    // Access all data
}

// Or simple flag
if ($user->is_admin || $user->is_super_admin) {
    // Access all data
}
```

### 2. **Tenant-Scoped** - Access Tenant/Organization Data
Users can search data within their tenant/organization.

**Use Cases:**
- Team members in a workspace
- Employees in a company
- Members of an organization

**Configuration:**
```php
// Enable in config
'enable_tenant_scope' => true,

// Tenant fields (checked in order)
'tenant_fields' => [
    'tenant_id',
    'organization_id',
    'company_id',
    'team_id',
],
```

### 3. **User-Scoped** - Access Only Own Data
Regular users can only search their own data.

**Use Cases:**
- Individual users
- Personal accounts
- Private data

**Configuration:**
```php
// Automatically applied when:
// - User is not admin
// - No tenant_id found
// - Filters by user_id
```

## Implementation

### 1. Model Setup

Add tenant and user fields to your vectorizable models:

```php
use LaravelAIEngine\Traits\Vectorizable;

class Email extends Model
{
    use Vectorizable;

    protected $fillable = [
        'user_id',        // Owner
        'tenant_id',      // Organization
        'subject',
        'body',
        'is_public',      // Public visibility
    ];

    protected $vectorizable = ['subject', 'body'];
}
```

### 2. Metadata Storage

The `Vectorizable` trait automatically includes access control metadata:

```php
public function getVectorMetadata(): array
{
    return [
        'user_id' => $this->user_id,           // User ownership
        'tenant_id' => $this->tenant_id,       // Tenant scope
        'is_public' => $this->is_public,       // Public access
        'visibility' => $this->visibility,      // Visibility level
    ];
}
```

### 3. User Model Setup

Ensure your User model has the necessary fields/methods:

```php
class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'tenant_id',        // For tenant-scoped access
        'is_admin',         // For admin access
    ];

    // Optional: Using Spatie Laravel Permission
    public function hasRole($roles): bool
    {
        return $this->roles()->whereIn('name', (array) $roles)->exists();
    }
}
```

### 4. Usage in Controllers

Pass the authenticated user object (not just ID):

```php
use LaravelAIEngine\Services\ChatService;

class ChatController extends Controller
{
    public function sendMessage(Request $request, ChatService $chatService)
    {
        $user = $request->user(); // Get authenticated user object
        
        $response = $chatService->processMessage(
            message: $request->input('message'),
            sessionId: $request->input('session_id'),
            engine: 'openai',
            model: 'gpt-4o',
            useIntelligentRAG: true,
            ragCollections: [Email::class, Document::class],
            user: $user  // ✅ Pass user object, not just ID
        );

        return response()->json($response);
    }
}
```

## Configuration

### Environment Variables

```env
# Allow anonymous searches (not recommended for production)
AI_ENGINE_ALLOW_ANONYMOUS_SEARCH=false

# Enable tenant-scoped access
AI_ENGINE_ENABLE_TENANT_SCOPE=true

# Allow public data access
AI_ENGINE_ALLOW_PUBLIC_DATA=true

# Log access levels for debugging
AI_ENGINE_LOG_ACCESS_LEVEL=true

# Strict mode (require authentication)
AI_ENGINE_STRICT_MODE=true
```

### Config File

Publish the configuration:

```bash
php artisan vendor:publish --tag=ai-engine-config
```

Edit `config/vector-access-control.php`:

```php
return [
    'admin_roles' => [
        'super-admin',
        'admin',
        'support',
    ],
    
    'tenant_fields' => [
        'tenant_id',
        'organization_id',
        'company_id',
    ],
    
    'enable_tenant_scope' => true,
];
```

## Examples

### Example 1: Regular User

```php
// User: John (ID: 123, Tenant: ABC Corp)
$user = User::find(123);

// Search only returns John's emails
$response = $chatService->processMessage(
    message: "show me my emails",
    sessionId: "session-123",
    ragCollections: [Email::class],
    user: $user
);

// Vector search filters:
// ['user_id' => '123']
```

### Example 2: Tenant User

```php
// User: Jane (ID: 456, Tenant: ABC Corp, Role: Manager)
$user = User::find(456);

// Search returns ALL emails in ABC Corp
$response = $chatService->processMessage(
    message: "show me team emails",
    sessionId: "session-456",
    ragCollections: [Email::class],
    user: $user
);

// Vector search filters:
// ['tenant_id' => 'ABC Corp']
```

### Example 3: Admin User

```php
// User: Admin (ID: 1, Role: super-admin)
$user = User::find(1);

// Search returns ALL emails across ALL tenants
$response = $chatService->processMessage(
    message: "show me all emails",
    sessionId: "session-1",
    ragCollections: [Email::class],
    user: $user
);

// Vector search filters:
// [] (no filters - access all data)
```

### Example 4: Public Data

```php
// Model with public visibility
$email = Email::create([
    'user_id' => 123,
    'tenant_id' => 'ABC Corp',
    'subject' => 'Public Announcement',
    'body' => 'This is public',
    'is_public' => true,  // ✅ Public data
]);

// Any user can find this email
```

## Security Best Practices

### 1. Always Pass User Object

```php
// ❌ WRONG - Passing user ID as string
$response = $chatService->processMessage(
    message: $message,
    sessionId: $sessionId,
    user: '123'  // String ID - basic filtering only
);

// ✅ CORRECT - Passing user object
$response = $chatService->processMessage(
    message: $message,
    sessionId: $sessionId,
    user: $request->user()  // User object - full access control
);
```

### 2. Validate User Authentication

```php
public function sendMessage(Request $request)
{
    // Ensure user is authenticated
    if (!$request->user()) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }

    $response = $chatService->processMessage(
        message: $request->input('message'),
        sessionId: $request->input('session_id'),
        user: $request->user()
    );
}
```

### 3. Use Middleware

```php
Route::post('/chat', [ChatController::class, 'sendMessage'])
    ->middleware(['auth:sanctum']);  // Require authentication
```

### 4. Audit Logging

Enable access level logging:

```php
// In config/vector-access-control.php
'log_access_level' => true,

// Logs will show:
// "Vector search with access control" {
//   "user_id": "123",
//   "access_level": "user",  // or "tenant" or "admin"
//   "model": "App\\Models\\Email",
//   "filters": {"user_id": "123"}
// }
```

## Testing

### Test User-Scoped Access

```php
public function test_user_can_only_see_own_data()
{
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    // Create emails for both users
    Email::factory()->create(['user_id' => $user1->id, 'subject' => 'User 1 Email']);
    Email::factory()->create(['user_id' => $user2->id, 'subject' => 'User 2 Email']);

    // Index emails
    Artisan::call('ai-engine:index', ['model' => Email::class]);

    // User 1 searches
    $response = $this->chatService->processMessage(
        message: "show me emails",
        sessionId: "test-session",
        ragCollections: [Email::class],
        user: $user1
    );

    // Should only find User 1's email
    $this->assertStringContains('User 1 Email', $response->getContent());
    $this->assertStringNotContains('User 2 Email', $response->getContent());
}
```

### Test Admin Access

```php
public function test_admin_can_see_all_data()
{
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    Email::factory()->create(['user_id' => $user->id, 'subject' => 'User Email']);

    $response = $this->chatService->processMessage(
        message: "show me all emails",
        sessionId: "test-session",
        ragCollections: [Email::class],
        user: $admin
    );

    // Admin should see all emails
    $this->assertStringContains('User Email', $response->getContent());
}
```

## Troubleshooting

### Issue: Users seeing other users' data

**Solution:** Ensure you're passing the user object, not just ID:

```php
// Check your controller
$response = $chatService->processMessage(
    user: $request->user()  // ✅ User object
);
```

### Issue: Admin not seeing all data

**Solution:** Check admin role configuration:

```php
// In User model
public function hasRole($roles): bool
{
    return $this->roles()->whereIn('name', (array) $roles)->exists();
}

// Or use simple flag
$user->is_admin = true;
$user->save();
```

### Issue: Tenant users not seeing team data

**Solution:** Enable tenant scope and ensure tenant_id is set:

```php
// config/vector-access-control.php
'enable_tenant_scope' => true,

// User model
$user->tenant_id = 'ABC Corp';

// Model
$email->tenant_id = 'ABC Corp';
```

## Migration Guide

### From Old System (userId string)

```php
// OLD CODE
$response = $chatService->processMessage(
    message: $message,
    sessionId: $sessionId,
    user: $userId  // String
);

// NEW CODE (Backward compatible)
$response = $chatService->processMessage(
    message: $message,
    sessionId: $sessionId,
    user: $request->user()  // User object
);
```

The system is **backward compatible** - passing a string userId will still work with basic user_id filtering, but won't support tenant-scoped or admin access.

## Summary

✅ **Admin users** - See ALL data across all tenants
✅ **Tenant users** - See data within their organization
✅ **Regular users** - See only their own data
✅ **Public data** - Accessible based on visibility settings
✅ **Backward compatible** - Works with existing code
✅ **Secure by default** - Requires authentication
✅ **Flexible** - Configurable access patterns
✅ **Auditable** - Logs access levels

This system ensures your RAG searches are secure, compliant, and properly isolated in multi-tenant applications.
