# AI Configuration Quick Reference

## 🚀 Quick Start (Choose Your Approach)

### Option 1: Zero Config (Fastest)
```php
use LaravelAIEngine\Traits\{HasAIActions, HasSimpleAIConfig};

class Product extends Model
{
    use HasAIActions, HasSimpleAIConfig;
    protected $fillable = ['name', 'price', 'description'];
}
```

### Option 2: Fluent Builder (Recommended)
```php
use LaravelAIEngine\Traits\{HasAIActions, HasAIConfigBuilder};

class Invoice extends Model
{
    use HasAIActions, HasAIConfigBuilder;
    
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
}
```

---

## 📋 Fluent Builder API

### Basic Fields
```php
->field('name', 'Description', type: 'string', required: false, default: null)
```

**Types:** `string`, `integer`, `number`, `boolean`, `text`, `email`, `phone`, `url`, `date`

### Array Fields
```php
->arrayField('items', 'Description', [
    'field1' => 'Description',
    'field2' => 'Description',
], required: false, minItems: 0)
```

### Relationships
```php
->relationship('category_id', 'Category', Category::class, searchField: 'name')
```

### Enums
```php
->enum('status', 'Status', ['draft', 'published'], default: 'draft')
```

### Dates
```php
->date('created_at', 'Creation date', default: 'today')
```

### Extraction Hints
```php
->extractionHints([
    'items' => ['ALWAYS use exact field name "items"'],
])
```

### Examples
```php
->examples('email', ['john@example.com', 'jane@company.com'])
```

---

## 🎯 Common Patterns

### E-commerce Order
```php
return $this->aiConfig()
    ->field('customer_email', 'Email', type: 'email', required: true)
    ->arrayField('items', 'Order items', [
        'product' => 'Product name',
        'quantity' => 'Quantity',
        'price' => 'Price',
    ])
    ->enum('status', 'Status', ['pending', 'shipped', 'delivered'])
    ->build();
```

### Blog Post
```php
return $this->aiConfig()
    ->field('title', 'Title', required: true)
    ->field('content', 'Content', type: 'text', required: true)
    ->relationship('category_id', 'Category', Category::class)
    ->enum('status', 'Status', ['draft', 'published'])
    ->build();
```

### Event Registration
```php
return $this->aiConfig()
    ->field('name', 'Attendee name', required: true)
    ->field('email', 'Email', type: 'email', required: true)
    ->date('event_date', 'Event date', required: true)
    ->enum('ticket_type', 'Ticket', ['standard', 'vip', 'early-bird'])
    ->build();
```

---

## 🔧 Auto-Discovery (HasSimpleAIConfig)

### Override Properties
```php
protected $aiDescription = 'Custom description';
protected $aiActions = ['create', 'update']; // No delete
```

### Add Custom Fields
```php
protected function customAIFields(): array
{
    return [
        'items' => [
            'type' => 'array',
            'description' => 'Items',
            'item_structure' => [...],
        ],
    ];
}
```

---

## 📊 Comparison Table

| Approach | Lines | Time | Flexibility |
|----------|-------|------|-------------|
| Zero Config | 0 | < 1 min | Low |
| Fluent Builder | 10-15 | 2-5 min | High |
| Hybrid | 15-20 | 5-10 min | Very High |
| Full Manual | 80+ | 15-30 min | Maximum |

---

## 💡 Tips

1. **Start Simple**: Use zero-config first, add complexity as needed
2. **Use Fluent Builder**: For production code (most readable)
3. **Add Hints**: For complex nested structures
4. **Provide Examples**: When field names are ambiguous
5. **Test Incrementally**: Add one field at a time

---

## 🐛 Troubleshooting

### AI uses wrong field names
```php
->extractionHints(['items' => ['ALWAYS use field name "items"']])
```

### Field not marked as required
```php
->field('email', 'Email', required: true)
```

### Complex nested structure
```php
->arrayField('addresses', 'Addresses', [
    'street' => 'Street',
    'city' => 'City',
    'zip' => 'ZIP',
])
```

---

## 📚 Full Documentation

- [Simple AI Config Guide](SIMPLE_AI_CONFIG_GUIDE.md)
- [Configuration Comparison](CONFIGURATION_COMPARISON.md)
- [Examples](../examples/SimpleInvoiceExample.php)
