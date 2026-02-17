<?php

namespace LaravelAIEngine\Tests\Feature\Actions;

use LaravelAIEngine\Tests\Support\ActionTestCase;
use LaravelAIEngine\Tests\Support\ActionFactory;

/**
 * Example: Action Registry Tests
 */
class ActionRegistryTest extends ActionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear registry for clean tests
        $this->actionRegistry->clearCache();
    }
    
    /**
     * Test action registration
     */
    public function test_registers_action_successfully()
    {
        // Act
        $this->registerTestAction('test_action', [
            'label' => 'My Test Action',
        ]);
        
        // Assert
        $this->assertActionRegistered('test_action');
        
        $action = $this->actionRegistry->get('test_action');
        $this->assertEquals('My Test Action', $action['label']);
    }
    
    /**
     * Test batch registration
     */
    public function test_registers_multiple_actions()
    {
        // Act
        $this->actionRegistry->registerBatch([
            'action1' => ActionFactory::actionDefinition(['id' => 'action1']),
            'action2' => ActionFactory::actionDefinition(['id' => 'action2']),
            'action3' => ActionFactory::actionDefinition(['id' => 'action3']),
        ]);
        
        // Assert
        $this->assertActionRegistered('action1');
        $this->assertActionRegistered('action2');
        $this->assertActionRegistered('action3');
        
        $all = $this->actionRegistry->all();
        $this->assertCount(3, $all);
    }
    
    /**
     * Test finding actions by trigger
     */
    public function test_finds_actions_by_trigger()
    {
        // Arrange
        $this->registerTestAction('create_product', [
            'triggers' => ['product', 'create product', 'add product'],
        ]);
        
        $this->registerTestAction('create_order', [
            'triggers' => ['order', 'create order'],
        ]);
        
        // Act
        $productActions = $this->actionRegistry->findByTrigger('product');
        $orderActions = $this->actionRegistry->findByTrigger('order');
        
        // Assert
        $this->assertCount(1, $productActions);
        $this->assertArrayHasKey('create_product', $productActions);
        
        $this->assertCount(1, $orderActions);
        $this->assertArrayHasKey('create_order', $orderActions);
    }
    
    /**
     * Test finding actions by model
     */
    public function test_finds_actions_by_model_class()
    {
        // Arrange
        $this->registerModelAction(\App\Models\Product::class);
        $this->registerModelAction(\App\Models\Order::class);
        
        // Act
        $productActions = $this->actionRegistry->findByModel(\App\Models\Product::class);
        
        // Assert
        $this->assertCount(1, $productActions);
        $this->assertEquals(\App\Models\Product::class, $productActions[0]['model_class']);
    }
    
    /**
     * Test action discovery from models
     */
    public function test_discovers_actions_from_models()
    {
        // Act
        $this->actionRegistry->discoverFromModels();
        
        // Assert
        $all = $this->actionRegistry->all();
        $this->assertNotEmpty($all);
        
        // Check that model actions were discovered
        $modelActions = $this->actionRegistry->getByType('model_action');
        $this->assertNotEmpty($modelActions);
    }
    
    /**
     * Test action unregistration
     */
    public function test_unregisters_action()
    {
        // Arrange
        $this->registerTestAction('test_action');
        $this->assertActionRegistered('test_action');
        
        // Act
        $this->actionRegistry->unregister('test_action');
        
        // Assert
        $this->assertActionNotRegistered('test_action');
    }
    
    /**
     * Test registry statistics
     */
    public function test_provides_statistics()
    {
        // Arrange
        $this->registerTestAction('action1', ['type' => 'model_action']);
        $this->registerTestAction('action2', ['type' => 'model_action']);
        $this->registerTestAction('action3', ['type' => 'custom_action']);
        $this->registerTestAction('action4', ['type' => 'custom_action', 'enabled' => false]);
        
        // Act
        $stats = $this->actionRegistry->getStatistics();
        
        // Assert
        $this->assertEquals(4, $stats['total']);
        $this->assertEquals(3, $stats['enabled']);
        $this->assertEquals(2, $stats['by_type']['model_action']);
        $this->assertEquals(2, $stats['by_type']['custom_action']);
    }
    
    /**
     * Test cache clearing
     */
    public function test_clears_cache()
    {
        // Arrange
        $this->actionRegistry->discoverFromModels();
        
        // Act
        $this->actionRegistry->clearCache();
        $this->actionRegistry->clear();
        
        // Assert: Should be able to discover again
        $this->actionRegistry->discoverFromModels();
        $this->assertNotEmpty($this->actionRegistry->all());
    }
    
    /**
     * Test action has required parameters
     */
    public function test_action_has_required_parameters()
    {
        // Arrange
        $this->registerTestAction('test_action', [
            'required_params' => ['name', 'price', 'category'],
        ]);
        
        // Assert
        $this->assertActionHasRequiredParams('test_action', ['name', 'price']);
    }
}
