# Invoice Creation Workflow - Agent Mode Example

## Your Requirement

**Complex Multi-Step Workflow:**
```
User: "Create invoice for Product X"
  ↓
1. Check if Product X exists
  ↓
  ├─ YES → Continue to step 3
  └─ NO → Go to step 2
  
2. Ask user: "Product X doesn't exist. Create it?"
  ↓
  ├─ YES → Create product workflow
  │   ↓
  │   Check if category exists
  │   ↓
  │   ├─ YES → Use existing category
  │   └─ NO → Ask user for category, create it
  │   ↓
  │   Create product
  │   ↓
  │   Return to invoice creation
  └─ NO → Cancel invoice creation

3. Confirm with user: "Create invoice with Product X for $100?"
  ↓
  ├─ YES → Create invoice
  └─ NO → Cancel or modify
```

## Why Current System Can't Do This

### Current ChatService Actions
❌ **Single-step execution** - Can't pause and resume  
❌ **No conditional logic** - Can't check if product exists  
❌ **No user confirmations** - Executes immediately  
❌ **No nested workflows** - Can't create product within invoice flow  

### Current DataCollector
❌ **Linear field collection** - Can't branch based on conditions  
❌ **No entity validation** - Can't check if product exists  
❌ **No nested collections** - Can't switch to product creation mid-flow  

## Solution: Agent Mode with Conditional Workflows

### Implementation

#### 1. Create Invoice Action with Agent Workflow

**File:** `app/AI/Actions/CreateInvoiceAction.php`

