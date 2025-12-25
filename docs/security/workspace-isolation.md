# Workspace Isolation

This guide covers how to use workspace-based data isolation in Laravel AI Engine's RAG system.

## Overview

Workspace isolation allows users within the same tenant/organization to have data scoped to their specific workspace. This is useful for:

- **Project-based access**: Users only see data from their assigned projects
- **Team workspaces**: Teams have isolated data within the same organization
- **Client workspaces**: Agencies can isolate client data

## Access Level Hierarchy

```
┌─────────────────────────────────────────┐
│  LEVEL 1: ADMIN/SUPER USER              │
│  ✓ Access ALL data                      │
│  ✓ No filtering applied                 │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 2: TENANT-SCOPED USER            │
│  ✓ Access data within organization      │
│  ✓ Filtered by: tenant_id               │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 2.5: WORKSPACE-SCOPED USER       │
│  ✓ Access data within workspace         │
│  ✓ Filtered by: workspace_id            │
└─────────────────────────────────────────┘
              ↓
┌─────────────────────────────────────────┐
│  LEVEL 3: REGULAR USER                  │
│  ✓ Access only own data                 │
│  ✓ Filtered by: user_id                 │
└─────────────────────────────────────────┘
```

## Configuration

### Enable Workspace Scope

```bash
# .env
AI_ENGINE_ENABLE_WORKSPACE_SCOPE=true
```

### Configure Workspace Fields

```php
// config/vector-access-control.php

return [
    'enable_workspace_scope' => env('AI_ENGINE_ENABLE_WORKSPACE_SCOPE', true),
    
    'workspace_fields' => [
        'workspace_id',
        'current_workspace_id',
    ],
];
```

## User Model Setup

### Option 1: Direct workspace_id Field

```php
class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'workspace_id',  // Current workspace
    ];
}
```

### Option 2: Workspace Relationship

```php
class User extends Authenticatable
{
    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
    
    public function currentWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }
}
```

### Option 3: Session-Based Workspace

```php
// In middleware
session(['current_workspace_id' => $workspace->id]);
```

## Model Setup

Add `workspace_id` to your vectorizable models:

```php
use LaravelAIEngine\Traits\Vectorizable;

class Document extends Model
{
    use Vectorizable;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'title',
        'content',
    ];

    protected $vectorizable = ['title', 'content'];
}
```

## How It Works

### Automatic Metadata Indexing

When you index a model, workspace information is automatically included:

```php
$document = Document::create([
    'user_id' => 1,
    'workspace_id' => 5,
    'title' => 'Project Plan',
    'content' => 'Q1 objectives...',
]);

$document->index();

// Vector metadata includes:
// {
//     'user_id' => 1,
//     'workspace_id' => 5,
//     'title' => 'Project Plan',
//     ...
// }
```

### Automatic Search Filtering

When a user with a workspace searches, results are automatically filtered:

```php
use LaravelAIEngine\Services\ChatService;

// User with workspace_id = 5
$response = $chatService->processMessage(
    message: 'Show me project documents',
    sessionId: 'user-session',
    ragCollections: [Document::class],
    userId: auth()->id()  // User has workspace_id = 5
);

// Only returns documents where workspace_id = 5
```

## Workspace Resolution

The system checks for workspace in this order:

1. **Direct field**: `$user->workspace_id`
2. **Current workspace field**: `$user->current_workspace_id`
3. **Workspace relationship**: `$user->workspace->id`
4. **Current workspace relationship**: `$user->currentWorkspace->id`
5. **Session**: `session('current_workspace_id')`

## Usage Examples

### Basic Workspace Isolation

```php
// User in workspace 5
$user = User::find(1);
$user->workspace_id = 5;

// Search only returns workspace 5 data
$response = $chatService->processMessage(
    message: 'Find all invoices',
    ragCollections: [Invoice::class],
    userId: $user->id
);
```

### Switching Workspaces

```php
// User can be in multiple workspaces
$user->current_workspace_id = 10;
$user->save();

// Now searches return workspace 10 data
$response = $chatService->processMessage(
    message: 'Find all invoices',
    ragCollections: [Invoice::class],
    userId: $user->id
);
```

