<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;
use LaravelAIEngine\Traits\AutoResolvesRelationships;

/**
 * Example: Product with Automatic Relationship Resolution
 * 
 * Before: 50+ lines of manual relationship handling in executeAI
 * After: 3 lines of configuration
 */
class ProductWithAutoRelations extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    protected $fillable = ['name', 'sku', 'price', 'description', 'category_id', 'brand_id'];
    
    /**
     * AI Configuration with Automatic Relationships
     */
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Product with automatic category and brand resolution')
            
            // Basic fields
            ->field('name', 'Product name', required: true)
            ->field('price', 'Price', type: 'number', required: true)
            ->field('description', 'Product description', type: 'text')
            
            // Auto-resolving relationships
            // Creates category if not found
            ->autoRelationship('category_id', 'Product category', Category::class)
            
            // Finds brand but doesn't create
            ->relationship('brand_id', 'Product brand', Brand::class)
            
            ->build();
    }
    
    // Relationships
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }
}

/**
 * Example: Blog Post with Multiple Relationships
 */
class BlogPost extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    protected $fillable = ['title', 'content', 'excerpt', 'category_id', 'author_id', 'status'];
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Blog post with automatic relationship resolution')
            
            ->field('title', 'Post title', required: true)
            ->field('content', 'Post content', type: 'text', required: true)
            ->field('excerpt', 'Short excerpt', type: 'text')
            
            // Auto-create category with defaults
            ->autoRelationship('category_id', 'Post category', Category::class, defaults: [
                'type' => 'post',
                'status' => 'active',
            ])
            
            // Find author by email
            ->relationship('author_id', 'Post author', User::class, searchField: 'email')
            
            ->enum('status', 'Publication status', ['draft', 'published', 'archived'], default: 'draft')
            
            ->build();
    }
}

/**
 * Example: Order with Customer Relationship
 */
class Order extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    protected $fillable = ['order_number', 'customer_id', 'total', 'status', 'notes'];
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Customer order with automatic customer resolution')
            
            ->field('order_number', 'Order number')
            ->field('total', 'Order total', type: 'number')
            
            // Find customer by email (most reliable)
            ->relationship('customer_id', 'Customer', User::class, searchField: 'email', required: true)
            
            ->enum('status', 'Order status', ['pending', 'processing', 'shipped', 'delivered'])
            ->field('notes', 'Order notes', type: 'text')
            
            ->build();
    }
}

/**
 * Example: Invoice with Complex Relationships (Simplified)
 */
class SimpleInvoice extends Model
{
    use HasAIActions, HasAIConfigBuilder, AutoResolvesRelationships;
    
    protected $fillable = ['customer_id', 'issue_date', 'due_date', 'status'];
    
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Customer invoice with automatic customer resolution')
            
            // Customer relationship - auto-create if needed
            ->autoRelationship('customer_id', 'Customer', User::class, defaults: [
                'type' => 'customer',
            ])
            
            // Invoice items (handled separately in executeAI if needed)
            ->arrayField('items', 'Invoice items', [
                'item' => 'Product name',
                'price' => 'Unit price',
                'quantity' => 'Quantity',
            ])
            
            ->date('issue_date', 'Invoice issue date', default: 'today')
            ->date('due_date', 'Payment due date')
            ->enum('status', 'Invoice status', ['Draft', 'Sent', 'Paid'])
            
            ->build();
    }
    
    // No need for complex executeAI - relationships handled automatically!
}

/**
 * Usage Examples
 */

// Example 1: Product Creation
// AI Input: "Create product iPhone 15 Pro in Electronics by Apple at $999"
// Automatically resolves to:
[
    'name' => 'iPhone 15 Pro',
    'price' => 999,
    'category_id' => 5,  // Found or created "Electronics"
    'brand_id' => 3,     // Found "Apple"
]

// Example 2: Blog Post Creation
// AI Input: "Write post about Laravel by john@example.com in Tutorial category"
// Automatically resolves to:
[
    'title' => 'Laravel Guide',
    'content' => '...',
    'category_id' => 8,  // Created "Tutorial" category
    'author_id' => 12,   // Found user with email john@example.com
    'status' => 'draft',
]

// Example 3: Order Creation
// AI Input: "Create order for customer@email.com with total $150"
// Automatically resolves to:
[
    'order_number' => 'ORD-001',
    'customer_id' => 45,  // Found customer by email
    'total' => 150,
    'status' => 'pending',
]
