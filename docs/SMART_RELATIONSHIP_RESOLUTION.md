# Smart Relationship Resolution with AI Config

## Overview

When a related model has AI configuration, the system automatically uses it for smarter relationship resolution. This means better search field detection, automatic required field generation, and proper defaults when creating related records.

---

## How It Works

### 1. Intelligent Search

The system analyzes the related model's AI config to determine the best search strategy:

```php
// User model has AI config with email field
class User extends Model
{
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Full name', required: true)
            ->field('email', 'Email address', type: 'email', required: true)
            ->build();
    }
}

// When searching for user "john@example.com"
// System detects email pattern and searches email field first
// Falls back to name field if needed
```

### 2. Smart Creation

When creating related records, the system uses the AI config to:
- ✅ Detect required fields
- ✅ Apply default values
- ✅ Generate sensible values for required fields
- ✅ Handle special types (email, date, enum, etc.)

```php
// Category model with AI config
class Category extends Model
{
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Category name', required: true)
            ->field('slug', 'URL slug', required: true)
            ->enum('type', 'Category type', ['product', 'post'], default: 'product')
            ->enum('status', 'Status', ['active', 'inactive'], default: 'active')
            ->build();
    }
}

// When auto-creating category "Electronics"
// System automatically generates:
[
    'name' => 'Electronics',
    'slug' => 'electronics',        // Generated from name
    'type' => 'product',            // From default
    'status' => 'active',           // From default
    'workspace_id' => 1,            // Auto-added
    'created_by' => 1,              // Auto-added
]
```

---

## Examples

### Example 1: Customer with Email

```php
// Customer model with AI config
class User extends Model
{
    use HasAIActions, HasAIConfigBuilder;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Full name', required: true)
            ->field('email', 'Email address', type: 'email', required: true)
            ->enum('type', 'User type', ['customer', 'admin'], default: 'customer')
            ->build();
    }
}

// Invoice with auto-relationship
class Invoice extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->autoRelationship('customer_id', 'Customer', User::class)
            ->build();
    }
}

// AI Input: "Create invoice for john@example.com"
// System behavior:
// 1. Detects email pattern in value
// 2. Checks User AI config, finds email field
// 3. Searches by email first (more reliable than name)
// 4. If not found, creates with:
//    - name: "john@example.com" (from context)
//    - email: "john@example.com" (detected pattern)
//    - type: "customer" (from AI config default)
```

### Example 2: Product Category

```php
// Category with comprehensive AI config
class Category extends Model
{
    use HasAIActions, HasAIConfigBuilder;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Category name', required: true)
            ->field('slug', 'URL slug', required: true)
            ->field('description', 'Description', type: 'text')
            ->enum('type', 'Type', ['product', 'post', 'page'], default: 'product')
            ->enum('status', 'Status', ['active', 'inactive'], default: 'active')
            ->field('sort_order', 'Sort order', type: 'integer', default: 0)
            ->build();
    }
}

// Product with auto-relationship
class Product extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Product name', required: true)
            ->autoRelationship('category_id', 'Category', Category::class)
            ->build();
    }
}

// AI Input: "Create product iPhone in Electronics"
// System creates category with:
[
    'name' => 'Electronics',
    'slug' => 'electronics',        // Auto-generated
    'type' => 'product',            // From AI config default
    'status' => 'active',           // From AI config default
    'sort_order' => 0,              // From AI config default
    'workspace_id' => 1,
    'created_by' => 1,
]
```

### Example 3: Blog Post with Author

```php
// User model with AI config
class User extends Model
{
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Full name', required: true)
            ->field('email', 'Email', type: 'email', required: true)
            ->field('username', 'Username', required: true)
            ->enum('role', 'Role', ['author', 'editor', 'admin'], default: 'author')
            ->build();
    }
}

// Post with relationship
class Post extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('title', 'Post title', required: true)
            ->relationship('author_id', 'Author', User::class, searchField: 'email')
            ->build();
    }
}

// AI Input: "Create post by john.doe@blog.com"
// System:
// 1. Searches User by email (specified in relationship)
// 2. If not found, could auto-create with User's AI config
// 3. User AI config ensures all required fields are set
```

---

## Benefits

### 1. Consistent Data Quality
Related models define their own requirements, ensuring consistency:
```php
// Category always created with proper defaults
// No need to specify defaults in every relationship
```

