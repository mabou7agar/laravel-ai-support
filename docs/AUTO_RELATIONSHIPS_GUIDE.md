# Automatic Relationship Resolution Guide

## Overview

The `AutoResolvesRelationships` trait automatically handles relationship resolution without manual code. Just configure your relationships and the system handles the rest.

---

## Quick Start

### Basic Usage

```php
use LaravelAIEngine\Traits\{HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships};

class Product extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    protected $fillable = ['name', 'price', 'category_id'];
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Product name', required: true)
            ->field('price', 'Price', type: 'number')
            ->relationship('category_id', 'Category', Category::class)
            ->build();
    }
}
```

**What happens automatically:**
```php
// Input from AI
['name' => 'iPhone 15', 'price' => 999, 'category' => 'Electronics']

// Automatically resolved to
['name' => 'iPhone 15', 'price' => 999, 'category_id' => 5]
```

---

## Features

### 1. Auto-Detection

Automatically detects relationships from:
- ✅ Fields ending with `_id`
- ✅ Relationship methods in your model
- ✅ AI configuration

```php
// No configuration needed - auto-detected!
protected $fillable = ['name', 'category_id', 'brand_id'];

public function category() {
    return $this->belongsTo(Category::class);
}
```

### 2. Semantic Search

Uses vector search for intelligent matching:
```php
// Input: "smartphone accessories"
// Finds: Category "Phone Accessories" (semantic match)
```

### 3. Auto-Creation

Optionally create related records if not found:
```php
->autoRelationship('category_id', 'Category', Category::class)
```

### 4. Custom Search Fields

Search by any field, not just `name`:
```php
->relationship('customer_id', 'Customer', User::class, searchField: 'email')
```

---

## Configuration Methods

### Basic Relationship
```php
->relationship(
    name: 'category_id',
    description: 'Product category',
    modelClass: Category::class,
    searchField: 'name',      // Default: 'name'
    required: false,          // Default: false
    createIfMissing: false,   // Default: false
    defaults: []              // Default: []
)
```

### Auto-Creating Relationship
```php
->autoRelationship(
    name: 'category_id',
    description: 'Product category',
    modelClass: Category::class,
    searchField: 'name',
    defaults: ['type' => 'product']  // Applied when creating
)
```

---

## Examples

### E-commerce Product

```php
class Product extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Product name', required: true)
            ->field('price', 'Price', type: 'number')
            
            // Find or create category
            ->autoRelationship('category_id', 'Category', Category::class)
            
            // Find brand (don't create)
            ->relationship('brand_id', 'Brand', Brand::class)
            
            ->build();
    }
}
```

**Usage:**
```php
// AI extracts: "iPhone 15 Pro in Electronics by Apple"
// Automatically resolves to:
[
    'name' => 'iPhone 15 Pro',
    'category_id' => 5,  // Found "Electronics"
    'brand_id' => 3,     // Found "Apple"
]
```

### Blog Post

```php
class Post extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('title', 'Post title', required: true)
            ->field('content', 'Post content', type: 'text')
            
            // Auto-create category if needed
            ->autoRelationship('category_id', 'Category', Category::class, defaults: [
                'type' => 'post',
                'status' => 'active',
            ])
            
            // Find author by email
            ->relationship('author_id', 'Author', User::class, searchField: 'email')
            
            ->build();
    }
}
```

### Order with Customer

```php
class Order extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('order_number', 'Order number')
            
            // Find customer by email or name
            ->relationship('customer_id', 'Customer', User::class, searchField: 'email')
            
            // Auto-create shipping address
            ->autoRelationship('shipping_address_id', 'Shipping Address', Address::class)
            
            ->build();
    }
}
```

---

## Advanced Features

### Multiple Search Fields

```php
// Try email first, fallback to name
protected static function findRelatedRecord($modelClass, $searchField, $value)
{
    // Custom logic in your model
    return $modelClass::where('email', $value)
        ->orWhere('name', 'LIKE', "%{$value}%")
        ->first();
}
```

### Custom Resolution Logic

```php
class Product extends Model
{
    use AutoResolvesRelationships;
    
    // Override for custom behavior
    protected static function autoResolveRelationships(array $data, array $config = []): array
    {
        // Custom pre-processing
        if (isset($data['category'])) {
            $data['category'] = ucfirst($data['category']);
        }
        
        // Call parent
        return parent::autoResolveRelationships($data, $config);
    }
}
```