```php
<?php

namespace App\AI\Actions;

use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\Services\Agent\WorkflowStep;
use LaravelAIEngine\DTOs\ActionResult;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Category;

class CreateInvoiceAction extends AgentWorkflow
{
    /**
     * Define the workflow steps
     */
    public function defineSteps(): array
    {
        return [
            // Step 1: Extract invoice data from user message
            WorkflowStep::make('extract_invoice_data')
                ->execute(fn($context) => $this->extractInvoiceData($context))
                ->onSuccess('validate_products')
                ->onFailure('ask_for_invoice_details'),

            // Step 2: Validate that all products exist
            WorkflowStep::make('validate_products')
                ->execute(fn($context) => $this->validateProducts($context))
                ->onSuccess('confirm_invoice')
                ->onFailure('handle_missing_products'),

            // Step 3: Handle missing products
            WorkflowStep::make('handle_missing_products')
                ->execute(fn($context) => $this->handleMissingProducts($context))
                ->requiresUserInput(true)
                ->onSuccess('create_missing_products')
                ->onFailure('cancel_invoice'),

            // Step 4: Create missing products
            WorkflowStep::make('create_missing_products')
                ->execute(fn($context) => $this->createMissingProducts($context))
                ->onSuccess('validate_products') // Loop back to validate again
                ->onFailure('ask_for_product_details'),

            // Step 5: Confirm invoice creation with user
            WorkflowStep::make('confirm_invoice')
                ->execute(fn($context) => $this->confirmInvoice($context))
                ->requiresUserInput(true)
                ->onSuccess('create_invoice')
                ->onFailure('modify_invoice'),

            // Step 6: Create the invoice
            WorkflowStep::make('create_invoice')
                ->execute(fn($context) => $this->createInvoice($context))
                ->onSuccess('complete')
                ->onFailure('error'),
        ];
    }

    /**
     * Extract invoice data from user message
     */
    protected function extractInvoiceData($context): ActionResult
    {
        // Use AI to extract invoice details
        $extracted = $this->extractWithAI($context->message, [
            'customer_name' => 'required|string',
            'products' => 'required|array',
            'products.*.name' => 'required|string',
            'products.*.quantity' => 'required|numeric',
            'products.*.price' => 'nullable|numeric',
        ]);

        if (!$extracted['complete']) {
            return ActionResult::failure(
                error: 'Missing invoice details',
                data: ['missing' => $extracted['missing_fields']]
            );
        }

        $context->set('invoice_data', $extracted['data']);
        
        return ActionResult::success(
            message: 'Invoice data extracted',
            data: $extracted['data']
        );
    }

    /**
     * Validate that all products exist
     */
    protected function validateProducts($context): ActionResult
    {
        $invoiceData = $context->get('invoice_data');
        $missingProducts = [];
        $existingProducts = [];

        foreach ($invoiceData['products'] as $productData) {
            $product = Product::where('name', $productData['name'])->first();
            
            if (!$product) {
                $missingProducts[] = $productData;
            } else {
                $existingProducts[] = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price' => $productData['price'] ?? $product->price,
                    'quantity' => $productData['quantity'],
                ];
            }
        }

        if (!empty($missingProducts)) {
            $context->set('missing_products', $missingProducts);
            $context->set('existing_products', $existingProducts);
            
            return ActionResult::failure(
                error: 'Some products do not exist',
                data: [
                    'missing' => $missingProducts,
                    'existing' => $existingProducts,
                ]
            );
        }

        $context->set('validated_products', $existingProducts);
        
        return ActionResult::success(
            message: 'All products validated',
            data: ['products' => $existingProducts]
        );
    }

    /**
     * Ask user what to do about missing products
     */
    protected function handleMissingProducts($context): ActionResult
    {
        $missingProducts = $context->get('missing_products');
        $productNames = array_column($missingProducts, 'name');

        // This will pause the workflow and ask the user
        return ActionResult::needsUserInput(
            message: "The following products don't exist:\n" . 
                     implode(', ', $productNames) . "\n\n" .
                     "Would you like me to create them?",
            data: [
                'missing_products' => $missingProducts,
                'expected_response' => 'yes|no',
            ],
            actions: [
                ['label' => '✅ Yes, create them', 'value' => 'yes'],
                ['label' => '❌ No, cancel invoice', 'value' => 'no'],
            ]
        );
    }

    /**
     * Create missing products (with category validation)
     */
    protected function createMissingProducts($context): ActionResult
    {
        $missingProducts = $context->get('missing_products');
        $createdProducts = [];

        foreach ($missingProducts as $productData) {
            // Check if category exists (if provided)
            $category = null;
            if (!empty($productData['category'])) {
                $category = Category::where('name', $productData['category'])->first();
                
                if (!$category) {
                    // Ask user for category
                    return ActionResult::needsUserInput(
                        message: "Category '{$productData['category']}' doesn't exist for product '{$productData['name']}'.\n\n" .
                                 "Please provide a category or I'll create a new one.",
                        data: [
                            'product' => $productData,
                            'needs_category' => true,
                        ],
                        actions: [
                            ['label' => '📁 Create new category', 'value' => 'create_category'],
                            ['label' => '🔍 Choose existing', 'value' => 'choose_category'],
                        ]
                    );
                }
            }

            // Create product
            $product = Product::create([
                'name' => $productData['name'],
                'price' => $productData['price'] ?? 0,
                'category_id' => $category?->id,
            ]);

            $createdProducts[] = $product;
        }

        $context->set('created_products', $createdProducts);
        
        return ActionResult::success(
            message: 'Products created successfully',
            data: ['products' => $createdProducts]
        );
    }

    /**
     * Confirm invoice creation with user
     */
    protected function confirmInvoice($context): ActionResult
    {
        $invoiceData = $context->get('invoice_data');
        $products = $context->get('validated_products');
        
        // Calculate total
        $total = array_sum(array_map(
            fn($p) => $p['price'] * $p['quantity'],
            $products
        ));

        // Format confirmation message
        $message = "📄 **Invoice Summary**\n\n";
        $message .= "Customer: {$invoiceData['customer_name']}\n\n";
        $message .= "Products:\n";
        
        foreach ($products as $product) {
            $message .= "- {$product['name']} x{$product['quantity']} @ \${$product['price']} = \$" . 
                       ($product['price'] * $product['quantity']) . "\n";
        }
        
        $message .= "\n**Total: \${$total}**\n\n";
        $message .= "Create this invoice?";

        return ActionResult::needsUserInput(
            message: $message,
            data: [
                'invoice_data' => $invoiceData,
                'products' => $products,
                'total' => $total,
            ],
            actions: [
                ['label' => '✅ Confirm', 'value' => 'confirm'],
                ['label' => '✏️ Modify', 'value' => 'modify'],
                ['label' => '❌ Cancel', 'value' => 'cancel'],
            ]
        );
    }

    /**
     * Create the invoice
     */
    protected function createInvoice($context): ActionResult
    {
        $invoiceData = $context->get('invoice_data');
        $products = $context->get('validated_products');

        // Create invoice
        $invoice = Invoice::create([
            'customer_name' => $invoiceData['customer_name'],
            'total' => array_sum(array_map(
                fn($p) => $p['price'] * $p['quantity'],
                $products
            )),
        ]);

        // Attach products
        foreach ($products as $product) {
            $invoice->items()->create([
                'product_id' => $product['id'],
                'quantity' => $product['quantity'],
                'price' => $product['price'],
            ]);
        }

        return ActionResult::success(
            message: "✅ Invoice #{$invoice->id} created successfully!",
            data: ['invoice' => $invoice]
        );
    }
}
```

