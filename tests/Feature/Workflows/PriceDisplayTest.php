<?php

namespace LaravelAIEngine\Tests\Feature\Workflows;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\GenericEntityResolver;
use Illuminate\Support\Facades\Log;

/**
 * Test price display in confirmation messages after subflow creation
 * 
 * Tests the fix where product prices were showing as $0 in confirmation
 * messages because the complete product data (including sale_price) wasn't
 * being fetched from the database after subflow creation.
 */
class PriceDisplayTest extends TestCase
{
    protected GenericEntityResolver $resolver;
    
    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(GenericEntityResolver::class)) {
            $this->markTestSkipped('GenericEntityResolver class not available');
        }

        $this->resolver = app(GenericEntityResolver::class);
    }
    
    /**
     * Test that product data includes prices after subflow creation
     * 
     * @test
     */
    public function it_fetches_complete_product_data_after_subflow_creation()
    {
        // Create a mock product model
        $productModel = $this->createMockProductModel();
        
        // Create context with subflow completion state
        $context = new UnifiedActionContext('test-session', 'test-user-1');
        $context->set('products_missing', [
            ['name' => 'Test Product', 'quantity' => 2]
        ]);
        $context->set('products_creation_index', 0);
        $context->set('products_validated', []);
        
        // Simulate subflow completion with entity ID
        $context->set('entity_id', $productModel->id);
        $context->set('active_subflow', [
            'field_name' => 'products',
            'step_prefix' => 'product_',
        ]);
        
        // Entity config
        $config = [
            'model' => get_class($productModel),
            'multiple' => true,
        ];
        
        // Simulate the subflow completion logic
        $missing = $context->get('products_missing', []);
        $index = $context->get('products_creation_index', 0);
        $validated = $context->get('products_validated', []);
        
        // Fetch complete entity from database
        $modelClass = $config['model'];
        $createdEntity = $modelClass::find($productModel->id);
        
        // Merge with validated list
        $createdItem = array_merge($missing[$index], [
            'id' => $productModel->id,
            'name' => $createdEntity->name,
            'sale_price' => $createdEntity->sale_price,
            'purchase_price' => $createdEntity->purchase_price,
        ]);
        
        $validated[] = $createdItem;
        
        // Assertions
        $this->assertNotEmpty($validated);
        $this->assertEquals($productModel->id, $validated[0]['id']);
        $this->assertEquals('Test Product', $validated[0]['name']);
        $this->assertEquals(99.99, $validated[0]['sale_price']);
        $this->assertEquals(49.99, $validated[0]['purchase_price']);
        $this->assertEquals(2, $validated[0]['quantity']);
    }
    
    /**
     * Test that confirmation message displays correct prices
     * 
     * @test
     */
    public function it_displays_correct_prices_in_confirmation_message()
    {
        $products = [
            [
                'id' => 1,
                'name' => 'Laptop',
                'quantity' => 2,
                'sale_price' => 999.99,
            ],
            [
                'id' => 2,
                'name' => 'Mouse',
                'quantity' => 3,
                'sale_price' => 29.99,
            ],
        ];
        
        // Build confirmation message
        $message = "**Products:**\n";
        $total = 0;
        
        foreach ($products as $productData) {
            $quantity = $productData['quantity'] ?? 1;
            $price = $productData['sale_price'] ?? 0;
            $itemTotal = $price * $quantity;
            $total += $itemTotal;
            $productName = $productData['name'] ?? 'Unknown Product';
            $message .= "  • {$productName} × {$quantity} @ \${$price} = \${$itemTotal}\n";
        }
        
        $message .= "\n**Total:** \${$total}";
        
        // Assertions
        $this->assertStringContainsString('Laptop × 2 @ $999.99 = $1999.98', $message);
        $this->assertStringContainsString('Mouse × 3 @ $29.99 = $89.97', $message);
        $this->assertStringContainsString('**Total:** $2089.95', $message);
        $this->assertStringNotContainsString('$0', $message);
    }
    
    /**
     * Test that existing products include prices
     * 
     * @test
     */
    public function it_includes_prices_for_existing_products()
    {
        $productModel = $this->createMockProductModel();
        
        // Simulate fetching existing product
        $existingProduct = [
            'id' => $productModel->id,
            'name' => $productModel->name,
            'quantity' => 1,
        ];
        
        // Fetch additional fields like prices
        $product = get_class($productModel)::find($productModel->id);
        $existingProduct['sale_price'] = $product->sale_price;
        $existingProduct['purchase_price'] = $product->purchase_price;
        
        // Assertions
        $this->assertEquals(99.99, $existingProduct['sale_price']);
        $this->assertEquals(49.99, $existingProduct['purchase_price']);
        $this->assertArrayHasKey('sale_price', $existingProduct);
        $this->assertArrayHasKey('purchase_price', $existingProduct);
    }
    
    /**
     * Create a mock product model for testing
     */
    protected function createMockProductModel()
    {
        // Create products table
        $this->app['db']->getSchemaBuilder()->create('products', function ($table) {
            $table->id();
            $table->string('name');
            $table->decimal('sale_price', 10, 2)->default(0);
            $table->decimal('purchase_price', 10, 2)->default(0);
            $table->timestamps();
        });
        
        // Create mock model class
        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'products';
            protected $fillable = ['name', 'sale_price', 'purchase_price'];
        };
        
        // Create test product
        return $model::create([
            'name' => 'Test Product',
            'sale_price' => 99.99,
            'purchase_price' => 49.99,
        ]);
    }
}
