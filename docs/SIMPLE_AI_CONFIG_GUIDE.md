# Simple AI Configuration Guide

This guide shows you how to make your models AI-enabled with minimal configuration using convention-based auto-discovery and fluent builders.

## Table of Contents
1. [Quick Start (Zero Config)](#quick-start-zero-config)
2. [Fluent Builder (Recommended)](#fluent-builder-recommended)
3. [Auto-Discovery with Custom Fields](#auto-discovery-with-custom-fields)
4. [Full Manual Configuration](#full-manual-configuration)
5. [Comparison](#comparison)

---

## Quick Start (Zero Config)

For simple models, just add the trait and you're done!

```php
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasSimpleAIConfig;

class Product extends Model
{
    use HasAIActions, HasSimpleAIConfig;
    
    protected $fillable = ['name', 'price', 'description', 'category_id'];
    
    // That's it! AI configuration is auto-generated from fillable fields
}
```

**What you get automatically:**
- ✅ All fillable fields discovered
- ✅ Field types inferred from names and casts
- ✅ Relationships detected from `_id` fields
- ✅ Required fields inferred from common patterns
- ✅ Sensible descriptions generated

---

## Fluent Builder (Recommended)

For more control with minimal code, use the fluent builder:

```php
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;

class Invoice extends Model
{
    use HasAIActions, HasAIConfigBuilder;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Customer invoice with line items')
            ->field('name', 'Customer full name', required: true)
            ->arrayField('items', 'Invoice line items', [
                'item' => 'Product name',
                'price' => 'Unit price',
                'quantity' => 'Quantity',
            ])
            ->date('issue_date', 'Invoice issue date', default: 'today')
            ->enum('status', 'Invoice status', ['Draft', 'Sent', 'Paid'])
            ->build();
    }
}
```

**Benefits:**
- ✅ Fluent, readable syntax
- ✅ Type inference from field names
- ✅ Only configure what matters
- ✅ ~10 lines instead of ~80 lines

---

## Auto-Discovery with Custom Fields

Mix auto-discovery with custom configurations:

```php
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasSimpleAIConfig;

class Order extends Model
{
    use HasAIActions, HasSimpleAIConfig;
    
    protected $fillable = ['customer_id', 'total', 'status', 'notes'];
    
    // Override only what you need
    protected $aiDescription = 'Customer order with items and shipping';
    
    protected $aiActions = ['create', 'update']; // No delete
    
    // Add custom field configurations
    protected function customAIFields(): array
    {
        return [
            'items' => [
                'type' => 'array',
                'description' => 'Order items',
                'required' => true,
                'item_structure' => [
                    'product_id' => ['type' => 'integer', 'description' => 'Product ID'],
                    'quantity' => ['type' => 'integer', 'description' => 'Quantity'],
                ],
            ],
        ];
    }
}
```

---

## Full Manual Configuration

For complete control (original approach):

```php
use LaravelAIEngine\Traits\HasAIActions;

class Invoice extends Model
{
    use HasAIActions;
    
    public function initializeAI(): array
    {
        return [
            'model_name' => 'Invoice',
            'description' => 'Customer invoice with line items',
            'actions' => ['create', 'update', 'delete'],
            'fields' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Customer full name',
                    'required' => true,
                    'examples' => ['John Doe', 'Sarah Johnson'],
                ],
                'items' => [
                    'type' => 'array',
                    'description' => 'Invoice line items',
                    'required' => true,
                    'item_structure' => [
                        'item' => [
                            'type' => 'string',
                            'description' => 'Product name',
                            'required' => true,
                        ],
                        'price' => [
                            'type' => 'number',
                            'description' => 'Unit price',
                            'required' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

---

## Comparison

| Approach | Lines of Code | Flexibility | Best For |
|----------|---------------|-------------|----------|
| **Zero Config** | 0 | Low | Simple CRUD models |
| **Fluent Builder** | ~10 | Medium | Most use cases |
| **Auto-Discovery + Custom** | ~15 | Medium-High | Complex models with some custom needs |
| **Full Manual** | ~80 | High | Very specific requirements |

---

## Advanced Features

### Extraction Hints

Guide the AI on how to extract data:

```php
return $this->aiConfig()
    ->description('Customer invoice')
    ->extractionHints([
        'items' => [
            'ALWAYS use this exact field name "items"',
            'ALWAYS return as array, even for single item',
        ],
    ])
    ->arrayField('items', 'Invoice items', [...])
    ->build();
```

### Field Examples

Add examples to improve AI extraction:

```php
return $this->aiConfig()
    ->field('email', 'Customer email', type: 'email', required: true)
    ->examples('email', ['john@example.com', 'sarah@company.com'])
    ->build();
```

### Relationships

Define relationships easily:

```php
return $this->aiConfig()
    ->relationship('category_id', 'Product category', Category::class)
    ->relationship('customer_id', 'Customer', User::class, searchField: 'email')
    ->build();
```

---

## Migration Guide

### From Full Manual to Fluent Builder

**Before (80 lines):**
```php
public function initializeAI(): array
{
    return [
        'model_name' => 'Invoice',
        'description' => 'Customer invoice',
        'fields' => [
            'name' => [
                'type' => 'string',
                'description' => 'Customer name',
                'required' => true,
            ],
            'items' => [
                'type' => 'array',
                'description' => 'Items',
                'item_structure' => [
                    'item' => ['type' => 'string', 'description' => 'Product'],
                    'price' => ['type' => 'number', 'description' => 'Price'],
                ],
            ],
        ],
    ];
}
```

**After (10 lines):**
```php
public function initializeAI(): array
{
    return $this->aiConfig()
        ->description('Customer invoice')
        ->field('name', 'Customer name', required: true)
        ->arrayField('items', 'Items', [
            'item' => 'Product name',
            'price' => 'Unit price',
        ])
        ->build();
}
```

---

## Best Practices

1. **Start Simple**: Use zero-config or fluent builder first
2. **Add Only What's Needed**: Don't over-configure
3. **Use Extraction Hints**: For complex nested structures
4. **Provide Examples**: When field names are ambiguous
5. **Test Incrementally**: Add one field at a time

---

## Common Patterns

### E-commerce Order
```php
return $this->aiConfig()
    ->description('Customer order')
    ->field('customer_email', 'Customer email', type: 'email', required: true)
    ->arrayField('items', 'Order items', [
        'product' => 'Product name',
        'quantity' => 'Quantity',
        'price' => 'Unit price',
    ])
    ->enum('status', 'Order status', ['pending', 'processing', 'shipped', 'delivered'])
    ->build();
```

### Blog Post
```php
return $this->aiConfig()
    ->description('Blog post article')
    ->field('title', 'Post title', required: true)
    ->field('content', 'Post content', type: 'text', required: true)
    ->field('excerpt', 'Short excerpt', type: 'text')
    ->relationship('category_id', 'Post category', Category::class)
    ->enum('status', 'Publication status', ['draft', 'published', 'archived'])
    ->build();
```

### Event Registration
```php
return $this->aiConfig()
    ->description('Event registration')
    ->field('name', 'Attendee name', required: true)
    ->field('email', 'Email address', type: 'email', required: true)
    ->date('event_date', 'Event date', required: true)
    ->field('ticket_type', 'Ticket type', required: true)
    ->field('dietary_requirements', 'Dietary requirements', type: 'text')
    ->build();
```

---

## Troubleshooting

### AI Extracts Wrong Field Names

**Problem**: AI uses `product_name` instead of `item`

**Solution**: Add extraction hints
```php
->extractionHints([
    'items' => ['ALWAYS use field name "item" for product name'],
])
```

### Required Field Not Detected

**Problem**: Auto-discovery doesn't mark field as required

**Solution**: Use fluent builder
```php
->field('email', 'Email address', required: true)
```

### Complex Nested Structure

**Problem**: Auto-discovery can't handle nested arrays

**Solution**: Use `arrayField()` with structure
```php
->arrayField('addresses', 'Shipping addresses', [
    'street' => 'Street address',
    'city' => 'City',
    'zip' => 'ZIP code',
])
```

---

## Next Steps

- See [AI Actions Guide](AI_ACTIONS_GUIDE.md) for execution details
- See [Vector Search Guide](VECTOR_SEARCH_GUIDE.md) for semantic search
- See [Examples](../examples/) for complete implementations
