# AI-Autonomous Data Collector

## Overview

The **Autonomous Collector** is a paradigm shift from traditional form-based data collection. Instead of defining rigid fields with validation rules, you give the AI:

1. **A Goal** - What you want to achieve
2. **Tools** - How to look up or create entities
3. **Output Schema** - The JSON structure you expect

The AI handles everything else: understanding user intent, parsing complex inputs, using tools, and producing structured output.

## Why Use Autonomous Collector?

### The Problem with Traditional Approach

```
User: create invoice with 2 products
Bot: Who is the customer?
User: Mohamed
Bot: What products?
User: macbook pro m4 and macbook pro m3
Bot: ERROR - couldn't parse "macbook pro m4 and macbook pro m3" ❌
```

The traditional approach:
- Requires manual field definitions
- Fails on complex natural language
- Creates awkward conversations
- Pre-loads all data (inefficient)

### The Autonomous Approach

```
User: create invoice for Mohamed with 2 macbook pro m4 and 1 macbook pro m3

Bot: [uses find_customer("Mohamed")] → Found Mohamed Ahmed
     [uses find_product("macbook pro m4")] → Found MacBook Pro M4 ($2,499)
     [uses find_product("macbook pro m3")] → Found MacBook Pro M3 ($1,999)
     
     I'll create an invoice for Mohamed Ahmed with:
     - 2x MacBook Pro M4 @ $2,499 = $4,998
     - 1x MacBook Pro M3 @ $1,999 = $1,999
     Total: $6,997
     
     Shall I proceed? ✅
```

## Quick Start

```php
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Facades\AutonomousCollector;

// 1. Define your config with goal, tools, and output schema
$config = new AutonomousCollectorConfig(
    goal: 'Create a sales invoice',
    
    tools: [
        'find_customer' => [
            'description' => 'Search for customer by name',
            'handler' => fn($query) => Customer::where('name', 'like', "%{$query}%")->get(),
        ],
        'find_product' => [
            'description' => 'Search for product by name',
            'handler' => fn($query) => Product::where('name', 'like', "%{$query}%")->get(),
        ],
    ],
    
    outputSchema: [
        'customer_id' => 'integer|required',
        'items' => [
            'type' => 'array',
            'items' => [
                'product_id' => 'integer|required',
                'quantity' => 'integer|required',
            ],
        ],
    ],
    
    onComplete: fn($data) => Invoice::create($data),
);

// 2. Start collection with user's message
$response = AutonomousCollector::start(
    'session-123',
    $config,
    "Create invoice for Mohamed with 2 macbooks"
);

// 3. Continue conversation if needed
$response = AutonomousCollector::process('session-123', "Yes, proceed");

// 4. Get result
if ($response->isComplete) {
    $invoice = $response->result;
}
```

## Configuration Options

### AutonomousCollectorConfig

| Parameter | Type | Description |
|-----------|------|-------------|
| `goal` | string | What you want to achieve (required) |
| `description` | string | Detailed context for the AI |
| `tools` | array | Tools the AI can use |
| `outputSchema` | array | Expected JSON structure |
| `onComplete` | Closure | Callback when complete |
| `onCompleteAction` | string | Action class to execute |
| `confirmBeforeComplete` | bool | Ask user to confirm (default: true) |
| `systemPromptAddition` | string | Custom instructions for AI |
| `context` | array | Static reference data |
| `maxTurns` | int | Max conversation turns (default: 20) |
| `name` | string | Unique identifier |

### Defining Tools

Tools are functions the AI can call to look up or create data:

```php
'tools' => [
    'find_customer' => [
        'description' => 'Search for existing customer by name or email',
        'parameters' => [
            'query' => 'required|string',
        ],
        'handler' => function ($query) {
            return Customer::where('name', 'like', "%{$query}%")
                ->orWhere('email', 'like', "%{$query}%")
                ->limit(5)
                ->get(['id', 'name', 'email']);
        },
    ],
    
    'create_customer' => [
        'description' => 'Create a new customer if not found',
        'parameters' => [
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'nullable|string',
        ],
        'handler' => function ($data) {
            return Customer::create($data);
        },
    ],
],
```

### Output Schema

Define the JSON structure you expect:

```php
'outputSchema' => [
    // Simple fields
    'customer_id' => 'integer|required',
    'notes' => 'string|nullable',
    
    // Array of objects
    'items' => [
        'type' => 'array',
        'required' => true,
        'items' => [
            'product_id' => 'integer|required',
            'quantity' => 'integer|required',
            'unit_price' => 'numeric|required',
        ],
    ],
    
    // Nested objects
    'shipping' => [
        'type' => 'object',
        'properties' => [
            'address' => 'string|required',
            'city' => 'string|required',
        ],
    ],
],
```

## Response Object

```php
$response = AutonomousCollector::process($sessionId, $message);

$response->success;              // bool - Operation succeeded
$response->message;              // string - AI's response message
$response->status;               // string - 'collecting', 'confirming', 'completed', 'cancelled'
$response->collectedData;        // array - Data collected so far
$response->isComplete;           // bool - Collection finished
$response->isCancelled;          // bool - User cancelled
$response->requiresConfirmation; // bool - Waiting for user confirmation
$response->result;               // mixed - Result from onComplete callback
$response->turnCount;            // int - Number of conversation turns
```

## Complete Example: Invoice Creator

```php
<?php

namespace App\Collectors;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Invoice;

class InvoiceCollector
{
    public static function config(): AutonomousCollectorConfig
    {
        return new AutonomousCollectorConfig(
            goal: 'Create a sales invoice',
            
            description: <<<DESC
            Help the user create a sales invoice by:
            1. Identifying or creating the customer
            2. Adding products with quantities
            3. Calculating totals
            
            Parse user input intelligently:
            - "2 macbooks and 3 mice" = 2 separate products
            - "for Mohamed" = customer name to search
            DESC,
            
            tools: [
                'find_customer' => [
                    'description' => 'Search for customer by name or email. Returns not_found if no match.',
                    'handler' => function($query) {
                        $found = Customer::where('name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%")
                            ->get(['id', 'name', 'email']);
                        
                        if ($found->isEmpty()) {
                            return ['found' => false, 'message' => "Customer not found. Ask user to confirm creation."];
                        }
                        return ['found' => true, 'customers' => $found->toArray()];
                    },
                ],
                
                'create_customer' => [
                    'description' => 'Create a new customer. ONLY call after user confirms.',
                    'parameters' => ['name' => 'required', 'email' => 'required|email'],
                    'handler' => fn($data) => Customer::create($data),
                ],
                
                'find_product' => [
                    'description' => 'Search for products by name. Returns not_found if no match.',
                    'handler' => function($query) {
                        $found = Product::where('name', 'like', "%{$query}%")
                            ->get(['id', 'name', 'price', 'stock']);
                        
                        if ($found->isEmpty()) {
                            return ['found' => false, 'message' => "Product not found. Ask user to confirm creation."];
                        }
                        return ['found' => true, 'products' => $found->toArray()];
                    },
                ],
                
                'create_product' => [
                    'description' => 'Create a new product. ONLY call after user confirms.',
                    'parameters' => ['name' => 'required', 'price' => 'required|numeric'],
                    'handler' => fn($data) => Product::create($data),
                ],
            ],
            
            outputSchema: [
                'customer_id' => 'integer|required',
                'items' => [
                    'type' => 'array',
                    'required' => true,
                    'items' => [
                        'product_id' => 'integer|required',
                        'quantity' => 'integer|required',
                        'unit_price' => 'numeric|required',
                        'total' => 'numeric|required',
                    ],
                ],
                'subtotal' => 'numeric|required',
                'tax' => 'numeric|nullable',
                'total' => 'numeric|required',
                'notes' => 'string|nullable',
            ],
            
            onComplete: fn($data) => Invoice::create($data),
            
            confirmBeforeComplete: true,
            
            systemPromptAddition: <<<PROMPT
            IMPORTANT RULES:
            
            1. PARSING:
               - "2 X and 3 Y" means TWO different products
               - Search for each product separately
               - Be helpful with typos
            
            2. CONFIRMATION REQUIRED BEFORE CREATING:
               - If customer not found: ASK user if they want to create
               - If product not found: ASK user if they want to create
               - NEVER call create_* without user confirmation
            
            3. CALCULATIONS:
               - Calculate totals: unit_price × quantity
               - Sum all items for subtotal
            PROMPT,
            
            name: 'invoice_creator',
        );
    }
}
```

## API Controller Example

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use LaravelAIEngine\Facades\AutonomousCollector;
use App\Collectors\InvoiceCollector;

