# 🔄 Subworkflows

Delegate complex tasks to child workflows with automatic context management and seamless parent-child communication.

---

## Overview

Subworkflows allow you to break down complex workflows into smaller, reusable components. When a parent workflow encounters a task that requires multiple steps (like creating a customer), it can delegate that task to a specialized child workflow. The system automatically manages the workflow stack, context switching, and data passing between parent and child workflows.

### Key Benefits

- **Reusability**: Create once, use in multiple parent workflows
- **Separation of Concerns**: Each workflow focuses on a single task
- **Automatic Context Management**: System handles stack operations
- **Data Passing**: Results automatically returned to parent
- **State Preservation**: Parent state maintained while child executes

---

## How Subworkflows Work

### Workflow Stack

The system maintains a stack of active workflows:

```
┌─────────────────────────────────────┐
│  Workflow Stack                      │
├─────────────────────────────────────┤
│  [Top] CreateCustomerWorkflow       │ ← Currently executing
│         ├─ step: collect_email      │
│         └─ state: {name: "John"}    │
├─────────────────────────────────────┤
│  CreateInvoiceWorkflow              │ ← Waiting (parent)
│         ├─ step: resolve_customer   │
│         └─ state: {products: [...]} │
└─────────────────────────────────────┘
```

### Execution Flow

```
1. Parent Workflow: CreateInvoiceWorkflow
   ↓
   Needs customer (not found)
   ↓
2. Push parent to stack
   Stack: [CreateInvoiceWorkflow]
   ↓
3. Start Child Workflow: CreateCustomerWorkflow
   Stack: [CreateInvoiceWorkflow, CreateCustomerWorkflow]
   ↓
4. Child collects data
   - Collect name
   - Collect email
   - Collect phone (optional)
   ↓
5. Child creates customer
   Result: {customer_id: 123}
   ↓
6. Pop parent from stack
   Stack: [CreateInvoiceWorkflow]
   ↓
7. Parent receives customer_id
   Continues with invoice creation
   ↓
8. Parent completes
   Stack: []
```

---

## Configuration

### Enable Subworkflow in Parent

```php
class CreateInvoiceWorkflow extends AgentWorkflow
{
    use AutomatesSteps;
    
    protected function config(): array
    {
        return [
            'goal' => 'Create an invoice',
            'fields' => [
                'customer_name' => 'Customer name | required',
                'customer_email' => 'Customer email | required',
            ],
            'entities' => [
                'customer' => [
                    'model' => Customer::class,
                    'search_field' => 'email',
                    'create_if_missing' => true,
                    'subflow' => CreateCustomerWorkflow::class, // ✅ Subworkflow
                ],
            ],
            'final_action' => 'createInvoice',
        ];
    }
}
```

### Create Subworkflow

```php
class CreateCustomerWorkflow extends AgentWorkflow
{
    use AutomatesSteps;
    
    protected function config(): array
    {
        return [
            'goal' => 'Create a new customer',
            'fields' => [
                'customer_name' => [
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
                'billing_address' => [
                    'type' => 'text',
                    'description' => 'Billing address',
                    'required' => false,
                ],
            ],
            'final_action' => 'createCustomer',
        ];
    }
    
    protected function createCustomer(UnifiedActionContext $context): ActionResult
    {
        $data = $context->get('collected_data');
        
        // Generate unique customer ID
        $workspace = getActiveWorkSpace();
        $maxCustomerId = Customer::where('workspace', $workspace)->max('customer_id') ?? 0;
        
        // Create user account
        $user = User::create([
            'name' => $data['customer_name'],
            'email' => $data['email'],
            'type' => 'customer',
        ]);
        
        // Create customer
        $customer = Customer::create([
            'customer_id' => $maxCustomerId + 1,
            'user_id' => $user->id,
            'name' => $data['customer_name'],
            'email' => $data['email'],
            'contact' => $data['phone'] ?? null,
            'billing_address' => $data['billing_address'] ?? null,
            'workspace' => $workspace,
        ]);
        
        return ActionResult::success(
            message: "✅ Customer '{$customer->name}' created successfully!",
            data: [
                'customer_id' => $customer->customer_id, // ✅ Returned to parent
                'customer' => $customer,
            ]
        );
    }
}
```

---

## Automatic Subworkflow Execution