#### 2. Register the Action

**File:** `config/ai-actions.php`

```php
<?php

return [
    'actions' => [
        'create_invoice' => [
            'label' => '📄 Create Invoice',
            'description' => 'Create an invoice with product validation and confirmation',
            'executor' => \App\AI\Actions\CreateInvoiceAction::class,
            'type' => 'agent_workflow',
            'triggers' => [
                'create invoice',
                'new invoice',
                'invoice for',
            ],
            'requires_confirmation' => true,
            'can_create_dependencies' => true, // Can create products
        ],
    ],
];
```

#### 3. Usage Example

```php
// User message: "Create invoice for John Doe with 5 laptops at $1000 each"

// Agent workflow executes:
// 1. Extract invoice data ✅
// 2. Validate products ❌ (laptop doesn't exist)
// 3. Ask user: "Product 'laptop' doesn't exist. Create it?" 
//    → User: "yes"
// 4. Check category for laptop
//    → Ask user: "What category for laptop?"
//    → User: "Electronics"
// 5. Create category "Electronics" if needed
// 6. Create product "laptop"
// 7. Validate products again ✅
// 8. Confirm invoice:
//    "Invoice for John Doe: 5x laptop @ $1000 = $5000. Confirm?"
//    → User: "yes"
// 9. Create invoice ✅
```

## Conversation Flow Example

```
User: Create invoice for John with 2 laptops

Agent: 🔍 Checking products...
       Product "laptop" doesn't exist.
       
       Would you like me to create it?
       [✅ Yes, create it] [❌ No, cancel]

User: Yes

Agent: 📁 What category should "laptop" belong to?
       
       Existing categories:
       - Electronics
       - Office Supplies
       - Furniture
       
       [Choose existing] [Create new category]

User: Electronics

Agent: ✅ Product "laptop" created in Electronics category.
       
       💰 What's the price for laptop?

User: $1200

Agent: Perfect! Here's the invoice summary:
       
       📄 Invoice for John
       - laptop x2 @ $1200 = $2400
       
       Total: $2400
       
       Create this invoice?
       [✅ Confirm] [✏️ Modify] [❌ Cancel]

User: Confirm

Agent: ✅ Invoice #1234 created successfully!
```

## How This Works

### 1. Workflow Steps
Each step can:
- ✅ Execute logic
- ✅ Pause for user input
- ✅ Branch based on conditions
- ✅ Loop back to previous steps
- ✅ Access shared context