class InvoiceCollectorController extends Controller
{
    public function start(Request $request)
    {
        $sessionId = 'invoice-' . auth()->id() . '-' . uniqid();
        $config = InvoiceCollector::config();
        
        // Register config for session restoration
        AutonomousCollector::registerConfig($config);
        
        $response = AutonomousCollector::start(
            $sessionId,
            $config,
            $request->input('message', '')
        );
        
        return response()->json([
            'session_id' => $sessionId,
            'message' => $response->message,
            'status' => $response->status,
            'data' => $response->collectedData,
        ]);
    }
    
    public function message(Request $request, string $sessionId)
    {
        $response = AutonomousCollector::process(
            $sessionId,
            $request->input('message')
        );
        
        return response()->json([
            'message' => $response->message,
            'status' => $response->status,
            'data' => $response->collectedData,
            'is_complete' => $response->isComplete,
            'result' => $response->result,
        ]);
    }
    
    public function confirm(string $sessionId)
    {
        $response = AutonomousCollector::confirm($sessionId);
        
        return response()->json([
            'message' => $response->message,
            'status' => $response->status,
            'result' => $response->result,
        ]);
    }
}
```

## Comparison: Traditional vs Autonomous

| Aspect | Traditional DataCollector | Autonomous Collector |
|--------|--------------------------|---------------------|
| **Configuration** | Define every field, type, validation | Define goal, tools, output schema |
| **Conversation** | Rigid field-by-field | Natural, flexible |
| **Entity Resolution** | Pre-load all data | Use tools on-demand |
| **Complex Input** | Often fails | AI parses intelligently |
| **Flexibility** | Low | High |
| **AI Control** | Limited | Full autonomy |

## Best Practices

### 1. Design Good Tools

```php
// ✅ Good: Specific, returns useful data
'find_product' => [
    'description' => 'Search products by name. Returns id, name, price, stock.',
    'handler' => fn($q) => Product::search($q)->get(['id', 'name', 'price', 'stock']),
],

// ❌ Bad: Vague, returns too much data
'get_products' => [
    'description' => 'Get products',
    'handler' => fn() => Product::all(),
],
```

### 2. Clear Output Schema

```php
// ✅ Good: Clear structure with validation
'outputSchema' => [
    'customer_id' => 'integer|required',
    'items' => [
        'type' => 'array',
        'items' => [
            'product_id' => 'integer|required',
            'quantity' => 'integer|required|min:1',
        ],
    ],
],

// ❌ Bad: Vague, no validation
'outputSchema' => [
    'customer' => 'any',
    'products' => 'array',
],
```

### 3. Helpful System Prompts

```php
'systemPromptAddition' => <<<PROMPT
Parsing rules:
- "2 X and 3 Y" = TWO products: 2 of X, 3 of Y
- Handle typos gracefully
- Always search before creating
- Calculate totals automatically
PROMPT,
```

## Troubleshooting

### AI Not Using Tools

Make sure tool descriptions are clear and specific:

```php
// ✅ Clear description
'description' => 'Search for customer by name or email. Returns matching customers with id, name, email.',

// ❌ Vague description
'description' => 'Find customer',
```

### Output Schema Validation Failing

Check that your schema matches what the AI produces:

```php
// Enable logging to see what AI produces
Log::info('AI output', ['data' => $response->collectedData]);
```

### Session Not Persisting

Configs with closures can't be fully serialized. Register configs for restoration:

```php
AutonomousCollector::registerConfig($config);
```

## Migration from Traditional DataCollector

### Before (Traditional)

```php
$config = new DataCollectorConfig(
    name: 'invoice_creator',
    fields: [
        'customer_name' => 'Customer name | required',
        'customer_email' => 'Email | required | email',
        'products' => [
            'type' => 'array',
            'description' => 'Products to add',
            // Complex parsing logic needed...
        ],
    ],
    onComplete: fn($data) => $this->createInvoice($data),
);
```

### After (Autonomous)

```php
$config = new AutonomousCollectorConfig(
    goal: 'Create a sales invoice',
    tools: [
        'find_customer' => ['handler' => fn($q) => Customer::search($q)->get()],
        'find_product' => ['handler' => fn($q) => Product::search($q)->get()],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'items' => ['type' => 'array', 'items' => [...]],
    ],
    onComplete: fn($data) => Invoice::create($data),
);
```

The AI handles all the complexity of understanding user input, searching for entities, and producing structured output.