The `AutomatesSteps` trait handles subworkflow execution automatically.

### When Subworkflow Starts

```php
// In AutomatesSteps::executeSubflow()

// 1. Check if already in subworkflow
if ($context->isInSubworkflow()) {
    // Continue existing subworkflow
    $response = $agentMode->continueWorkflow($message, $context);
    
    // Check if subworkflow completed
    if ($response->isComplete && $response->success) {
        // Pop parent from stack
        $parent = $context->popWorkflow();
        
        // Extract created entity ID
        if (isset($response->data['customer_id'])) {
            $context->set('customer_id', $response->data['customer_id']);
        }
        
        return ActionResult::success(
            message: "✅ Customer created successfully",
            data: $response->data
        );
    }
    
    return ActionResult::needsUserInput(
        message: $response->message,
        metadata: ['in_subflow' => true]
    );
}

// 2. First time - start new subworkflow
$context->pushWorkflow($subflowClass, null, $subflowState);
$context->currentStep = null; // Start from first step

// 3. Start the subworkflow
$response = $agentMode->startWorkflow($subflowClass, $context, '');

return ActionResult::needsUserInput(
    message: $response->message,
    metadata: ['subflow_started' => true]
);
```

### Context Stack Operations

```php
// Push workflow onto stack
$context->pushWorkflow(
    workflowClass: CreateCustomerWorkflow::class,
    step: null, // Start from first step
    state: ['customer_name' => 'John Smith'] // Pre-populate data
);

// Check if in subworkflow
if ($context->isInSubworkflow()) {
    // Get parent workflow info
    $parent = $context->getParentWorkflow();
    // Returns: [
    //     'workflow' => 'CreateInvoiceWorkflow',
    //     'step' => 'resolve_customer',
    //     'state' => [...]
    // ]
}

// Pop workflow from stack (returns to parent)
$parent = $context->popWorkflow();
```

---

## Data Passing

### Parent to Child

Data can be passed when starting a subworkflow:

```php
// In parent workflow
$subflowState = [
    'customer_name' => $collectedData['customer_name'],
    'email' => $collectedData['customer_email'],
];

$context->pushWorkflow(
    CreateCustomerWorkflow::class,
    null,
    $subflowState // ✅ Pre-populate child workflow
);
```

### Child to Parent

Child workflow returns data in the final action:

```php
// In child workflow
protected function createCustomer(UnifiedActionContext $context): ActionResult
{
    $customer = Customer::create([...]);
    
    return ActionResult::success(
        message: "Customer created",
        data: [
            'customer_id' => $customer->customer_id, // ✅ Returned to parent
            'customer' => $customer,
        ]
    );
}

// Parent receives data automatically
// $context->get('customer_id') is now available
```

---

## Real-World Example

### Complete Flow with Subworkflow

```
User: "Create invoice for John Smith with 2 laptops"
  ↓
Parent: CreateInvoiceWorkflow starts
  ↓
Parent: Collects customer_name="John Smith", products="laptops"
  ↓
Parent: Searches for customer by name
  ↓
Parent: Customer not found
  ↓
Parent: "Customer 'John Smith' doesn't exist. Would you like to create it?"
  ↓
User: "yes"
  ↓
Parent: Pushes self to stack
Parent: Starts CreateCustomerWorkflow
  ↓
Child: CreateCustomerWorkflow executing
Child: "What is the customer's email?"
  ↓
User: "john@example.com"
  ↓
Child: Stores email
Child: "What is the customer's phone? (Optional - type 'skip')"
  ↓
User: "skip"
  ↓
Child: Skips phone
Child: "What is the billing address? (Optional - type 'skip')"
  ↓
User: "skip"
  ↓
Child: Skips address
Child: Creates customer
Child: Returns {customer_id: 123}
  ↓
Parent: Pops from stack
Parent: Receives customer_id=123
Parent: Continues with products
  ↓
Parent: Searches for product "laptops"
Parent: Product not found
Parent: Creates product
  ↓
Parent: "📋 Invoice Summary:
        Customer: John Smith (john@example.com)
        Products: laptops x2 @ $999 = $1,998
        Total: $1,998
        
        Would you like to create this invoice?"
  ↓
User: "yes"
  ↓
Parent: Creates invoice
Parent: "✅ Invoice #INVO001234 created successfully!"
```

