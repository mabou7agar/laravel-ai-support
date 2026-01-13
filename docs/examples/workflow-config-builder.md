# Workflow Config Builder

Declarative, fluent configuration for workflows - similar to the AI Config Builder pattern.

---

## Overview

The `WorkflowConfigBuilder` provides a clean, type-safe way to configure workflows using a fluent builder pattern, eliminating verbose array configurations.

---

## Basic Usage

### Old Way (Array Configuration)

```php
protected function config(): array
{
    return [
        'goal' => 'Create an invoice',
        'fields' => [
            'customer_identifier' => [
                'type' => 'string',
                'required' => true,
                'prompt' => 'What is the customer name?',
                'description' => 'Customer identifier',
            ],
            'products' => [
                'type' => 'array',
                'required' => true,
                'description' => 'Products to invoice',
            ],
        ],
        'entities' => [
            'customer' => [
                'identifier_field' => 'customer_identifier',
                'model' => Customer::class,
                'create_if_missing' => true,
                'subflow' => CreateCustomerWorkflow::class,
            ],
        ],
        'final_action' => fn($ctx) => $this->createInvoice($ctx),
    ];
}
```

### New Way (Builder Pattern)

```php
use LaravelAIEngine\Services\Agent\Traits\HasWorkflowConfig;

class CreateInvoiceWorkflow extends AgentWorkflow
{
    use AutomatesSteps, HasWorkflowConfig;
    
    protected function config(): array
    {
        return $this->workflowConfig()
            ->goal('Create an invoice')
            
            ->field('customer_identifier', 'Customer name, email, or phone | required')
            ->field('products', [
                'type' => 'array',
                'required' => true,
                'description' => 'Products to invoice',
            ])
            
            ->entityWithSubflow(
                name: 'customer',
                identifierField: 'customer_identifier',
                modelClass: Customer::class,
                subflowClass: CreateCustomerWorkflow::class
            )
            
            ->finalAction(fn($ctx) => $this->createInvoice($ctx))
            
            ->build();
    }
}
```

---

## Builder Methods

### Goal

```php
->goal('Create an invoice with customer and products')
```

### Fields

**Simple Field (String Format):**
```php
->field('name', 'Customer name | required | type:string')
->field('email', 'Email address | required | type:email')
->field('phone', 'Phone number | type:string')  // Optional
```

**Detailed Field (Array Format):**
```php
->field('products', [
    'type' => 'array',
    'required' => true,
    'prompt' => 'What products are on this invoice?',
    'description' => 'Array of products with name and quantity',
])
```

**Multiple Fields:**
```php
->fields([
    'name' => 'Customer name | required',
    'email' => 'Email address | required | type:email',
    'phone' => 'Phone number',
])
```

### Entities

**Simple Entity:**
```php
->entity('customer', Customer::class, [
    'identifier_field' => 'customer_email',
    'create_if_missing' => true,
])
```

**Entity with Identifier:**
```php
->entityWithIdentifier(
    name: 'customer',
    identifierField: 'customer_email',
    modelClass: Customer::class,
    options: ['create_if_missing' => true]
)
```

**Entity with Subworkflow:**
```php
->entityWithSubflow(
    name: 'customer',
    identifierField: 'customer_identifier',
    modelClass: Customer::class,
    subflowClass: CreateCustomerWorkflow::class
)
```

**Multiple Entities (Arrays):**
```php
->multipleEntities(
    name: 'products',
    identifierField: 'products',
    modelClass: ProductService::class,
    options: [
        'create_if_missing' => true,
        'subflow' => CreateProductWorkflow::class,
    ]
)
```

### Conversational Guidance

**Single Line:**
```php
->guidance('Ask for customer information first')
```

**Multiple Lines:**
```php
->guidance([
    'When user wants to create an invoice, guide them step-by-step:',
    '1. If customer info is missing, ask for name, email, or phone',
    '2. If products are missing, ask what to add',
    '3. Before creating, show summary and ask for confirmation',
])
```

