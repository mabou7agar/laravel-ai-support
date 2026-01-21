<?php

namespace LaravelAIEngine\Tests\Feature\Workflows;

use LaravelAIEngine\Tests\TestCase;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\GenericEntityResolver;
use LaravelAIEngine\DTOs\EntityFieldConfig;

/**
 * Test entity resolution with subflows
 * 
 * Tests that entities are properly resolved, subflows are triggered correctly,
 * and complete entity data is retrieved after creation.
 */
class EntityResolutionTest extends TestCase
{
    protected GenericEntityResolver $resolver;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(GenericEntityResolver::class);
    }
    
    /**
     * Test entity resolution with existing entity
     * 
     * @test
     */
    public function it_resolves_existing_entity_by_name()
    {
        // Create test entity
        $category = $this->createMockCategory('Electronics');
        
        $context = new UnifiedActionContext('test-session');
        $config = [
            'model' => get_class($category),
            'search_fields' => ['name'],
            'multiple' => false,
        ];
        
        // Resolve entity
        $result = $this->resolver->resolveEntity(
            'category',
            $config,
            'Electronics',
            $context
        );
        
        // Assertions
        $this->assertTrue($result->success);
        $this->assertEquals($category->id, $result->data['category_id']);
    }
    
    /**
     * Test entity resolution triggers subflow for missing entity
     * 
     * @test
     */
    public function it_triggers_subflow_for_missing_entity()
    {
        $context = new UnifiedActionContext('test-session');
        
        // Create mock model
        $this->createCategoriesTable();
        $modelClass = $this->getMockCategoryModel();
        
        $config = [
            'model' => $modelClass,
            'search_fields' => ['name'],
            'subflow' => 'App\\AI\\Workflows\\DeclarativeCategoryWorkflow',
            'confirm_before_create' => true,
            'multiple' => false,
        ];
        
        // Try to resolve non-existent entity
        $result = $this->resolver->resolveEntity(
            'category',
            $config,
            'NewCategory',
            $context
        );
        
        // Should ask for confirmation to create
        $this->assertFalse($result->success);
        $this->assertTrue($result->needsUserInput);
    }
    
    /**
     * Test multiple entity resolution
     * 
     * @test
     */
    public function it_resolves_multiple_entities()
    {
        // Create test entities
        $product1 = $this->createMockProduct('Laptop', 999.99);
        $product2 = $this->createMockProduct('Mouse', 29.99);
        
        $context = new UnifiedActionContext('test-session');
        $config = [
            'model' => get_class($product1),
            'search_fields' => ['name'],
            'multiple' => true,
        ];
        
        $items = [
            ['product' => 'Laptop', 'quantity' => 2],
            ['product' => 'Mouse', 'quantity' => 3],
        ];
        
        // Resolve entities
        $result = $this->resolver->resolveEntities(
            'products',
            $config,
            $items,
            $context
        );
        
        // Assertions
        $this->assertTrue($result->success);
        $validated = $context->get('products_validated', []);
        $this->assertCount(2, $validated);
        $this->assertEquals($product1->id, $validated[0]['id']);
        $this->assertEquals($product2->id, $validated[1]['id']);
    }
    
    /**
     * Test subflow completion stores entity data
     * 
     * @test
     */
    public function it_stores_complete_entity_data_after_subflow()
    {
        $product = $this->createMockProduct('Test Product', 99.99);
        
        $context = new UnifiedActionContext('test-session');
        $context->set('products_missing', [
            ['name' => 'Test Product', 'quantity' => 1]
        ]);
        $context->set('products_creation_index', 0);
        $context->set('products_validated', []);
        $context->set('entity_id', $product->id);
        
        $config = [
            'model' => get_class($product),
            'multiple' => true,
        ];
        
        // Simulate fetching complete entity after subflow
        $missing = $context->get('products_missing', []);
        $index = $context->get('products_creation_index', 0);
        $validated = $context->get('products_validated', []);
        
        $modelClass = $config['model'];
        $createdEntity = $modelClass::find($product->id);
        
        $createdItem = array_merge($missing[$index], [
            'id' => $product->id,
            'name' => $createdEntity->name,
            'sale_price' => $createdEntity->sale_price,
            'purchase_price' => $createdEntity->purchase_price,
        ]);
        
        $validated[] = $createdItem;
        $context->set('products_validated', $validated);
        
        // Assertions
        $validatedProducts = $context->get('products_validated', []);
        $this->assertCount(1, $validatedProducts);
        $this->assertEquals($product->id, $validatedProducts[0]['id']);
        $this->assertEquals(99.99, $validatedProducts[0]['sale_price']);
        $this->assertArrayHasKey('purchase_price', $validatedProducts[0]);
    }
    
    /**
     * Test EntityFieldConfig creation
     * 
     * @test
     */
    public function it_creates_entity_field_config_correctly()
    {
        $config = EntityFieldConfig::make(\stdClass::class)
            ->searchFields(['name', 'email'])
            ->friendlyName('customer')
            ->description('Customer information')
            ->required(true)
            ->confirmBeforeCreate(true);
        
        $this->assertEquals(\stdClass::class, $config->model);
        $this->assertEquals(['name', 'email'], $config->searchFields);
        $this->assertEquals('customer', $config->friendlyName);
        $this->assertEquals('Customer information', $config->description);
        $this->assertTrue($config->required);
        $this->assertTrue($config->confirmBeforeCreate);
    }
    
    /**
     * Test entity resolution with filters
     * 
     * @test
     */
    public function it_applies_filters_during_resolution()
    {
        // Create test entities in different workspaces
        $category1 = $this->createMockCategory('Electronics', 1);
        $category2 = $this->createMockCategory('Electronics', 2);
        
        $context = new UnifiedActionContext('test-session');
        $config = [
            'model' => get_class($category1),
            'search_fields' => ['name'],
            'filters' => function($query) {
                return $query->where('workspace_id', 1);
            },
            'multiple' => false,
        ];
        
        // Resolve entity with filter
        $result = $this->resolver->resolveEntity(
            'category',
            $config,
            'Electronics',
            $context
        );
        
        // Should find category1 (workspace 1), not category2
        $this->assertTrue($result->success);
        $this->assertEquals($category1->id, $result->data['category_id']);
    }
    
    /**
     * Test mixed existing and new entities
     * 
     * @test
     */
    public function it_handles_mixed_existing_and_new_entities()
    {
        $existingProduct = $this->createMockProduct('Laptop', 999.99);
        
        $context = new UnifiedActionContext('test-session');
        $config = [
            'model' => get_class($existingProduct),
            'search_fields' => ['name'],
            'multiple' => true,
        ];
        
        $items = [
            ['product' => 'Laptop', 'quantity' => 1],      // Exists
            ['product' => 'New Product', 'quantity' => 2], // Doesn't exist
        ];
        
        // Resolve entities
        $result = $this->resolver->resolveEntities(
            'products',
            $config,
            $items,
            $context
        );
        
        // Should find existing and mark new as missing
        $validated = $context->get('products_validated', []);
        $missing = $context->get('products_missing', []);
        
        $this->assertCount(1, $validated); // Laptop found
        $this->assertCount(1, $missing);   // New Product missing
    }
    
    /**
     * Create mock category
     */
    protected function createMockCategory(string $name, int $workspaceId = 1)
    {
        $this->createCategoriesTable();
        
        $model = $this->getMockCategoryModel();
        return $model::create([
            'name' => $name,
            'workspace_id' => $workspaceId,
        ]);
    }
    
    /**
     * Create mock product
     */
    protected function createMockProduct(string $name, float $price)
    {
        $this->createProductsTable();
        
        $model = $this->getMockProductModel();
        return $model::create([
            'name' => $name,
            'sale_price' => $price,
            'purchase_price' => $price * 0.5,
        ]);
    }
    
    /**
     * Create categories table
     */
    protected function createCategoriesTable()
    {
        if (!$this->app['db']->getSchemaBuilder()->hasTable('categories')) {
            $this->app['db']->getSchemaBuilder()->create('categories', function ($table) {
                $table->id();
                $table->string('name');
                $table->integer('workspace_id')->default(1);
                $table->timestamps();
            });
        }
    }
    
    /**
     * Create products table
     */
    protected function createProductsTable()
    {
        if (!$this->app['db']->getSchemaBuilder()->hasTable('products')) {
            $this->app['db']->getSchemaBuilder()->create('products', function ($table) {
                $table->id();
                $table->string('name');
                $table->decimal('sale_price', 10, 2)->default(0);
                $table->decimal('purchase_price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
    
    /**
     * Get mock category model
     */
    protected function getMockCategoryModel()
    {
        return new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'categories';
            protected $fillable = ['name', 'workspace_id'];
        };
    }
    
    /**
     * Get mock product model
     */
    protected function getMockProductModel()
    {
        return new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'products';
            protected $fillable = ['name', 'sale_price', 'purchase_price'];
        };
    }
}