### Session-Based Workspace Switching

```php
// In your controller
public function switchWorkspace(Request $request, Workspace $workspace)
{
    // Verify user has access to workspace
    abort_unless($request->user()->workspaces->contains($workspace), 403);
    
    // Set current workspace in session
    session(['current_workspace_id' => $workspace->id]);
    
    return redirect()->back();
}
```

## Access Control Methods

### Check Workspace Access

```php
use LaravelAIEngine\Services\Vector\VectorAccessControl;

$accessControl = app(VectorAccessControl::class);

// Get user's workspace
$workspaceId = $accessControl->getUserWorkspaceId($user);

// Check access level
$level = $accessControl->getAccessLevel($user);
// Returns: 'admin', 'tenant', 'workspace', or 'user'

// Check if user can access a model
$canAccess = $accessControl->canAccessModel($document, $user);
```

### Build Search Filters

```php
$filters = $accessControl->buildSearchFilters($userId);

// Returns:
// ['workspace_id' => 5]  // For workspace-scoped user
// ['user_id' => 1]       // For regular user
// []                     // For admin
```

## Combining with Tenant Scope

Workspace scope works alongside tenant scope:

```php
class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'tenant_id',      // Organization
        'workspace_id',   // Project/Team within org
    ];
}
```

The access control checks in order:
1. Is user admin? → No filter
2. Has tenant_id? → Filter by tenant
3. Has workspace_id? → Filter by workspace
4. Default → Filter by user_id

## Best Practices

### 1. Always Set Workspace on Creation

```php
class Document extends Model
{
    protected static function booted()
    {
        static::creating(function ($document) {
            if (!$document->workspace_id && auth()->check()) {
                $document->workspace_id = auth()->user()->workspace_id;
            }
        });
    }
}
```

### 2. Validate Workspace Access

```php
class DocumentController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'workspace_id' => 'required|exists:workspaces,id',
            'title' => 'required|string',
        ]);
        
        // Verify user has access to workspace
        abort_unless(
            $request->user()->workspaces->contains($validated['workspace_id']),
            403,
            'You do not have access to this workspace'
        );
        
        return Document::create($validated);
    }
}
```

### 3. Re-index After Workspace Changes

```php
// When moving document to different workspace
$document->workspace_id = $newWorkspaceId;
$document->save();
$document->reindex(); // Update vector with new workspace_id
```

## Troubleshooting

### Workspace Not Detected

```php
use LaravelAIEngine\Services\Vector\VectorAccessControl;

$accessControl = app(VectorAccessControl::class);
$user = auth()->user();

dd([
    'access_level' => $accessControl->getAccessLevel($user),
    'workspace_id' => $accessControl->getUserWorkspaceId($user),
    'user_workspace_id' => $user->workspace_id ?? null,
    'session_workspace' => session('current_workspace_id'),
]);
```

### User Seeing Wrong Data

Check the search filters being applied:

```php
$filters = $accessControl->buildSearchFilters(auth()->id());
dd($filters);

// Should show:
// ['workspace_id' => 5] for workspace-scoped user
```

### Workspace Not in Vector Metadata

Verify the model has workspace_id:

```php
$document = Document::find(1);
dd($document->getVectorMetadata());

// Should include:
// ['workspace_id' => 5, ...]
```

## Configuration Reference

```php
// config/vector-access-control.php

return [
    // Enable workspace-scoped access
    'enable_workspace_scope' => env('AI_ENGINE_ENABLE_WORKSPACE_SCOPE', true),

    // Workspace field names to check (in order)
    'workspace_fields' => [
        'workspace_id',
        'current_workspace_id',
    ],
];
```

## See Also

- [Multi-Tenant RAG Access Control](MULTI_TENANT_RAG_ACCESS_CONTROL.md)
- [Multi-Database Tenancy](MULTI_DATABASE_TENANCY.md)
- [Simplified Access Control](SIMPLIFIED_ACCESS_CONTROL.md)
