# 🔄 Workflow System

Create complex multi-step conversational workflows with automatic state management, subworkflow support, and seamless ChatService integration.

---

## Overview

The Workflow System enables you to build sophisticated conversational flows that guide users through multi-step processes. Workflows can collect data progressively, resolve entities automatically, delegate tasks to subworkflows, and persist state across multiple chat messages.

### Key Features

- **Multi-Step Flows**: Define complex conversational processes with multiple steps
- **Declarative & Manual**: Choose auto-generated steps or explicit control
- **Subworkflow Support**: Delegate tasks to child workflows with automatic context management
- **Context Persistence**: Workflow state automatically saved and restored across messages
- **ChatService Integration**: Workflows detected and continued automatically through chat
- **Entity Resolution**: Automatic search and creation of related entities (customers, products, etc.)
- **Session Isolation**: Each session has independent workflow context
- **Error Handling**: Graceful failure with automatic context cleanup

---

## Quick Start

### 1. Create a Declarative Workflow

```php
<?php

namespace App\AI\Workflows;

use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\Services\Agent\Traits\AutomatesSteps;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;

class CreateInvoiceWorkflow extends AgentWorkflow
{
    use AutomatesSteps;
    
    protected function config(): array
    {
        return [
            'goal' => 'Create an invoice with customer and products',
            
            // Fields to collect
            'fields' => [
                'customer_name' => [
                    'type' => 'string',
                    'description' => 'Customer name',
                    'required' => true,
                ],
                'customer_email' => [
                    'type' => 'email',
                    'description' => 'Customer email address',
                    'required' => true,
                ],
                'products' => [
                    'type' => 'array',
                    'description' => 'List of products to invoice',
                    'required' => true,
                ],
            ],
            
            // Entities to resolve
            'entities' => [
                'customer' => [
                    'model' => Customer::class,
                    'search_field' => 'email',
                    'create_if_missing' => true,
                    'subflow' => CreateCustomerWorkflow::class,
                ],
                'products' => [
                    'model' => Product::class,
                    'search_field' => 'name',
                    'create_if_missing' => true,
                    'multiple' => true,
                ],
            ],
            
            // Final action to execute
            'final_action' => 'createInvoice',
        ];
    }
    
    protected function createInvoice(UnifiedActionContext $context): ActionResult
    {
        $invoice = Invoice::create([
            'customer_id' => $context->get('customer_id'),
            'items' => $context->get('valid_products'),
            'status' => 'Draft',
        ]);
        
        return ActionResult::success(
            message: "✅ Invoice #{$invoice->invoice_id} created successfully!",
            data: ['invoice_id' => $invoice->id]
        );
    }
}
```

### 2. Start the Workflow

```php
use LaravelAIEngine\Services\Agent\AgentMode;
use LaravelAIEngine\DTOs\UnifiedActionContext;

$agentMode = app(AgentMode::class);
$context = new UnifiedActionContext('session-123', auth()->id());

// Start workflow with initial message
$response = $agentMode->startWorkflow(
    CreateInvoiceWorkflow::class,
    $context,
    'Create invoice for John Smith with 2 laptops'
);

echo $response->message;
// "What is the customer's email address?"
```

### 3. Continue the Workflow

```php
// User provides email
$response = $agentMode->continueWorkflow('john@example.com', $context);

// User confirms product creation
$response = $agentMode->continueWorkflow('yes', $context);

// Workflow completes
echo $response->message;
// "✅ Invoice #INVO001234 created successfully!"
```

---

## Workflow Types

### Declarative Workflows (Auto-Generated Steps)

Use the `AutomatesSteps` trait to automatically generate workflow steps from your configuration.

**Benefits:**
- Less code to write
- Automatic step generation
- Built-in entity resolution
- Subworkflow support

**Example:**