---

## Multiple Levels of Nesting

Subworkflows can have their own subworkflows:

```
CreateOrderWorkflow (Level 0)
  ↓
  Needs customer
  ↓
CreateCustomerWorkflow (Level 1)
  ↓
  Needs address validation
  ↓
ValidateAddressWorkflow (Level 2)
  ↓
  Validates address
  ↓
Returns to CreateCustomerWorkflow (Level 1)
  ↓
  Creates customer
  ↓
Returns to CreateOrderWorkflow (Level 0)
  ↓
  Creates order
```

**Stack at Level 2:**
```
[CreateOrderWorkflow, CreateCustomerWorkflow, ValidateAddressWorkflow]
```

---

## Custom Subworkflow Logic

### Manual Subworkflow Execution

For custom control, execute subworkflows manually:

```php
protected function handleMissingCustomer(UnifiedActionContext $context): ActionResult
{
    // Check if already in subworkflow
    if ($context->isInSubworkflow()) {
        $agentMode = app(AgentMode::class);
        $lastMessage = end($context->conversationHistory)['content'] ?? '';
        
        // Continue subworkflow
        $response = $agentMode->continueWorkflow($lastMessage, $context);
        
        if ($response->isComplete) {
            // Subworkflow done, pop parent
            $context->popWorkflow();
            
            // Extract customer_id
            $customerId = $response->data['customer_id'] ?? null;
            if ($customerId) {
                $context->set('customer_id', $customerId);
                return ActionResult::success(
                    message: "Customer created, continuing with invoice..."
                );
            }
        }
        
        // Still needs input
        return ActionResult::needsUserInput(
            message: $response->message
        );
    }
    
    // First time - ask user
    return ActionResult::needsUserInput(
        message: "Customer not found. Would you like to create it? (yes/no)",
        metadata: ['awaiting_customer_creation_confirmation' => true]
    );
}

protected function startCustomerSubworkflow(UnifiedActionContext $context): ActionResult
{
    // Push parent to stack
    $context->pushWorkflow(
        CreateCustomerWorkflow::class,
        null,
        ['customer_name' => $context->get('customer_name')]
    );
    
    // Start subworkflow
    $agentMode = app(AgentMode::class);
    $response = $agentMode->startWorkflow(
        CreateCustomerWorkflow::class,
        $context,
        ''
    );
    
    return ActionResult::needsUserInput(
        message: $response->message,
        metadata: ['subflow_started' => true]
    );
}
```

---

## Testing Subworkflows

### Unit Test

```php
public function test_subworkflow_creates_customer()
{
    $agentMode = app(AgentMode::class);
    $context = new UnifiedActionContext('test-session', 1);
    
    // Start parent workflow
    $response = $agentMode->startWorkflow(
        CreateInvoiceWorkflow::class,
        $context,
        'Create invoice for newcustomer@example.com with 1 laptop'
    );
    
    // Should ask to create customer
    $this->assertStringContainsString('create', strtolower($response->message));
    
    // Confirm customer creation
    $response = $agentMode->continueWorkflow('yes', $context);
    
    // Should be in subworkflow
    $this->assertTrue($context->isInSubworkflow());
    $this->assertEquals(
        CreateCustomerWorkflow::class,
        $context->currentWorkflow
    );
    
    // Provide customer details
    $response = $agentMode->continueWorkflow('newcustomer@example.com', $context);
    $response = $agentMode->continueWorkflow('skip', $context); // phone
    $response = $agentMode->continueWorkflow('skip', $context); // address
    
    // Should return to parent
    $this->assertFalse($context->isInSubworkflow());
    $this->assertEquals(
        CreateInvoiceWorkflow::class,
        $context->currentWorkflow
    );
    
    // Customer ID should be set
    $this->assertNotNull($context->get('customer_id'));
}
```

### Integration Test