### 2. Context Management
```php
$context->set('invoice_data', $data);  // Store data
$context->get('invoice_data');         // Retrieve data
$context->has('invoice_data');         // Check if exists
```

### 3. User Input Handling
```php
ActionResult::needsUserInput(
    message: "Question for user",
    data: ['key' => 'value'],
    actions: [
        ['label' => 'Option 1', 'value' => 'opt1'],
        ['label' => 'Option 2', 'value' => 'opt2'],
    ]
);
```

### 4. Conditional Branching
```php
WorkflowStep::make('validate')
    ->execute(fn($context) => $this->validate($context))
    ->onSuccess('next_step')      // If validation passes
    ->onFailure('handle_error');   // If validation fails
```

## Implementation in Agent Mode

This requires **Phase 3** of the Unified Agent Plan:

### What You Need

1. **AgentWorkflow Base Class**
   - Workflow step execution
   - Context management
   - User input handling
   - Conditional branching

2. **WorkflowStep Class**
   - Step definition
   - Success/failure routing
   - User input requirements

3. **AgentMode Service**
   - Workflow orchestration
   - Step execution
   - State persistence

### Quick Start (Before Full Agent Mode)

You can implement this NOW with a simpler approach:

**File:** `app/Services/InvoiceCreationService.php`

```php
<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Product;
use App\Models\Category;

class InvoiceCreationService
{
    protected array $state = [];

    public function process(string $message, array $sessionState = []): array
    {
        $this->state = $sessionState;
        
        // State machine
        $step = $this->state['current_step'] ?? 'extract_data';
        
        return match($step) {
            'extract_data' => $this->extractData($message),
            'validate_products' => $this->validateProducts(),
            'ask_create_product' => $this->askCreateProduct($message),
            'create_product' => $this->createProduct($message),
            'ask_category' => $this->askCategory($message),
            'confirm_invoice' => $this->confirmInvoice($message),
            'create_invoice' => $this->createInvoice(),
            default => ['error' => 'Invalid step'],
        };
    }

    protected function extractData(string $message): array
    {
        // Extract invoice data using AI
        $data = $this->extractWithAI($message);
        
        $this->state['invoice_data'] = $data;
        $this->state['current_step'] = 'validate_products';
        
        return [
            'message' => 'Validating products...',
            'state' => $this->state,
            'continue' => true,
        ];
    }

    protected function validateProducts(): array
    {
        $products = $this->state['invoice_data']['products'];
        $missing = [];
        
        foreach ($products as $product) {
            if (!Product::where('name', $product['name'])->exists()) {
                $missing[] = $product['name'];
            }
        }
        
        if (!empty($missing)) {
            $this->state['missing_products'] = $missing;
            $this->state['current_step'] = 'ask_create_product';
            
            return [
                'message' => "Products don't exist: " . implode(', ', $missing) . 
                           "\n\nCreate them?",
                'actions' => [
                    ['label' => 'Yes', 'value' => 'yes'],
                    ['label' => 'No', 'value' => 'no'],
                ],
                'state' => $this->state,
                'needs_input' => true,
            ];
        }
        
        $this->state['current_step'] = 'confirm_invoice';
        return $this->confirmInvoice('');
    }

    // ... other methods
}
```

**Usage:**

```php
// In ChatService or Controller
$service = new InvoiceCreationService();

// First message
$result = $service->process("Create invoice for John with laptop");
// Returns: "Product laptop doesn't exist. Create it?"

// User responds
$result = $service->process("yes", $result['state']);
// Returns: "What category for laptop?"

// User responds
$result = $service->process("Electronics", $result['state']);
// Returns: "Invoice created!"
```

## Next Steps

### Option 1: Quick Implementation (This Week)
Implement the state machine approach above for invoice creation specifically.

### Option 2: Full Agent Mode (6-8 Weeks)
Follow the Unified Agent Plan to build the complete system that handles ANY workflow like this automatically.

Which approach do you prefer? 🚀
