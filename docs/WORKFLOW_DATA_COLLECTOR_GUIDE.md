# WorkflowDataCollector - Generic AI-Powered Data Collection

## Overview

**WorkflowDataCollector** is a generic, reusable system for collecting data from users in multi-turn conversations using AI. It's similar to DataCollector but designed specifically for complex workflows with conditional logic.

---

## Key Features

✅ **AI-Powered Extraction** - Automatically extracts structured data from natural language  
✅ **Progressive Collection** - Collects data across multiple conversation turns  
✅ **Generic & Reusable** - Works with any workflow, any fields  
✅ **Type-Safe** - Validates data types and requirements  
✅ **Smart Merging** - Intelligently merges new data with existing data  
✅ **Flexible** - Supports conditional logic and complex workflows  

---

## How It Works

```
User: "Create invoice for John Smith with 2 iPhones"
    ↓
WorkflowDataCollector extracts:
  - customer_name: "John Smith"
  - products: [{"name": "iPhone", "quantity": 2}]
    ↓
Checks missing fields: None
    ↓
Returns: Success with all data
```

---

## Usage

### 1. Add Trait to Your Workflow

```php
use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\Services\Agent\Traits\CollectsWorkflowData;

class CreateInvoiceWorkflow extends AgentWorkflow
{
    use CollectsWorkflowData;
    
    // Your workflow code...
}
```

### 2. Define Fields to Collect

```php
protected function getFieldDefinitions(): array
{
    return [
        'customer_name' => [
            'type' => 'string',
            'required' => true,
            'prompt' => 'What is the customer name?',
            'description' => 'Name of the customer',
        ],
        'quantity' => [
            'type' => 'integer',
            'required' => true,
            'prompt' => 'How many units?',
            'description' => 'Number of items',
            'min' => 1,
        ],
        'products' => [
            'type' => 'array',
            'required' => true,
            'prompt' => 'What products?',
            'description' => 'List of products',
        ],
    ];
}
```

### 3. Use in Workflow Steps

```php
protected function extractData($context): ActionResult
{
    // Automatically collects data from user messages
    return $this->collectData($context);
}

protected function processData($context): ActionResult
{
    // Check if all data collected
    if (!$this->isDataComplete($context)) {
        return ActionResult::needsUserInput(
            message: 'Please provide more information'
        );
    }
    
    // Get collected data
    $data = $this->getCollectedData($context);
    
    // Use the data
    $customerName = $data['customer_name'];
    $quantity = $data['quantity'];
    
    // Continue workflow...
}
```

---

## Field Definition Options

### Basic Field

```php
'field_name' => [
    'type' => 'string',           // string, integer, array
    'required' => true,            // Is this field required?
    'prompt' => 'What is X?',      // Question to ask user
    'description' => 'Field desc', // For AI extraction
]
```

### With Validation

```php
'quantity' => [
    'type' => 'integer',
    'required' => true,
    'min' => 1,
    'max' => 1000,
    'prompt' => 'How many units?',
]
```

### Array Field

```php
'products' => [
    'type' => 'array',
    'required' => true,
    'prompt' => 'What products?',
    'description' => 'Array of product objects with name and quantity',
]
```

---

## Available Methods

### From Trait

```php
// Collect data from user message
$result = $this->collectData($context);

// Check if all required fields collected
$complete = $this->isDataComplete($context);

// Get specific field value
$name = $this->getFieldValue($context, 'customer_name');

// Set specific field value
$this->setFieldValue($context, 'quantity', 5);

// Get all collected data
$data = $this->getCollectedData($context);

// Validate collected data
$errors = $this->validateCollectedData($context);

// Clear collected data
$this->clearCollectedData($context);
```

---

## Complete Example: Invoice Workflow

```php
<?php

namespace App\AI\Workflows;

use LaravelAIEngine\Services\Agent\AgentWorkflow;
use LaravelAIEngine\Services\Agent\Traits\CollectsWorkflowData;
use LaravelAIEngine\DTOs\WorkflowStep;
use LaravelAIEngine\DTOs\ActionResult;

class CreateInvoiceWorkflow extends AgentWorkflow
{
    use CollectsWorkflowData;
    
    protected function getFieldDefinitions(): array
    {
        return [
            'customer_name' => [
                'type' => 'string',
                'required' => true,
                'prompt' => 'What is the customer name?',
                'description' => 'Name of the customer for this invoice',
            ],
            'products' => [
                'type' => 'array',
                'required' => true,
                'prompt' => 'What products are on this invoice?',
                'description' => 'Array of products with name and quantity',
            ],
        ];
    }
    
    public function defineSteps(): array
    {
        return [
            WorkflowStep::make('collect_data')
                ->execute(fn($ctx) => $this->collectData($ctx))
                ->onSuccess('validate_data')
                ->onFailure('collect_data'), // Loop back to collect more
                
            WorkflowStep::make('validate_data')
                ->execute(fn($ctx) => $this->validateData($ctx))
                ->onSuccess('create_invoice')
                ->onFailure('collect_data'),
                
            WorkflowStep::make('create_invoice')
                ->execute(fn($ctx) => $this->createInvoice($ctx))
                ->onSuccess('complete')
                ->onFailure('error'),
        ];
    }
    
    protected function validateData($context): ActionResult
    {
        $errors = $this->validateCollectedData($context);
        
        if (!empty($errors)) {
            return ActionResult::failure(
                error: 'Validation failed',
                data: $errors
            );
        }
        
        return ActionResult::success(message: 'Data valid');
    }
    
    protected function createInvoice($context): ActionResult
    {
        $data = $this->getCollectedData($context);
        
        // Create invoice with collected data
        $invoice = Invoice::create([
            'customer_name' => $data['customer_name'],
            'products' => $data['products'],
        ]);
        
        return ActionResult::success(
            message: "Invoice #{$invoice->id} created!",
            data: $invoice
        );
    }
}
```