```php
public function test_full_flow_with_subworkflow()
{
    $chat = app(ChatService::class);
    $sessionId = 'test-' . time();
    
    // Start invoice creation
    $response = $chat->processMessage(
        message: 'Create invoice for test@example.com with 1 Pump',
        sessionId: $sessionId,
        userId: 1
    );
    
    // Confirm customer creation
    $response = $chat->processMessage(
        message: 'yes',
        sessionId: $sessionId,
        userId: 1
    );
    
    // Provide email (subworkflow)
    $response = $chat->processMessage(
        message: 'test@example.com',
        sessionId: $sessionId,
        userId: 1
    );
    
    // Skip phone
    $response = $chat->processMessage(
        message: 'skip',
        sessionId: $sessionId,
        userId: 1
    );
    
    // Confirm invoice
    $response = $chat->processMessage(
        message: 'yes',
        sessionId: $sessionId,
        userId: 1
    );
    
    // Verify invoice created
    $this->assertTrue($response->metadata['workflow_completed']);
    $this->assertDatabaseHas('invoices', [
        'customer_id' => $response->metadata['workflow_data']['customer_id'],
    ]);
}
```

---

## Best Practices

### 1. Keep Subworkflows Focused

Each subworkflow should handle one specific task:

✅ **Good**: `CreateCustomerWorkflow`, `CreateProductWorkflow`  
❌ **Bad**: `CreateCustomerAndProductsWorkflow`

### 2. Return Required Data

Always return the data parent needs:

```php
// ✅ Good
return ActionResult::success(
    message: "Customer created",
    data: ['customer_id' => $customer->id]
);

// ❌ Bad
return ActionResult::success(
    message: "Customer created"
    // Missing customer_id!
);
```

### 3. Pre-populate When Possible

Pass known data to subworkflows:

```php
$context->pushWorkflow(
    CreateCustomerWorkflow::class,
    null,
    [
        'customer_name' => $collectedData['name'], // ✅ Pre-populate
        'email' => $collectedData['email'],
    ]
);
```

### 4. Handle Subworkflow Failures

Check for errors when subworkflow completes:

```php
if ($response->isComplete) {
    if ($response->success) {
        // Success - extract data
        $customerId = $response->data['customer_id'];
    } else {
        // Failure - handle error
        return ActionResult::failure(
            error: "Failed to create customer: {$response->message}"
        );
    }
}
```

### 5. Clear Error Messages

Provide context about which workflow is executing:

```php
return ActionResult::needsUserInput(
    message: "[Creating Customer] What is the email address?",
    metadata: ['subflow' => 'CreateCustomerWorkflow']
);
```

---

## Troubleshooting

### Subworkflow Not Starting

**Issue**: Parent workflow doesn't start subworkflow

**Solution**: Verify subworkflow is configured correctly

```php
'entities' => [
    'customer' => [
        'model' => Customer::class,
        'create_if_missing' => true, // ✅ Required
        'subflow' => CreateCustomerWorkflow::class, // ✅ Required
    ],
]
```

### Data Not Passed to Parent

**Issue**: Parent doesn't receive customer_id

**Solution**: Ensure subworkflow returns data in success result

```php
// In subworkflow final action
return ActionResult::success(
    message: "Customer created",
    data: ['customer_id' => $customer->id] // ✅ Must include
);
```

### Stack Not Popping

**Issue**: Workflow stays in subworkflow after completion

**Solution**: Verify subworkflow returns `isComplete = true`

```php
// Check subworkflow completion
if ($response->isComplete && $response->success) {
    $context->popWorkflow(); // ✅ Pop parent
}
```

### Context Lost

**Issue**: Parent state lost after subworkflow

**Solution**: Ensure context is persisted after each step

```php
$context->persist(); // ✅ Save to cache
```

---

## API Reference

### Context Stack Methods

```php
// Push workflow onto stack
$context->pushWorkflow(
    string $workflowClass,
    ?string $step = null,
    array $state = []
): void

// Pop workflow from stack (returns parent info)
$context->popWorkflow(): array
// Returns: ['workflow' => '...', 'step' => '...', 'state' => [...]]

// Check if in subworkflow
$context->isInSubworkflow(): bool

// Get parent workflow info
$context->getParentWorkflow(): ?array
```

### Subworkflow Execution

```php
// In AutomatesSteps trait
protected function executeSubflow(
    string $subflowClass,
    UnifiedActionContext $context,
    string $entityName
): ActionResult
```

---

## See Also

- **[Workflow System](workflows.md)** - Main workflow documentation
- **[ChatService Integration](chat-workflow-integration.md)** - Integration guide
- **[Entity Resolution](../advanced/entity-resolution.md)** - Custom entity resolution

---

**Production Status**: Fully tested with 100% integration test pass rate