```php
class CreateCustomerWorkflow extends AgentWorkflow
{
    use AutomatesSteps;
    
    protected function config(): array
    {
        return [
            'goal' => 'Create a new customer',
            'fields' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Customer full name',
                    'required' => true,
                ],
                'email' => [
                    'type' => 'email',
                    'description' => 'Email address',
                    'required' => true,
                ],
                'phone' => [
                    'type' => 'string',
                    'description' => 'Phone number',
                    'required' => false,
                ],
            ],
            'final_action' => 'createCustomer',
        ];
    }
    
    protected function createCustomer(UnifiedActionContext $context): ActionResult
    {
        $data = $context->get('collected_data');
        
        $customer = Customer::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);
        
        return ActionResult::success(
            message: "Customer created: {$customer->name}",
            data: ['customer_id' => $customer->id]
        );
    }
}
```

### Manual Workflows (Explicit Steps)

Define steps explicitly for full control over the workflow logic.

**Benefits:**
- Complete control over flow
- Custom step logic
- Complex branching
- Custom validation

**Example:**

```php
use LaravelAIEngine\DTOs\WorkflowStep;

class CreateProductWorkflow extends AgentWorkflow
{
    protected function defineSteps(): array
    {
        return [
            WorkflowStep::make('collect_name')
                ->execute(fn($ctx) => $this->collectName($ctx))
                ->requiresUserInput()
                ->onSuccess('collect_price')
                ->onFailure('error'),
                
            WorkflowStep::make('collect_price')
                ->execute(fn($ctx) => $this->collectPrice($ctx))
                ->requiresUserInput()
                ->onSuccess('create_product')
                ->onFailure('error'),
                
            WorkflowStep::make('create_product')
                ->execute(fn($ctx) => $this->createProduct($ctx))
                ->onSuccess('complete')
                ->onFailure('error'),
        ];
    }
    
    protected function collectName(UnifiedActionContext $context): ActionResult
    {
        $message = $context->conversationHistory[count($context->conversationHistory) - 1]['content'] ?? '';
        
        if (empty($message)) {
            return ActionResult::needsUserInput(
                message: 'What is the product name?'
            );
        }
        
        $context->set('product_name', $message);
        
        return ActionResult::success(
            message: "Product name: {$message}"
        );
    }
    
    protected function collectPrice(UnifiedActionContext $context): ActionResult
    {
        $message = $context->conversationHistory[count($context->conversationHistory) - 1]['content'] ?? '';
        
        if (empty($message)) {
            return ActionResult::needsUserInput(
                message: 'What is the sale price?'
            );
        }
        
        // Parse price
        preg_match('/\d+(\.\d{1,2})?/', $message, $matches);
        $price = $matches[0] ?? null;
        
        if (!$price) {
            return ActionResult::needsUserInput(
                message: 'Please provide a valid price (e.g., 99.99)'
            );
        }
        
        $context->set('sale_price', $price);
        
        return ActionResult::success(
            message: "Price set to: ${price}"
        );
    }
    
    protected function createProduct(UnifiedActionContext $context): ActionResult
    {
        $product = Product::create([
            'name' => $context->get('product_name'),
            'sale_price' => $context->get('sale_price'),
        ]);
        
        return ActionResult::success(
            message: "✅ Product '{$product->name}' created!",
            data: ['product_id' => $product->id]
        );
    }
}
```

---

## Entity Resolution

Workflows can automatically search for and create related entities.

### Configuration

```php
'entities' => [
    'customer' => [
        'model' => Customer::class,
        'search_field' => 'email',           // Field to search by
        'create_if_missing' => true,         // Create if not found
        'subflow' => CreateCustomerWorkflow::class, // Use subworkflow for creation
    ],
    'products' => [
        'model' => Product::class,
        'search_field' => 'name',
        'create_if_missing' => true,
        'multiple' => true,                  // Allow multiple entities
    ],
]
```

### Custom Resolution Methods

Override default resolution with custom logic:

```php
protected function resolveEntity_customer(UnifiedActionContext $context): ActionResult
{
    $email = $context->get('collected_data')['customer_email'] ?? null;
    
    if (!$email) {
        return ActionResult::failure(error: 'Email required');
    }
    
    // Custom search logic
    $customer = Customer::where('email', $email)
        ->where('workspace', getActiveWorkSpace())
        ->first();
    
    if ($customer) {
        $context->set('customer_id', $customer->customer_id);
        return ActionResult::success(
            message: "Found customer: {$customer->name}"
        );
    }
    
    // Not found - will trigger subworkflow if configured
    return ActionResult::failure(
        error: "Customer not found",
        metadata: ['entity' => 'customer']
    );
}
```

---

## Subworkflows

Delegate complex tasks to child workflows with automatic context management.

### How It Works

```
Parent Workflow: CreateInvoiceWorkflow
    ↓
    Needs customer (not found)
    ↓
    Push parent to stack
    ↓
Child Workflow: CreateCustomerWorkflow
    ↓
    Collect: name, email, phone
    ↓
    Create customer
    ↓
    Pop parent from stack
    ↓
Parent Workflow: CreateInvoiceWorkflow (continues)
    ↓
    Use customer_id from subworkflow
    ↓
    Create invoice
```

### Configuration

```php
'entities' => [
    'customer' => [
        'model' => Customer::class,
        'create_if_missing' => true,
        'subflow' => CreateCustomerWorkflow::class, // ✅ Subworkflow
    ],
]
```

### Workflow Stack

The system manages a workflow stack automatically:

```php
// Stack operations (handled automatically)
$context->pushWorkflow(CreateCustomerWorkflow::class, null, $state);
$context->popWorkflow(); // Returns parent workflow info

// Check if in subworkflow
if ($context->isInSubworkflow()) {
    $parent = $context->getParentWorkflow();
    // ['workflow' => 'CreateInvoiceWorkflow', 'step' => 'resolve_customer', ...]
}
```

---

## ChatService Integration

Workflows automatically integrate with ChatService - no additional code needed.

### How It Works

```php
use LaravelAIEngine\Services\ChatService;

$chat = app(ChatService::class);

// Turn 1: User starts workflow
$response = $chat->processMessage(
    message: 'Create invoice for Sarah with 2 Pumps',
    sessionId: 'user-123',
    userId: auth()->id()
);

// ChatService automatically:
// ✅ Detects workflow intent
// ✅ Starts CreateInvoiceWorkflow
// ✅ Saves context to cache
// ✅ Returns workflow response

echo $response->content;
// "📋 Invoice Summary... Would you like to create this invoice?"

// Turn 2: User confirms
$response = $chat->processMessage(
    message: 'yes',
    sessionId: 'user-123',
    userId: auth()->id()
);

// ChatService automatically:
// ✅ Loads workflow context from cache
// ✅ Continues CreateInvoiceWorkflow
// ✅ Creates invoice
// ✅ Clears context on completion

echo $response->content;
// "✅ Invoice #INVO001234 created successfully!"
```

### Context Persistence

Workflow state is automatically cached:

```php
// Saved to cache after each step
Cache::put("agent_context:{$sessionId}", [
    'current_workflow' => 'App\AI\Workflows\CreateInvoiceWorkflow',
    'current_step' => 'collect_customer_data',
    'workflow_state' => [...],
    'conversation_history' => [...],
    'workflow_stack' => [...],
], now()->addHours(24));
```

### Session Isolation

Each session has independent workflow context:

```php
// Session A
$responseA = $chat->processMessage(
    message: 'Create invoice for Alice',
    sessionId: 'session-a',
    userId: 1
);
// Workflow: CreateInvoiceWorkflow (session-a)

// Session B (different user/session)
$responseB = $chat->processMessage(
    message: 'Create product MacBook',
    sessionId: 'session-b',
    userId: 2
);
// Workflow: CreateProductWorkflow (session-b)

// ✅ No cross-contamination
```

---

## Real-World Example

### Complete Invoice Creation Flow