### Nested Relationships

```php
// Automatically handles nested data
[
    'name' => 'Product',
    'category' => 'Electronics',
    'subcategory' => 'Phones',  // Resolves subcategory_id
]
```

---

## How It Works

### 1. Detection Phase
```
Scans model for:
- Fields ending with _id
- Relationship methods
- AI config relationships
```

### 2. Resolution Phase
```
For each relationship field:
1. Check if string value exists
2. Try vector search (semantic)
3. Fallback to LIKE search
4. Create if allowed and not found
5. Replace string with ID
```

### 3. Execution Phase
```
Resolved data passed to create/update
```

---

## Comparison

### Without AutoResolvesRelationships (Manual)

```php
public static function executeAI(string $action, array $data)
{
    // Manual category resolution
    if (isset($data['category'])) {
        $category = Category::where('name', 'LIKE', "%{$data['category']}%")->first();
        if (!$category) {
            $category = Category::create(['name' => $data['category']]);
        }
        $data['category_id'] = $category->id;
        unset($data['category']);
    }
    
    // Manual brand resolution
    if (isset($data['brand'])) {
        $brand = Brand::where('name', 'LIKE', "%{$data['brand']}%")->first();
        if ($brand) {
            $data['brand_id'] = $brand->id;
        }
        unset($data['brand']);
    }
    
    // ... 50+ more lines for other relationships
    
    return static::create($data);
}
```

### With AutoResolvesRelationships (Automatic)

```php
public function initializeAI(): array
{
    return $this->aiConfig()
        ->autoRelationship('category_id', 'Category', Category::class)
        ->relationship('brand_id', 'Brand', Brand::class)
        ->build();
}

// That's it! Everything handled automatically
```

**Code reduction:** 95% less code

---

## Configuration Options

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `searchField` | string | `'name'` | Field to search in related model |
| `createIfMissing` | bool | `false` | Create record if not found |
| `defaults` | array | `[]` | Default values when creating |
| `required` | bool | `false` | Fail if relationship not resolved |

---

## Best Practices

1. **Use autoRelationship** for categories, tags, and other flexible relationships
2. **Use relationship** for strict relationships like users, customers
3. **Provide defaults** when auto-creating to ensure valid records
4. **Use semantic search** for better matching (enable Vectorizable trait)
5. **Log resolution** for debugging (automatically logged)

---

## Troubleshooting

### Relationship Not Resolved

**Problem:** String value not converted to ID

**Solution:** Check search field and value
```php
->relationship('category_id', 'Category', Category::class, searchField: 'slug')
```

### Record Created When Shouldn't

**Problem:** Auto-creation happening unexpectedly

**Solution:** Use `relationship()` instead of `autoRelationship()`
```php
->relationship('brand_id', 'Brand', Brand::class) // Won't create
```

### Wrong Record Found

**Problem:** Semantic search finding wrong match

**Solution:** Use exact matching or custom search
```php
// Override in model
protected static function findRelatedRecord($modelClass, $searchField, $value)
{
    return $modelClass::where($searchField, $value)->first(); // Exact match
}
```

---

## Migration Guide

### From Manual Resolution

**Before:**
```php
public static function executeAI(string $action, array $data)
{
    // 50+ lines of manual resolution code
    if (isset($data['category'])) {
        $category = Category::where('name', $data['category'])->first();
        // ...
    }
    // ... repeat for every relationship
    
    return static::create($data);
}
```

**After:**
```php
// Add trait
use AutoResolvesRelationships;

// Configure relationships
public function initializeAI(): array
{
    return $this->aiConfig()
        ->autoRelationship('category_id', 'Category', Category::class)
        ->build();
}

// Delete executeAI method - automatic now!
```

---

## Performance

- **Vector Search:** ~50ms per relationship (if enabled)
- **DB Search:** ~10ms per relationship
- **Creation:** ~20ms per record
- **Total:** Negligible impact on overall request time

---

## Next Steps

- See [Simple AI Config Guide](SIMPLE_AI_CONFIG_GUIDE.md) for full configuration
- See [Quick Reference](QUICK_REFERENCE.md) for syntax
- See [Examples](../examples/) for complete implementations
