# Agent Permission & Authorization System

## Overview

The Agent system needs robust permission checking to ensure users can only perform actions they're authorized for. This document outlines the complete permission architecture.

---

## Permission Layers

```
1. Model-Level Permissions
   ├─ Can user access this model?
   └─ Can user perform this action (create/update/delete)?

2. Workflow-Level Permissions
   ├─ Can user execute this workflow?
   └─ Can user access required resources?

3. Field-Level Permissions
   ├─ Can user view this field?
   └─ Can user modify this field?

4. Node-Level Permissions (Federated)
   ├─ Can user access remote node?
   └─ Can user perform cross-node operations?
```

---

## Implementation

### 1. Permission Guard Service

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Gate;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentPermissionGuard
{
    /**
     * Check if user can execute workflow
     */
    public function canExecuteWorkflow(
        $userId,
        string $workflowClass,
        UnifiedActionContext $context
    ): bool {
        // Check workflow-level permission
        $workflowName = class_basename($workflowClass);
        
        if (Gate::forUser($userId)->denies("execute-workflow:{$workflowName}")) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if user can perform action on model
     */
    public function canPerformAction(
        $userId,
        string $modelClass,
        string $action,
        ?int $modelId = null
    ): bool {
        // Check model-level permission
        $modelName = class_basename($modelClass);
        
        // Check general permission
        if (Gate::forUser($userId)->denies("{$action}-{$modelName}")) {
            return false;
        }
        
        // Check specific instance permission
        if ($modelId) {
            $model = $modelClass::find($modelId);
            if ($model && Gate::forUser($userId)->denies($action, $model)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check if user can access field
     */
    public function canAccessField(
        $userId,
        string $modelClass,
        string $fieldName,
        string $access = 'view'
    ): bool {
        $modelName = class_basename($modelClass);
        
        return Gate::forUser($userId)->allows(
            "{$access}-field:{$modelName}.{$fieldName}"
        );
    }
    
    /**
     * Check if user can access node
     */
    public function canAccessNode($userId, string $nodeSlug): bool
    {
        return Gate::forUser($userId)->allows("access-node:{$nodeSlug}");
    }
    
    /**
     * Get allowed actions for user on model
     */
    public function getAllowedActions($userId, string $modelClass): array
    {
        $modelName = class_basename($modelClass);
        $actions = ['create', 'read', 'update', 'delete'];
        
        return array_filter($actions, function($action) use ($userId, $modelName) {
            return Gate::forUser($userId)->allows("{$action}-{$modelName}");
        });
    }
    
    /**
     * Filter fields based on permissions
     */
    public function filterFields(
        $userId,
        string $modelClass,
        array $fields,
        string $access = 'view'
    ): array {
        return array_filter($fields, function($field) use ($userId, $modelClass, $access) {
            return $this->canAccessField($userId, $modelClass, $field, $access);
        });
    }
}
```

---

### 2. Permission Middleware for Workflows

```php
<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

trait HasPermissionChecks
{
    protected AgentPermissionGuard $permissionGuard;
    
    /**
     * Check permissions before workflow execution
     */
    protected function checkPermissions(UnifiedActionContext $context): ActionResult
    {
        $this->permissionGuard = app(AgentPermissionGuard::class);
        
        // Check workflow permission
        if (!$this->permissionGuard->canExecuteWorkflow(
            $context->userId,
            static::class,
            $context
        )) {
            return ActionResult::failure(
                error: 'You do not have permission to execute this workflow',
                metadata: ['permission' => 'workflow', 'workflow' => static::class]
            );
        }
        
        return ActionResult::success(message: 'Permissions verified');
    }
    
    /**
     * Check model action permission
     */
    protected function checkModelPermission(
        UnifiedActionContext $context,
        string $modelClass,
        string $action,
        ?int $modelId = null
    ): ActionResult {
        if (!$this->permissionGuard->canPerformAction(
            $context->userId,
            $modelClass,
            $action,
            $modelId
        )) {
            return ActionResult::failure(
                error: "You do not have permission to {$action} " . class_basename($modelClass),
                metadata: [
                    'permission' => 'model',
                    'model' => $modelClass,
                    'action' => $action,
                ]
            );
        }
        
        return ActionResult::success(message: 'Model permission verified');
    }
}
```

---

### 3. Enhanced AgentWorkflow with Permissions

```php
<?php

namespace LaravelAIEngine\Services\Agent;

abstract class AgentWorkflow
{
    use HasPermissionChecks;
    
    /**
     * Execute workflow with permission checks
     */
    public function execute(UnifiedActionContext $context): ActionResult
    {
        // Check permissions first
        $permissionCheck = $this->checkPermissions($context);
        if (!$permissionCheck->success) {
            return $permissionCheck;
        }
        
        // Continue with workflow execution
        return $this->executeSteps($context);
    }
    
    /**
     * Define required permissions for workflow
     */
    public function getRequiredPermissions(): array
    {
        return [
            'workflow' => static::class,
            'models' => [],
            'actions' => [],
        ];
    }
}
```

---

### 4. Permission-Aware CreateInvoiceWorkflow

```php
<?php

namespace App\AI\Workflows;

use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\DTOs\WorkflowStep;
use LaravelAIEngine\DTOs\ActionResult;

class CreateInvoiceWorkflow extends AgentWorkflow
{
    public function getRequiredPermissions(): array
    {
        return [
            'workflow' => 'CreateInvoiceWorkflow',
            'models' => [
                'Invoice' => ['create', 'read'],
                'Product' => ['read', 'create'],
                'Category' => ['read', 'create'],
                'Customer' => ['read'],
            ],
        ];
    }
    
    public function defineSteps(): array
    {
        return [
            WorkflowStep::make('check_permissions')
                ->execute(fn($ctx) => $this->checkAllPermissions($ctx))
                ->onSuccess('extract_invoice_data')
                ->onFailure('permission_denied'),
                
            WorkflowStep::make('extract_invoice_data')
                ->execute(fn($ctx) => $this->extractInvoiceData($ctx))
                ->onSuccess('validate_customer')
                ->onFailure('ask_for_invoice_details'),
                
            // ... rest of steps
        ];
    }
    
    protected function checkAllPermissions($context): ActionResult
    {
        // Check Invoice creation permission
        $result = $this->checkModelPermission(
            $context,
            \App\Models\Invoice::class,
            'create'
        );
        
        if (!$result->success) {
            return $result;
        }
        
        // Check Product access permission
        $result = $this->checkModelPermission(
            $context,
            \App\Models\Product::class,
            'read'
        );
        
        if (!$result->success) {
            return $result;
        }
        
        return ActionResult::success(message: 'All permissions verified');
    }
    
    protected function validateCustomer($context): ActionResult
    {
        $customerId = $context->get('customer_id');
        
        // Check if user can access this customer
        if (!$this->permissionGuard->canPerformAction(
            $context->userId,
            \App\Models\Customer::class,
            'read',
            $customerId
        )) {
            return ActionResult::failure(
                error: 'You do not have permission to access this customer'
            );
        }
        
        // Continue validation...
    }
}
```

---

### 5. Permission Gates Definition

```php
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    public function boot()
    {
        // Workflow permissions
        Gate::define('execute-workflow:CreateInvoiceWorkflow', function ($user) {
            return $user->hasPermission('create-invoices');
        });
        
        Gate::define('execute-workflow:CreateOrderWorkflow', function ($user) {
            return $user->hasPermission('create-orders');
        });
        
        // Model permissions
        Gate::define('create-Invoice', function ($user) {
            return $user->hasPermission('create-invoices');
        });
        
        Gate::define('read-Invoice', function ($user) {
            return $user->hasPermission('view-invoices');
        });
        
        Gate::define('update-Invoice', function ($user, $invoice) {
            return $user->hasPermission('edit-invoices') 
                && $user->id === $invoice->user_id;
        });
        
        Gate::define('delete-Invoice', function ($user, $invoice) {
            return $user->hasPermission('delete-invoices')
                && $user->id === $invoice->user_id;
        });
        
        // Product permissions
        Gate::define('create-Product', function ($user) {
            return $user->hasRole('admin') || $user->hasRole('manager');
        });
        
        Gate::define('read-Product', function ($user) {
            return true; // Everyone can view products
        });
        
        // Field-level permissions
        Gate::define('view-field:Invoice.total', function ($user) {
            return $user->hasPermission('view-invoice-totals');
        });
        
        Gate::define('edit-field:Invoice.total', function ($user) {
            return $user->hasRole('admin');
        });
        
        // Node permissions
        Gate::define('access-node:inventory', function ($user) {
            return $user->hasPermission('access-inventory-node');
        });
    }
}
```

---

### 6. Enhanced AgentOrchestrator with Permissions

```php
<?php

namespace LaravelAIEngine\Services\Agent;

class AgentOrchestrator
{
    protected AgentPermissionGuard $permissionGuard;
    
    public function __construct(
        protected ComplexityAnalyzer $complexityAnalyzer,
        protected ActionManager $actionManager,
        protected DataCollectorService $dataCollector,
        protected AgentMode $agentMode,
        protected ContextManager $contextManager,
        AgentPermissionGuard $permissionGuard
    ) {
        $this->permissionGuard = $permissionGuard;
    }
    
    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        
        // Analyze complexity
        $analysis = $this->complexityAnalyzer->analyze($message, $context);
        
        // Check permissions based on strategy
        if ($analysis['suggested_strategy'] === 'agent_mode') {
            $workflow = $this->findWorkflow($message, $context);
            
            if ($workflow) {
                // Check workflow permission
                if (!$this->permissionGuard->canExecuteWorkflow(
                    $userId,
                    $workflow,
                    $context
                )) {
                    return AgentResponse::failure(
                        message: 'You do not have permission to perform this action',
                        metadata: ['reason' => 'insufficient_permissions']
                    );
                }
            }
        }
        
        // Continue with execution...
    }
}
```

---

## Permission Patterns

### Pattern 1: Role-Based Access Control (RBAC)

```php
// User has roles
$user->hasRole('admin');
$user->hasRole('manager');
$user->hasRole('employee');

// Roles have permissions
Gate::define('create-Invoice', function ($user) {
    return $user->hasRole('admin') || $user->hasRole('manager');
});
```

### Pattern 2: Permission-Based Access Control

```php
// User has direct permissions
$user->hasPermission('create-invoices');
$user->hasPermission('view-invoices');
$user->hasPermission('edit-invoices');

Gate::define('create-Invoice', function ($user) {
    return $user->hasPermission('create-invoices');
});
```

### Pattern 3: Policy-Based Access Control

```php
// Invoice Policy
class InvoicePolicy
{
    public function create(User $user): bool
    {
        return $user->hasPermission('create-invoices');
    }
    
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->hasPermission('edit-invoices')
            && ($user->id === $invoice->user_id || $user->hasRole('admin'));
    }
}

// Register policy
Gate::policy(Invoice::class, InvoicePolicy::class);
```

### Pattern 4: Attribute-Based Access Control (ABAC)

```php
Gate::define('create-Invoice', function ($user, $context) {
    // Check multiple attributes
    return $user->hasPermission('create-invoices')
        && $user->department === 'sales'
        && $context->get('invoice_total') < $user->approval_limit;
});
```

---

## Integration with Existing Systems

### Laravel Permission Package (Spatie)

```php
use Spatie\Permission\Traits\HasRoles;

class User extends Model
{
    use HasRoles;
}

// In AuthServiceProvider
Gate::define('create-Invoice', function ($user) {
    return $user->hasPermissionTo('create invoices');
});

Gate::define('execute-workflow:CreateInvoiceWorkflow', function ($user) {
    return $user->hasPermissionTo('create invoices');
});
```

### Custom Permission System

```php
// Your existing permission check
class User extends Model
{
    public function hasPermission(string $permission): bool
    {
        return $this->permissions()
            ->where('name', $permission)
            ->exists();
    }
}

// Integrate with Agent
Gate::define('create-Invoice', function ($user) {
    return $user->hasPermission('create-invoices');
});
```

---

## Usage Examples

### Example 1: Basic Permission Check

```php
class CreateInvoiceWorkflow extends AgentWorkflow
{
    public function defineSteps(): array
    {
        return [
            WorkflowStep::make('check_permissions')
                ->execute(function($context) {
                    // Check if user can create invoices
                    $result = $this->checkModelPermission(
                        $context,
                        Invoice::class,
                        'create'
                    );
                    
                    if (!$result->success) {
                        return $result;
                    }
                    
                    return ActionResult::success(message: 'Permission granted');
                })
                ->onSuccess('extract_data')
                ->onFailure('permission_denied'),
        ];
    }
}
```

### Example 2: Field-Level Permissions

```php
protected function collectInvoiceData($context): ActionResult
{
    $fields = ['customer_name', 'total', 'tax', 'discount'];
    
    // Filter fields based on user permissions
    $allowedFields = $this->permissionGuard->filterFields(
        $context->userId,
        Invoice::class,
        $fields,
        'edit'
    );
    
    // Only collect data for allowed fields
    foreach ($allowedFields as $field) {
        // Collect field data...
    }
}
```

### Example 3: Dynamic Permission Checks

```php
protected function validateProduct($context): ActionResult
{
    $productId = $context->get('product_id');
    
    // Check if user can read this specific product
    if (!$this->permissionGuard->canPerformAction(
        $context->userId,
        Product::class,
        'read',
        $productId
    )) {
        return ActionResult::failure(
            error: 'You do not have access to this product'
        );
    }
    
    // Check if product needs to be created
    if (!$productId) {
        // Check if user can create products
        if (!$this->permissionGuard->canPerformAction(
            $context->userId,
            Product::class,
            'create'
        )) {
            return ActionResult::failure(
                error: 'You do not have permission to create products'
            );
        }
    }
    
    return ActionResult::success(message: 'Product access granted');
}
```

---

## Security Best Practices

### 1. Always Check Permissions Early
```php
// ✅ Good: Check at workflow start
public function defineSteps(): array
{
    return [
        WorkflowStep::make('check_permissions')->...,
        WorkflowStep::make('execute_action')->...,
    ];
}

// ❌ Bad: Check after action
public function defineSteps(): array
{
    return [
        WorkflowStep::make('execute_action')->...,
        WorkflowStep::make('check_permissions')->..., // Too late!
    ];
}
```

### 2. Check Permissions at Multiple Levels
```php
// Check workflow permission
$this->checkPermissions($context);

// Check model permission
$this->checkModelPermission($context, Invoice::class, 'create');

// Check instance permission
$this->checkModelPermission($context, Invoice::class, 'update', $invoiceId);

// Check field permission
$this->permissionGuard->canAccessField($userId, Invoice::class, 'total', 'edit');
```

### 3. Fail Securely
```php
// ✅ Good: Deny by default
if (!$this->permissionGuard->canPerformAction(...)) {
    return ActionResult::failure(error: 'Permission denied');
}

// ❌ Bad: Allow by default
if ($this->permissionGuard->canPerformAction(...)) {
    // execute
} // Implicitly allows if check fails
```

### 4. Log Permission Denials
```php
protected function checkPermissions($context): ActionResult
{
    if (!$this->permissionGuard->canExecuteWorkflow(...)) {
        Log::warning('Permission denied', [
            'user_id' => $context->userId,
            'workflow' => static::class,
            'session' => $context->sessionId,
        ]);
        
        return ActionResult::failure(error: 'Permission denied');
    }
}
```

---

## Testing Permissions

```php
// Test permission checks
public function test_user_cannot_create_invoice_without_permission()
{
    $user = User::factory()->create(); // No permissions
    
    $workflow = app(CreateInvoiceWorkflow::class);
    $context = new UnifiedActionContext('test', $user->id);
    
    $result = $workflow->execute($context);
    
    $this->assertFalse($result->success);
    $this->assertStringContains('permission', $result->error);
}

public function test_user_can_create_invoice_with_permission()
{
    $user = User::factory()->create();
    $user->givePermissionTo('create invoices');
    
    $workflow = app(CreateInvoiceWorkflow::class);
    $context = new UnifiedActionContext('test', $user->id);
    
    $result = $workflow->execute($context);
    
    $this->assertTrue($result->success);
}
```

---

## Summary

**Permission System Provides:**
- ✅ Workflow-level authorization
- ✅ Model-level authorization
- ✅ Field-level authorization
- ✅ Instance-level authorization
- ✅ Node-level authorization (federated)
- ✅ Integration with Laravel Gates
- ✅ Integration with existing permission systems
- ✅ Secure by default
- ✅ Easy to test

**Your agent workflows are now secure!** 🔒