### Final Action

```php
->finalAction(fn($ctx) => $this->createInvoice($ctx))
```

---

## Complete Example

```php
<?php

namespace App\AI\Workflows;

use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\Services\Agent\Traits\AutomatesSteps;
use LaravelAIEngine\Services\Agent\Traits\HasWorkflowConfig;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Order;

class CreateOrderWorkflow extends AgentWorkflow
{
    use AutomatesSteps, HasWorkflowConfig;
    
    protected function config(): array
    {
        return $this->workflowConfig()
            ->goal('Create a customer order with products')
            
            // Define fields
            ->field('customer_email', 'Customer email address | required | type:email')
            ->field('shipping_address', 'Shipping address | required')
            ->field('products', [
                'type' => 'array',
                'required' => true,
                'description' => 'Products to order',
            ])
            ->field('notes', 'Order notes')  // Optional
            
            // Define entities
            ->entityWithSubflow(
                name: 'customer',
                identifierField: 'customer_email',
                modelClass: Customer::class,
                subflowClass: CreateCustomerWorkflow::class
            )
            
            ->multipleEntities(
                name: 'products',
                identifierField: 'products',
                modelClass: Product::class,
                options: [
                    'create_if_missing' => true,
                    'subflow' => CreateProductWorkflow::class,
                ]
            )
            
            // Conversational guidance
            ->guidance([
                'Guide the user through order creation:',
                '1. Collect customer email',
                '2. Collect shipping address',
                '3. Collect products and quantities',
                '4. Show order summary',
                '5. Confirm before creating',
            ])
            
            // Final action
            ->finalAction(fn($ctx) => $this->createOrder($ctx))
            
            ->build();
    }
    
    protected function createOrder(UnifiedActionContext $context): ActionResult
    {
        $order = Order::create([
            'customer_id' => $context->getEntityState('customer', EntityState::RESOLVED),
            'shipping_address' => $context->get('shipping_address'),
            'notes' => $context->get('notes'),
        ]);
        
        // Add products
        $products = $context->getEntityState('products', EntityState::RESOLVED);
        foreach ($products as $product) {
            $order->items()->create([
                'product_id' => $product['id'],
                'quantity' => $product['quantity'],
                'price' => $product['price'],
            ]);
        }
        
        return ActionResult::success(
            message: "✅ Order #{$order->id} created successfully!",
            data: ['order_id' => $order->id]
        );
    }
}
```

---

## Field Format Parsing

The builder supports a simple string format for fields:

```php
'Field description | required | type:email | prompt:Custom prompt'
```

**Supported Options:**
- `required` - Makes field required
- `type:email` - Sets field type (string, email, array, etc.)
- `prompt:Custom prompt` - Custom prompt for this field

**Examples:**
```php
->field('name', 'Full name | required')
->field('email', 'Email address | required | type:email')
->field('age', 'Age in years | type:integer')
->field('bio', 'Biography | prompt:Tell me about yourself')
```

---

## Benefits

### 1. **Type Safety**
```php
// IDE autocomplete and type checking
$this->workflowConfig()
    ->goal('...')           // ✅ Autocomplete
    ->field('name', '...')  // ✅ Autocomplete
    ->build();              // ✅ Returns array
```

### 2. **Cleaner Code**
```php
// Before: 40+ lines of nested arrays
// After: 15 lines of fluent calls
```

### 3. **Better Readability**
```php
// Clear intent
->entityWithSubflow(
    name: 'customer',
    identifierField: 'customer_email',
    modelClass: Customer::class,
    subflowClass: CreateCustomerWorkflow::class
)

// vs nested array
'customer' => [
    'identifier_field' => 'customer_email',
    'model' => Customer::class,
    'create_if_missing' => true,
    'subflow' => CreateCustomerWorkflow::class,
]
```