---

## Conversation Flow Example

```
Turn 1:
User: "Create invoice with iPhone"
System extracts: products=[{"name":"iPhone"}]
Missing: customer_name, quantity
Agent: "What is the customer name?"

Turn 2:
User: "John Smith"
System extracts: customer_name="John Smith"
Missing: quantity
Agent: "How many iPhone units?"

Turn 3:
User: "2"
System extracts: quantity=2
Missing: None
Agent: "Invoice created successfully!"
```

---

## How AI Extraction Works

The system sends this prompt to AI:

```
Extract structured data from the user's message.

User said: "Create invoice for John with 2 iPhones"

Already collected: {}

Fields to extract:
- customer_name (string): Name of the customer
- products (array): Array of products with name and quantity

Rules:
- Only extract fields clearly mentioned
- Return empty object {} if no fields found
- Don't guess or infer data

Return ONLY valid JSON with extracted fields.
```

AI Response:
```json
{
  "customer_name": "John",
  "products": [
    {"name": "iPhone", "quantity": 2}
  ]
}
```

---

## Advantages Over DataCollector

| Feature | DataCollector | WorkflowDataCollector |
|---------|---------------|----------------------|
| Multi-turn conversation | ✅ | ✅ |
| Field validation | ✅ | ✅ |
| AI extraction | ❌ | ✅ |
| Conditional logic | ❌ | ✅ |
| Database validation | ❌ | ✅ |
| Entity creation | ❌ | ✅ |
| Tool integration | ❌ | ✅ |
| Workflow integration | ❌ | ✅ |
| Reusable | ✅ | ✅ |

---

## Best Practices

### 1. Clear Field Descriptions
```php
'customer_name' => [
    'description' => 'Full name of the customer (first and last name)',
    // Not: 'description' => 'name'
]
```

### 2. Specific Prompts
```php
'quantity' => [
    'prompt' => 'How many units would you like to order?',
    // Not: 'prompt' => 'Quantity?'
]
```

### 3. Validate Early
```php
protected function processData($context): ActionResult
{
    // Validate before using
    $errors = $this->validateCollectedData($context);
    if (!empty($errors)) {
        return ActionResult::failure(error: 'Invalid data');
    }
    
    // Now safe to use
    $data = $this->getCollectedData($context);
}
```

### 4. Handle Partial Data
```php
protected function checkProgress($context): ActionResult
{
    $data = $this->getCollectedData($context);
    
    // Can work with partial data
    if (!empty($data['customer_name'])) {
        // Do something with customer name
    }
    
    if ($this->isDataComplete($context)) {
        // All data collected, proceed
    }
}
```

---

## Testing

```php
// Test data collection
$workflow = new CreateInvoiceWorkflow();
$context = new UnifiedActionContext('test-session', 1);

// Simulate user messages
$context->addUserMessage('Create invoice for John Smith');
$result = $workflow->collectData($context);

// Check collected data
$data = $workflow->getCollectedData($context);
assert($data['customer_name'] === 'John Smith');

// Check if complete
$complete = $workflow->isDataComplete($context);
assert($complete === false); // Still missing products
```

---

## Troubleshooting

### Issue: AI Not Extracting Data

**Solution:** Improve field descriptions
```php
'products' => [
    'description' => 'Array of product objects, each with "name" and "quantity" fields',
    // More specific helps AI understand structure
]
```

### Issue: Data Not Persisting

**Solution:** Ensure context is saved
```php
// In AgentOrchestrator
$this->contextManager->save($context);
```

### Issue: Wrong Data Type

**Solution:** Add type validation
```php
'quantity' => [
    'type' => 'integer',
    'min' => 1,
]
```

---

## Summary

**WorkflowDataCollector** provides:
- ✅ Generic, reusable data collection
- ✅ AI-powered extraction
- ✅ Multi-turn conversation support
- ✅ Type validation
- ✅ Easy integration with workflows
- ✅ Flexible and extensible

**Use it for:**
- Invoice creation
- Order processing
- User registration
- Any multi-step data collection

**Your workflows are now generic and reusable!** 🚀
