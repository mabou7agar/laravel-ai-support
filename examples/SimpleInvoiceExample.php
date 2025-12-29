<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Traits\HasAIActions;
use LaravelAIEngine\Traits\HasAIConfigBuilder;

/**
 * Simple Invoice Example - Using Fluent Builder
 * 
 * Before: 80+ lines of configuration
 * After: 15 lines with fluent builder
 */
class SimpleInvoice extends Model
{
    use HasAIActions, HasAIConfigBuilder;
    
    protected $fillable = ['customer_id', 'issue_date', 'due_date', 'status', 'notes'];
    
    /**
     * AI Configuration - Fluent Builder Approach
     * 
     * This replaces 80+ lines of manual configuration with a clean, readable builder
     */
    public function initializeAI(): array
    {
        return $this->aiConfig()
            ->description('Customer invoice with line items')
            
            // Customer field
            ->field('name', 'Customer full name', required: true)
            
            // Invoice items array
            ->arrayField('items', 'Invoice line items', [
                'item' => 'Product name',
                'price' => 'Unit price in dollars',
                'quantity' => 'Quantity (default: 1)',
                'category' => 'Product category (optional)',
            ])
            
            // Date fields
            ->date('issue_date', 'Invoice issue date', default: 'today')
            ->date('due_date', 'Payment due date')
            
            // Status enum
            ->enum('status', 'Invoice status', 
                ['Draft', 'Sent', 'Unpaid', 'Partially Paid', 'Paid'],
                default: 'Draft'
            )
            
            // Extraction hints for better AI understanding
            ->extractionHints([
                'items' => [
                    'ALWAYS use exact field name "items" (not "products" or "main_item")',
                    'ALWAYS return as array, even for single item',
                    'Each item must have: item (product name), price, quantity (optional)',
                ],
            ])
            
            ->build();
    }
    
    /**
     * Execute AI action with enhanced features
     */
    public static function executeAI(string $action, array $data)
    {
        // Your custom logic here
        // The normalizeAIData and other methods from the full example
        // can be reused or simplified based on your needs
        
        return parent::executeAI($action, $data);
    }
}

/**
 * Even Simpler: Zero Configuration Example
 * 
 * For basic CRUD, just add the trait!
 */
class Product extends Model
{
    use HasAIActions, HasSimpleAIConfig;
    
    protected $fillable = ['name', 'sku', 'price', 'description', 'category_id'];
    
    // That's it! Everything is auto-discovered:
    // - Fields from $fillable
    // - Types inferred from names (price = number, category_id = relationship)
    // - Descriptions auto-generated
    // - Relationships detected from _id suffix
}

/**
 * Hybrid Approach: Auto-Discovery + Custom Fields
 * 
 * Best of both worlds
 */
class Order extends Model
{
    use HasAIActions, HasSimpleAIConfig;
    
    protected $fillable = ['customer_id', 'total', 'status', 'shipping_address'];
    
    // Override description
    protected $aiDescription = 'Customer order with items and shipping details';
    
    // Limit actions
    protected $aiActions = ['create', 'update']; // No delete
    
    // Add custom fields that can't be auto-discovered
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
                    'price' => ['type' => 'number', 'description' => 'Unit price'],
                ],
            ],
        ];
    }
}
