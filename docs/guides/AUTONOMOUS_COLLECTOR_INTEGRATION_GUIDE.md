# Autonomous Collector Integration Guide

This guide explains how to create and integrate autonomous collectors into your Laravel application using the AI Engine package.

## Overview

Autonomous Collectors give AI full autonomy to collect data through natural conversation instead of rigid field-by-field forms. You define:
- **Goal** - What you want to achieve
- **Tools** - How AI can look up/create entities
- **Output Schema** - The JSON structure you expect

The AI handles the conversation naturally and produces structured output.

## Quick Start

### Option 1: Auto-Discovery (Recommended)

Create a config class implementing `DiscoverableAutonomousCollector`:

```php
<?php

namespace App\AI\Configs;

use LaravelAIEngine\Contracts\DiscoverableAutonomousCollector;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

class InvoiceAutonomousConfig implements DiscoverableAutonomousCollector
{
    public static function getName(): string
    {
        return 'invoice';
    }

    public static function getDescription(): string
    {
        return 'Create a sales invoice with customer and products';
    }

    public static function getPriority(): int
    {
        return 10; // Higher = checked first
    }

    public static function getConfig(): AutonomousCollectorConfig
    {
        return new AutonomousCollectorConfig(
            goal: 'Create a sales invoice',
            
            description: 'Help the user create a sales invoice by identifying the customer and adding products.',
            
            tools: [
                'find_customer' => [
                    'description' => 'Search for customer by name or email',
                    'parameters' => ['query' => 'required|string'],
                    'handler' => function ($query) {
                        $customers = Customer::where('name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%")
                            ->limit(5)
                            ->get(['id', 'name', 'email']);
                        
                        if ($customers->isEmpty()) {
                            return ['found' => false, 'message' => 'Customer not found. Ask user if they want to create.'];
                        }
                        
                        return ['found' => true, 'customers' => $customers->toArray()];
                    },
                ],
                
                'create_customer' => [
                    'description' => 'Create a new customer. ONLY call after user confirms.',
                    'parameters' => [
                        'name' => 'required|string',
                        'email' => 'required|email',
                    ],
                    'handler' => function ($data) {
                        $customer = Customer::create($data);
                        return ['created' => true, 'id' => $customer->id, 'name' => $customer->name];
                    },
                ],
                
                'find_product' => [
                    'description' => 'Search for products by name',
                    'parameters' => ['query' => 'required|string'],
                    'handler' => function ($query) {
                        $products = Product::where('name', 'like', "%{$query}%")
                            ->limit(5)
                            ->get(['id', 'name', 'price']);
                        
                        if ($products->isEmpty()) {
                            return ['found' => false, 'message' => 'Product not found. Ask user if they want to create.'];
                        }
                        
                        return ['found' => true, 'products' => $products->toArray()];
                    },
                ],
                
                'create_product' => [
                    'description' => 'Create a new product. ONLY call after user confirms.',
                    'parameters' => [
                        'name' => 'required|string',
                        'price' => 'required|numeric',
                    ],
                    'handler' => function ($data) {
                        $product = Product::create($data);
                        return ['created' => true, 'id' => $product->id, 'name' => $product->name, 'price' => $product->price];
                    },
                ],
            ],
            
            outputSchema: [
                'customer_id' => 'integer|required',
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'product_id' => 'integer|required',
                        'name' => 'string|required',
                        'quantity' => 'integer|required|min:1',
                        'unit_price' => 'numeric|required',
                        'total' => 'numeric|required',
                    ],
                ],
                'subtotal' => 'numeric|required',
                'total' => 'numeric|required',
            ],
            
            onComplete: function ($data) {
                // Create the invoice
                $invoice = Invoice::create([
                    'customer_id' => $data['customer_id'],
                    'subtotal' => $data['subtotal'],
                    'total' => $data['total'],
                ]);
                
                // Add items
                foreach ($data['items'] as $item) {
                    $invoice->items()->create($item);
                }
                
                return [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'customer' => $invoice->customer->name,
                    'total' => $invoice->total,
                ];
            },
            
            confirmBeforeComplete: true,
            
            name: 'invoice', // Must match getName()
        );
    }
}
```

Place this file in `app/AI/Configs/` or `app/AI/Collectors/` - it will be auto-discovered!

### Option 2: Manual Registration

Register in a service provider:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;

class AutonomousCollectorServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AutonomousCollectorRegistry::register('invoice', [
            'config' => fn() => InvoiceAutonomousConfig::getConfig(),
            'description' => 'Create a sales invoice with customer and products',
        ]);
    }
}
```

## Configuration Options

### AutonomousCollectorConfig Properties

| Property | Type | Description |
|----------|------|-------------|
| `goal` | string | What the collector should achieve |
| `description` | string | Detailed context for the AI |
| `tools` | array | Tools AI can use (see below) |
| `outputSchema` | array | Expected JSON structure |
| `onComplete` | Closure | Callback when collection is complete |
| `confirmBeforeComplete` | bool | Ask user to confirm before completing |
| `systemPromptAddition` | string | Additional instructions for AI |
| `maxTurns` | int | Maximum conversation turns (default: 20) |
| `name` | string | Unique identifier for this config |

### Tool Definition

Each tool has:

```php
'tool_name' => [
    'description' => 'What this tool does',
    'parameters' => [
        'param1' => 'required|string',
        'param2' => 'nullable|integer',
    ],
    'handler' => function ($data) {
        // Execute the tool and return result
        return ['success' => true, 'data' => $result];
    },
],
```

### Tool Patterns

**Search Tool (returns found/not_found):**
```php
'find_customer' => [
    'description' => 'Search customer. Returns not_found if no match.',
    'handler' => function ($query) {
        $found = Customer::search($query)->get();
        if ($found->isEmpty()) {
            return ['found' => false, 'message' => 'Ask user to confirm creation'];
        }
        return ['found' => true, 'customers' => $found->toArray()];
    },
],
```

**Create Tool (requires confirmation):**
```php
'create_customer' => [
    'description' => 'Create customer. ONLY call after user confirms.',
    'handler' => function ($data) {
        $customer = Customer::create($data);
        return ['created' => true, 'id' => $customer->id, 'name' => $customer->name];
    },
],
```

## Node Routing Integration

When using multi-node architecture, autonomous collectors are automatically advertised to the master node.

### How It Works

1. **Child node** registers autonomous collectors
2. **Health endpoint** includes collectors in response
3. **Master node** syncs collectors during ping
4. **AI routing** considers collectors when routing messages

### Node Configuration

The `AINode` model has an `autonomous_collectors` field:

```php
$node->autonomous_collectors = [
    [
        'name' => 'invoice',
        'goal' => 'Create a sales invoice',
        'description' => 'Create invoices with customer and products',
    ],
];
```

This is automatically synced when the master pings the child node.

## Config File Options

In `config/ai-engine.php`:

```php
'autonomous_collector' => [
    // Enable auto-discovery
    'auto_discovery' => true,

    // Additional directories to scan
    'discovery_paths' => [
        // app_path('Custom/Collectors'),
    ],

    // Custom AI detection prompt (optional)
    'detection_prompt' => null,
],
```

## Example Conversation Flow

```
User: "create invoice for Mohamed with 2 macbooks"

AI: Found multiple customers named Mohamed. Which one?
    1. Mohamed Abou Hagar - m.abou7agar@gmail.com
    2. Mohamed Ali - mali@example.com

User: "1"

AI: Found multiple MacBook products. Which one?
    1. MacBookPro - $2500
    2. MacBook Air - $1200

User: "1"

AI: Summary:
    - Customer: Mohamed Abou Hagar
    - Items: MacBookPro × 2 @ $2500 = $5000
    - Total: $5000
    
    Shall I proceed? (yes/no)

User: "no, change the price to 2000"

AI: Updated summary:
    - Customer: Mohamed Abou Hagar
    - Items: MacBookPro × 2 @ $2000 = $4000
    - Total: $4000
    
    Shall I proceed? (yes/no)

User: "yes"

AI: ✅ Invoice Created Successfully!
    Invoice #: #INV001
    Customer: Mohamed Abou Hagar
    Items: MacBookPro × 2 @ $2000 = $4000
    Total: $4000
```

## Best Practices

### 1. Tool Descriptions
Be explicit about when tools should be called:
```php
'description' => 'Create customer. ONLY call after user explicitly confirms.',
```

### 2. Confirmation Pattern
Always use find/create pairs:
- `find_customer` → returns not_found if missing
- `create_customer` → only called after user confirms

### 3. System Prompt Addition
Add explicit rules:
```php
systemPromptAddition: <<<PROMPT
CONFIRMATION REQUIRED:
- NEVER call create_* tools without user saying "yes", "sure", "create it"
- Always ask for confirmation before creating new entities
PROMPT,
```

### 4. Output Schema
Define clear structure for AI to produce:
```php
outputSchema: [
    'customer_id' => 'integer|required',
    'items' => [
        'type' => 'array',
        'items' => [
            'product_id' => 'integer|required',
            'quantity' => 'integer|required|min:1',
        ],
    ],
],
```

### 5. onComplete Callback
Return useful data for the success message:
```php
onComplete: function ($data) {
    $invoice = Invoice::create($data);
    return [
        'invoice_number' => $invoice->number,
        'customer' => $invoice->customer->name,
        'total' => $invoice->total,
    ];
},
```

## Troubleshooting

### Collector Not Found
- Ensure config `name` matches registry key
- Check if auto-discovery is enabled
- Verify file is in `app/AI/Configs/` or `app/AI/Collectors/`

### Tools Not Being Called
- Check tool descriptions are clear
- Verify handler returns proper structure
- Check logs: `Log::channel('ai-engine')`

### Node Routing Issues
- Ensure node is healthy (ping within 10 minutes)
- Check `autonomous_collectors` field is populated
- Verify health endpoint returns collectors

## API Reference

### AutonomousCollectorRegistry

```php
// Register a collector
AutonomousCollectorRegistry::register('name', [
    'config' => fn() => $config,
    'description' => 'Description',
]);

// Get all configs
AutonomousCollectorRegistry::getConfigs();

// Get specific config
AutonomousCollectorRegistry::getConfig('name');

// Find config for message (AI-driven)
AutonomousCollectorRegistry::findConfigForMessage('create invoice...');

// Get goals for node advertisement
AutonomousCollectorRegistry::getCollectorGoals();
```

### DiscoverableAutonomousCollector Interface

```php
interface DiscoverableAutonomousCollector
{
    public static function getName(): string;
    public static function getConfig(): AutonomousCollectorConfig;
    public static function getDescription(): string;
    public static function getPriority(): int;
}
```