```
User: "Create invoice for John Smith with 3 laptops"
  ↓
Bot: "Customer 'John Smith' doesn't exist. Would you like to create it?"
  ↓
User: "yes"
  ↓
[Subworkflow: CreateCustomerWorkflow starts]
  ↓
Bot: "What is the customer's email?"
  ↓
User: "john@example.com"
  ↓
Bot: "What is the customer's phone? (Optional - type 'skip')"
  ↓
User: "skip"
  ↓
Bot: "What is the billing address? (Optional - type 'skip')"
  ↓
User: "skip"
  ↓
[Customer created, subworkflow completes]
[Returns to parent workflow with customer_id]
  ↓
Bot: "Product 'laptops' doesn't exist. Would you like to create it?"
  ↓
User: "yes"
  ↓
Bot: "Please provide pricing: Format: 'sale price X, purchase price Y'"
  ↓
User: "sale price 999, purchase price 500"
  ↓
[Product created]
  ↓
Bot: "📋 Invoice Summary:
     Customer: John Smith (john@example.com)
     Products: laptops x3 @ $999 = $2,997
     Total: $2,997
     
     Would you like to create this invoice?"
  ↓
User: "yes"
  ↓
Bot: "✅ Invoice #INVO001234 created successfully!"
```

---

## Advanced Features

### Conversational Guidance

Guide users through the workflow with helpful prompts:

```php
protected function config(): array
{
    return [
        'goal' => 'Create an invoice',
        'conversational_guidance' => [
            'When user wants to create an invoice, guide them step-by-step:',
            '1. If customer info is missing, ask for name and email',
            '2. If products are missing, ask what to add',
            '3. Before creating, show summary and ask for confirmation',
            '4. DON\'T require all info at once - collect progressively',
        ],
        // ... rest of config
    ];
}
```

### Error Handling

Workflows handle errors gracefully:

```php
protected function createInvoice(UnifiedActionContext $context): ActionResult
{
    try {
        $invoice = Invoice::create([...]);
        
        return ActionResult::success(
            message: "Invoice created!",
            data: ['invoice_id' => $invoice->id]
        );
        
    } catch (\Exception $e) {
        Log::error('Invoice creation failed', [
            'error' => $e->getMessage(),
            'context' => $context->toArray(),
        ]);
        
        return ActionResult::failure(
            error: "Failed to create invoice: {$e->getMessage()}"
        );
    }
}
```

### Workflow Cancellation

Users can cancel workflows at any time:

```php
// User says "cancel" or "stop"
if ($this->checkForCancellation($context)) {
    $this->handleCancellation($context);
    
    return ActionResult::failure(
        error: "Workflow cancelled. How can I help you?"
    );
}
```

---

## Testing Workflows

### Unit Testing

```php
use Tests\TestCase;
use LaravelAIEngine\Services\Agent\AgentMode;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use App\AI\Workflows\CreateInvoiceWorkflow;

class CreateInvoiceWorkflowTest extends TestCase
{
    public function test_workflow_creates_invoice()
    {
        $agentMode = app(AgentMode::class);
        $context = new UnifiedActionContext('test-session', 1);
        
        // Start workflow
        $response = $agentMode->startWorkflow(
            CreateInvoiceWorkflow::class,
            $context,
            'Create invoice for test@example.com with 1 laptop'
        );
        
        $this->assertTrue($response->needsUserInput);
        
        // Continue with confirmations
        $response = $agentMode->continueWorkflow('yes', $context);
        
        // Assert invoice created
        $this->assertTrue($response->isComplete);
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $response->data['customer_id'],
        ]);
    }
}
```

### Integration Testing

```php
public function test_workflow_through_chat_service()
{
    $chat = app(ChatService::class);
    
    $response = $chat->processMessage(
        message: 'Create invoice for Sarah with 2 Pumps',
        sessionId: 'test-123',
        userId: 1
    );
    
    $this->assertTrue($response->metadata['workflow_active']);
    
    $response = $chat->processMessage(
        message: 'yes',
        sessionId: 'test-123',
        userId: 1
    );
    
    $this->assertTrue($response->metadata['workflow_completed']);
}
```

---

## Best Practices

### 1. Keep Workflows Focused

Each workflow should have a single, clear purpose:

✅ **Good**: `CreateInvoiceWorkflow`, `CreateCustomerWorkflow`  
❌ **Bad**: `HandleEverythingWorkflow`

### 2. Use Subworkflows for Reusability

Create reusable subworkflows for common tasks:

```php
// Reusable customer creation
CreateCustomerWorkflow::class

// Used by multiple parent workflows
CreateInvoiceWorkflow::class
CreateOrderWorkflow::class
CreateQuoteWorkflow::class
```

### 3. Provide Clear Prompts

Make prompts helpful and specific:

✅ **Good**: "What is the customer's email address?"  
❌ **Bad**: "Email?"

### 4. Handle Optional Fields

Allow users to skip optional fields:

```php
'phone' => [
    'type' => 'string',
    'description' => 'Phone number',
    'required' => false, // ✅ Optional
],

// In prompt: "What is the phone number? (Optional - type 'skip')"
```

### 5. Show Summaries Before Actions

Always confirm before creating/modifying data:

```php
return ActionResult::needsUserInput(
    message: "📋 Invoice Summary:\n" .
             "Customer: {$customer->name}\n" .
             "Products: {$productList}\n" .
             "Total: ${$total}\n\n" .
             "Would you like to create this invoice?",
    metadata: ['awaiting_confirmation' => true]
);
```

---

## Troubleshooting

### Workflow Not Starting

**Issue**: ChatService doesn't detect workflow intent

**Solution**: Ensure workflow is registered and intent keywords are clear

```php
// Add trigger keywords to workflow
protected function getTriggers(): array
{
    return ['invoice', 'create invoice', 'new invoice'];
}
```

### Context Not Persisting

**Issue**: Workflow state lost between messages

**Solution**: Verify cache is configured and context is being persisted

```php
// Check cache configuration
php artisan config:cache

// Verify context persistence
Log::info('Context persisted', [
    'session_id' => $context->sessionId,
    'workflow' => $context->currentWorkflow,
]);
```

### Subworkflow Not Returning

**Issue**: Subworkflow completes but parent doesn't continue

**Solution**: Ensure subworkflow returns success with required data

```php
// In subworkflow final action
return ActionResult::success(
    message: "Customer created",
    data: ['customer_id' => $customer->id] // ✅ Required for parent
);
```

---

## API Reference

### AgentMode Methods

```php
// Start a new workflow
$response = $agentMode->startWorkflow(
    string $workflowClass,
    UnifiedActionContext $context,
    string $initialMessage = ''
): AgentResponse

// Continue existing workflow
$response = $agentMode->continueWorkflow(
    string $message,
    UnifiedActionContext $context
): AgentResponse

// Execute workflow step
$response = $agentMode->execute(
    string $message,
    UnifiedActionContext $context
): AgentResponse
```

### UnifiedActionContext Methods

```php
// Workflow stack
$context->pushWorkflow(string $workflowClass, ?string $step, array $state);
$context->popWorkflow(): array;
$context->isInSubworkflow(): bool;
$context->getParentWorkflow(): ?array;

// State management
$context->set(string $key, $value): void;
$context->get(string $key, $default = null);
$context->has(string $key): bool;
$context->forget(string $key): void;

// Persistence
$context->persist(): void;
$context->toArray(): array;
```

### ActionResult Factory Methods

```php
// Success
ActionResult::success(
    string $message = '',
    array $data = []
): ActionResult

// Needs user input
ActionResult::needsUserInput(
    string $message,
    array $data = [],
    array $metadata = []
): ActionResult

// Failure
ActionResult::failure(
    string $error,
    array $metadata = []
): ActionResult
```

---

## See Also

- **[Subworkflow Implementation](subworkflows.md)** - Detailed subworkflow guide
- **[ChatService Integration](chat-workflow-integration.md)** - Integration details
- **[Data Collector](data-collector.md)** - Alternative conversational data collection
- **[Actions System](actions.md)** - AI-powered actions

---

**Production Ready**: 3/3 integration tests passing (100% success rate)