### 4. **Named Parameters**
```php
// Self-documenting
->entityWithSubflow(
    name: 'customer',
    identifierField: 'customer_email',
    modelClass: Customer::class,
    subflowClass: CreateCustomerWorkflow::class
)
```

---

## Migration Guide

### Step 1: Add Trait

```php
use LaravelAIEngine\Services\Agent\Traits\HasWorkflowConfig;

class YourWorkflow extends AgentWorkflow
{
    use AutomatesSteps, HasWorkflowConfig;  // ✅ Add trait
}
```

### Step 2: Replace Array with Builder

**Before:**
```php
protected function config(): array
{
    return [
        'goal' => 'Create something',
        'fields' => [...],
        'entities' => [...],
    ];
}
```

**After:**
```php
protected function config(): array
{
    return $this->workflowConfig()
        ->goal('Create something')
        ->field(...)
        ->entity(...)
        ->build();
}
```

### Step 3: Convert Fields

**Before:**
```php
'fields' => [
    'name' => [
        'type' => 'string',
        'required' => true,
        'description' => 'Customer name',
    ],
]
```

**After:**
```php
->field('name', 'Customer name | required | type:string')
// or
->field('name', [
    'type' => 'string',
    'required' => true,
    'description' => 'Customer name',
])
```

### Step 4: Convert Entities

**Before:**
```php
'entities' => [
    'customer' => [
        'identifier_field' => 'customer_email',
        'model' => Customer::class,
        'create_if_missing' => true,
        'subflow' => CreateCustomerWorkflow::class,
    ],
]
```

**After:**
```php
->entityWithSubflow(
    name: 'customer',
    identifierField: 'customer_email',
    modelClass: Customer::class,
    subflowClass: CreateCustomerWorkflow::class
)
```

---

## Advanced Usage

### Conditional Configuration

```php
protected function config(): array
{
    $builder = $this->workflowConfig()
        ->goal('Create invoice')
        ->field('customer_email', 'Email | required | type:email');
    
    // Add optional fields based on conditions
    if ($this->requiresShipping()) {
        $builder->field('shipping_address', 'Shipping address | required');
    }
    
    return $builder->build();
}
```

### Reusable Configurations

```php
trait InvoiceWorkflowConfig
{
    protected function addInvoiceFields($builder)
    {
        return $builder
            ->field('customer_email', 'Email | required')
            ->field('products', ['type' => 'array', 'required' => true])
            ->entityWithSubflow(
                name: 'customer',
                identifierField: 'customer_email',
                modelClass: Customer::class,
                subflowClass: CreateCustomerWorkflow::class
            );
    }
}

class CreateInvoiceWorkflow extends AgentWorkflow
{
    use InvoiceWorkflowConfig;
    
    protected function config(): array
    {
        $builder = $this->workflowConfig()->goal('Create invoice');
        $builder = $this->addInvoiceFields($builder);
        
        return $builder
            ->finalAction(fn($ctx) => $this->createInvoice($ctx))
            ->build();
    }
}
```

---

## Best Practices

1. **Use simple string format for basic fields**
   ```php
   ->field('name', 'Full name | required')
   ```

2. **Use array format for complex fields**
   ```php
   ->field('products', [
       'type' => 'array',
       'required' => true,
       'validation' => 'min:1',
   ])
   ```

3. **Use named parameters for clarity**
   ```php
   ->entityWithSubflow(
       name: 'customer',
       identifierField: 'email',
       modelClass: Customer::class,
       subflowClass: CreateCustomerWorkflow::class
   )
   ```

4. **Group related configuration**
   ```php
   return $this->workflowConfig()
       // Goal
       ->goal('...')
       
       // Fields
       ->field('...')
       ->field('...')
       
       // Entities
       ->entity('...')
       ->entity('...')
       
       // Guidance
       ->guidance([...])
       
       // Final action
       ->finalAction(...)
       
       ->build();
   ```

---

**This pattern makes workflow configuration clean, maintainable, and type-safe!**