### 2. Reduced Configuration
```php
// Before: Specify defaults in every relationship
->autoRelationship('category_id', 'Category', Category::class, defaults: [
    'type' => 'product',
    'status' => 'active',
    'sort_order' => 0,
])

// After: Category AI config handles it
->autoRelationship('category_id', 'Category', Category::class)
```

### 3. Intelligent Field Detection
```php
// System automatically detects:
// - Email patterns → searches email field
// - Phone patterns → searches phone field
// - URLs → searches url/website field
```

### 4. Type-Safe Creation
```php
// AI config ensures correct types:
// - Enums use first valid option
// - Dates use current date
// - Booleans default to false
// - Numbers default to 0
```

---

## Advanced Usage

### Custom Field Generation

Override `generateDefaultValue` for custom logic:

```php
class Product extends Model
{
    use AutoResolvesRelationships;
    
    protected static function generateDefaultValue(string $field, array $fieldConfig, string $contextValue)
    {
        // Custom slug generation
        if ($field === 'slug') {
            return Str::slug($contextValue);
        }
        
        // Custom SKU generation
        if ($field === 'sku') {
            return 'SKU-' . strtoupper(Str::random(8));
        }
        
        return parent::generateDefaultValue($field, $fieldConfig, $contextValue);
    }
}
```

### Multi-Field Search

Related model AI config can hint at multiple search fields:

```php
class User extends Model
{
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Full name')
            ->field('email', 'Email', type: 'email')
            ->field('username', 'Username')
            ->build();
    }
    
    // System will try: email (if pattern matches) → name → username
}
```

---

## Best Practices

1. **Define AI Config for Frequently Related Models**
   ```php
   // User, Category, Tag, etc. should have AI config
   ```

2. **Use Descriptive Field Types**
   ```php
   ->field('email', 'Email', type: 'email')  // Enables pattern detection
   ```

3. **Set Sensible Defaults**
   ```php
   ->enum('status', 'Status', ['active', 'inactive'], default: 'active')
   ```

4. **Mark Required Fields**
   ```php
   ->field('name', 'Name', required: true)  // Ensures generation
   ```

5. **Use Slug Fields**
   ```php
   ->field('slug', 'URL slug', required: true)  // Auto-generated from name
   ```

---

## Troubleshooting

### Related Record Not Created Properly

**Check:** Does related model have AI config with required fields?

```php
// Add AI config to related model
public function initializeAI(): array
{
    return $this->aiConfig()
        ->field('name', 'Name', required: true)
        ->build();
}
```

### Wrong Search Field Used

**Check:** Is the field type specified in AI config?

```php
// Specify type for pattern detection
->field('email', 'Email', type: 'email')
```

### Missing Default Values

**Check:** Are defaults specified in AI config?

```php
// Add defaults to related model
->enum('status', 'Status', ['active'], default: 'active')
```

---

## Comparison

### Without AI Config on Related Model

```php
// Manual defaults in every relationship
->autoRelationship('category_id', 'Category', Category::class, defaults: [
    'type' => 'product',
    'status' => 'active',
    'sort_order' => 0,
    'slug' => '???',  // How to generate?
])

// Result: Inconsistent, repetitive, error-prone
```

### With AI Config on Related Model

```php
// Category defines its own requirements
class Category extends Model
{
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->field('name', 'Name', required: true)
            ->field('slug', 'Slug', required: true)
            ->enum('type', 'Type', ['product'], default: 'product')
            ->enum('status', 'Status', ['active'], default: 'active')
            ->field('sort_order', 'Order', type: 'integer', default: 0)
            ->build();
    }
}

// Simple relationship definition
->autoRelationship('category_id', 'Category', Category::class)

// Result: Consistent, DRY, type-safe
```

---

## Summary

By leveraging AI configuration from related models, the system provides:

- ✅ **Intelligent search** - Detects best fields automatically
- ✅ **Smart creation** - Uses related model's requirements
- ✅ **Consistent data** - Defaults defined once, used everywhere
- ✅ **Less configuration** - No need to repeat defaults
- ✅ **Type safety** - Proper types and validation
- ✅ **Pattern detection** - Email, phone, URL recognition

This makes relationship resolution truly intelligent and maintainable!
