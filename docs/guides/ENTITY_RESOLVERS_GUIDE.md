# Entity Resolvers for User-Friendly Confirmations

## Overview

When using the Autonomous Collector, you can provide **entity resolvers** to display user-friendly information in confirmation messages instead of raw IDs.

## Problem

**Without Entity Resolvers:**
```
📋 Please Review:

🔖 Customer Id: 79
🔖 Customer User Id: 127
🔖 Invoice Id: 217
```

**With Entity Resolvers:**
```
📋 Please Review:

👤 Customer:
  • Name: Mohamed
  • Email: mohamed@example.com
  • Phone: +1234567890

👤 Customer User:
  • Name: Mohamed User
  • Email: user@example.com

📄 Invoice:
  • Invoice Number: INV-2024-001
  • Customer: Mohamed Abou Hagarz
  • Issue Date: 2026-02-03
  • Total: $500.00
  • Status: Paid
```

## Usage

### Basic Example

```php
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

$config = new AutonomousCollectorConfig(
    goal: 'Update invoice customer',
    tools: [
        'find_customer' => [
            'description' => 'Search for customer by name or email',
            'handler' => fn($query) => Customer::search($query)->get(),
        ],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'invoice_id' => 'integer|required',
    ],
    entityResolvers: [
        'customer_id' => fn($id) => [
            'Name' => Customer::find($id)?->name ?? 'N/A',
            'Email' => Customer::find($id)?->email ?? 'N/A',
            'Phone' => Customer::find($id)?->phone_no ?? 'N/A',
        ],
        'invoice_id' => fn($id) => [
            'Invoice Number' => Invoice::find($id)?->invoice_id ?? 'N/A',
            'Customer' => Invoice::find($id)?->customer?->name ?? 'N/A',
            'Issue Date' => Invoice::find($id)?->issue_date ?? 'N/A',
            'Total' => '$' . number_format(Invoice::find($id)?->getTotal() ?? 0, 2),
            'Status' => ucfirst(Invoice::find($id)?->status ?? 'N/A'),
        ],
    ],
    onComplete: function($data) {
        $invoice = Invoice::find($data['invoice_id']);
        $invoice->update(['customer_id' => $data['customer_id']]);
        return $invoice;
    },
);
```

### Advanced Example with Eager Loading

```php
$config = new AutonomousCollectorConfig(
    goal: 'Create sales order',
    tools: [
        'find_customer' => [
            'description' => 'Search for customer',
            'handler' => fn($q) => Customer::search($q)->get(),
        ],
        'find_product' => [
            'description' => 'Search for product',
            'handler' => fn($q) => Product::search($q)->get(),
        ],
    ],
    outputSchema: [
        'customer_id' => 'integer|required',
        'product_id' => 'integer|required',
        'quantity' => 'integer|required',
    ],
    entityResolvers: [
        'customer_id' => function($id) {
            $customer = Customer::with('user')->find($id);
            return $customer ? [
                'Name' => $customer->name,
                'Email' => $customer->email,
                'Phone' => $customer->phone_no,
                'Account Manager' => $customer->user?->name ?? 'N/A',
            ] : null;
        },
        'product_id' => function($id) {
            $product = Product::find($id);
            return $product ? [
                'Name' => $product->name,
                'SKU' => $product->sku,
                'Price' => '$' . number_format($product->sale_price, 2),
                'Stock' => $product->quantity . ' units',
            ] : null;
        },
    ],
);
```

## Best Practices

### 1. Cache Entity Lookups

If you're resolving the same entity multiple times, cache it:

```php
'customer_id' => function($id) {
    static $cache = [];
    
    if (!isset($cache[$id])) {
        $customer = Customer::find($id);
        $cache[$id] = $customer ? [
            'Name' => $customer->name,
            'Email' => $customer->email,
        ] : null;
    }
    
    return $cache[$id];
},
```

### 2. Handle Missing Entities Gracefully

Always return `null` or an empty array if entity not found:

```php
'invoice_id' => function($id) {
    $invoice = Invoice::find($id);
    
    if (!$invoice) {
        return null; // Or return ['Error' => 'Invoice not found']
    }
    
    return [
        'Invoice Number' => $invoice->invoice_id,
        'Total' => '$' . number_format($invoice->getTotal(), 2),
    ];
},
```

### 3. Use Eager Loading

Prevent N+1 queries by eager loading relationships:

```php
'invoice_id' => function($id) {
    $invoice = Invoice::with(['customer', 'items'])->find($id);
    
    return $invoice ? [
        'Invoice Number' => $invoice->invoice_id,
        'Customer' => $invoice->customer->name,
        'Items Count' => $invoice->items->count(),
        'Total' => '$' . number_format($invoice->getTotal(), 2),
    ] : null;
},
```

### 4. Format Values Consistently

Use consistent formatting for currency, dates, etc.:

```php
'order_id' => function($id) {
    $order = Order::find($id);
    
    return $order ? [
        'Order Number' => $order->order_number,
        'Date' => $order->created_at->format('Y-m-d'),
        'Total' => '$' . number_format($order->total, 2),
        'Status' => ucfirst($order->status),
    ] : null;
},
```

## Complete Example: Update Invoice Customer

```php
use LaravelAIEngine\Facades\AutonomousCollector;
use LaravelAIEngine\DTOs\AutonomousCollectorConfig;

// Configure the collector
$config = new AutonomousCollectorConfig(
    goal: 'Update invoice customer',
    description: 'Change the customer associated with an invoice',
    
    tools: [
        'find_customer' => [
            'description' => 'Search for customer by name or email. Returns list of matching customers.',
            'handler' => function($query) {
                return Customer::where('name', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->get()
                    ->map(fn($c) => [
                        'id' => $c->id,
                        'name' => $c->name,
                        'email' => $c->email,
                    ])
                    ->toArray();
            },
        ],
    ],
    
    outputSchema: [
        'customer_id' => 'integer|required',
        'invoice_id' => 'integer|required',
    ],
    
    entityResolvers: [
        'customer_id' => function($id) {
            $customer = Customer::find($id);
            return $customer ? [
                'Name' => $customer->name,
                'Email' => $customer->email,
                'Phone' => $customer->phone_no ?? 'N/A',
                'Company' => $customer->company ?? 'N/A',
            ] : null;
        },
        
        'customer_user_id' => function($id) {
            $user = User::find($id);
            return $user ? [
                'Name' => $user->name,
                'Email' => $user->email,
                'Role' => $user->type ?? 'N/A',
            ] : null;
        },
        
        'invoice_id' => function($id) {
            $invoice = Invoice::with('customer')->find($id);
            return $invoice ? [
                'Invoice Number' => $invoice->invoice_id,
                'Current Customer' => $invoice->customer->name,
                'Issue Date' => $invoice->issue_date,
                'Due Date' => $invoice->due_date,
                'Total' => '$' . number_format($invoice->getTotal(), 2),
                'Status' => ucfirst($invoice->status),
            ] : null;
        },
    ],
    
    onComplete: function($data) {
        $invoice = Invoice::find($data['invoice_id']);
        $invoice->update([
            'customer_id' => $data['customer_id'],
        ]);
        
        return [
            'success' => true,
            'message' => 'Invoice customer updated successfully',
            'invoice' => $invoice,
        ];
    },
    
    confirmBeforeComplete: true,
);

// Start the collection
$response = AutonomousCollector::startCollection('session-123', $config);
```

## Testing

Test your entity resolvers independently:

```php
$config = new AutonomousCollectorConfig(
    goal: 'Test',
    entityResolvers: [
        'customer_id' => fn($id) => [
            'Name' => Customer::find($id)?->name,
        ],
    ],
);

// Test the resolver
$resolver = $config->entityResolvers['customer_id'];
$result = $resolver(79);

// Should return: ['Name' => 'Mohamed']
```

## Troubleshooting

### Resolver Not Called

Make sure the field name ends with `_id`:
- ✅ `customer_id` - Will be resolved
- ❌ `customer` - Won't be resolved

### Null Values

If resolver returns `null`, the field will show as raw ID:
```php
// Bad - returns null on error
'customer_id' => fn($id) => Customer::find($id)?->name,

// Good - returns array or null
'customer_id' => function($id) {
    $customer = Customer::find($id);
    return $customer ? ['Name' => $customer->name] : null;
},
```

### Performance Issues

Use eager loading and caching:
```php
'invoice_id' => function($id) {
    static $cache = [];
    
    if (!isset($cache[$id])) {
        $cache[$id] = Invoice::with(['customer', 'items'])
            ->find($id)
            ?->toArray();
    }
    
    return $cache[$id];
},
```

## See Also

- [Autonomous Collector Guide](AUTONOMOUS_COLLECTOR_GUIDE.md)
- [Data Collector Chat Guide](DATA_COLLECTOR_CHAT_GUIDE.md)
