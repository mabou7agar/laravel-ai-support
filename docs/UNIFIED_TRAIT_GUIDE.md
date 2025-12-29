# Unified AI Trait Guide

## Overview

The `HasAIFeatures` trait combines all AI functionality into a single, easy-to-use trait. Instead of adding 3-4 separate traits, just add one.

---

## Quick Start

### Before (Multiple Traits)
```php
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;
use LaravelAIEngine\Traits\AutoResolvesRelationships;

class Invoice extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
}
```

### After (Unified Trait)
```php
use LaravelAIEngine\Traits\HasAIFeatures;

class Invoice extends Model
{
    use HasAIFeatures;
}
```

**Result:** Same functionality, cleaner code!

---

## What's Included

The `HasAIFeatures` trait includes:

1. **HasAIActions** - AI action execution
2. **HasAIConfigBuilder** - Fluent configuration API
3. **AutoResolvesRelationships** - Automatic relationship resolution

---

## Complete Example

```php
use LaravelAIEngine\Traits\HasAIFeatures;

class Invoice extends Model
{
    use HasAIFeatures;
    
    protected $fillable = ['customer_id', 'issue_date', 'status'];
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Customer invoice')
            ->autoRelationship('customer_id', 'Customer', User::class)
            ->arrayField('items', 'Items', [
                'item' => 'Product name',
                'price' => 'Unit price',
            ])
            ->date('issue_date', 'Issue date', default: 'today')
            ->enum('status', 'Status', ['Draft', 'Sent', 'Paid'])
            ->build();
    }
    
    // Optional: Override executeAI for custom logic
    public static function executeAI(string $action, array $data)
    {
        // Custom pre-processing
        $data = static::normalizeData($data);
        
        // Auto-resolve relationships (from AutoResolvesRelationships)
        $data = static::autoResolveRelationships($data);
        
        // Your custom logic here
        
        return static::create($data);
    }
}
```

---

## Benefits

### 1. Cleaner Imports
```php
// Before: 3-4 use statements
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;
use LaravelAIEngine\Traits\AutoResolvesRelationships;

// After: 1 use statement
use LaravelAIEngine\Traits\HasAIFeatures;
```

### 2. Simpler Class Definition
```php
// Before: Multiple traits in use statement
class Invoice extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
}

// After: Single trait
class Invoice extends Model
{
    use HasAIFeatures;
}
```

### 3. No Functionality Lost
All features still available:
- ✅ Fluent configuration builder
- ✅ Automatic relationship resolution
- ✅ AI action execution
- ✅ Smart field detection
- ✅ Vector search integration

---

## Migration Guide

### Step 1: Replace Trait Imports
```php
// Remove these
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;
use LaravelAIEngine\Traits\AutoResolvesRelationships;

// Add this
use LaravelAIEngine\Traits\HasAIFeatures;
```

### Step 2: Update Class
```php
// Replace
use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;

// With
use HasAIFeatures;
```

### Step 3: Test
Everything should work exactly the same!

---

## Advanced Usage

### Custom executeAI with Automatic Features

```php
public static function executeAI(string $action, array $data)
{
    \Log::info('Custom executeAI', ['action' => $action]);
    
    // Normalize data (custom method)
    $data = static::normalizeAIData($data);
    
    // Auto-resolve relationships (from HasAIFeatures)
    $data = static::autoResolveRelationships($data);
    
    // Extract nested data
    $items = $data['items'] ?? [];
    unset($data['items']);
    
    // Create main record
    $model = static::create($data);
    
    // Create related records
    foreach ($items as $item) {
        $model->items()->create($item);
    }
    
    return [
        'success' => true,
        'data' => $model->fresh(['items']),
    ];
}
```

---

## Comparison

| Aspect | Multiple Traits | Unified Trait |
|--------|----------------|---------------|
| **Import Lines** | 3-4 | 1 |
| **Use Statement** | Long | Short |
| **Functionality** | Full | Full |
| **Maintainability** | Good | Better |
| **Readability** | Good | Better |

---

## Real-World Examples

### E-commerce Product
```php
class Product extends Model
{
    use HasAIFeatures;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Product name', required: true)
            ->autoRelationship('category_id', 'Category', Category::class)
            ->relationship('brand_id', 'Brand', Brand::class)
            ->build();
    }
}
```

### Blog Post
```php
class Post extends Model
{
    use HasAIFeatures;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('title', 'Post title', required: true)
            ->autoRelationship('category_id', 'Category', Category::class)
            ->relationship('author_id', 'Author', User::class, searchField: 'email')
            ->build();
    }
}
```

### Customer Order
```php
class Order extends Model
{
    use HasAIFeatures;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->autoRelationship('customer_id', 'Customer', User::class)
            ->arrayField('items', 'Order items', [
                'product' => 'Product name',
                'quantity' => 'Quantity',
            ])
            ->enum('status', 'Status', ['pending', 'shipped'])
            ->build();
    }
}
```

---

## Summary

The `HasAIFeatures` trait provides:

- ✅ **Single import** instead of 3-4
- ✅ **Cleaner code** with less boilerplate
- ✅ **Full functionality** - nothing lost
- ✅ **Easy migration** - drop-in replacement
- ✅ **Better maintainability** - one trait to manage

Perfect for keeping your models clean and focused! 🎉
