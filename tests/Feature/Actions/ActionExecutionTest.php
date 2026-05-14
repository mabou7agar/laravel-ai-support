<?php

namespace LaravelAIEngine\Tests\Feature\Actions;

use LaravelAIEngine\Tests\Support\ActionTestCase;
use LaravelAIEngine\Tests\Support\ActionFactory;
use LaravelAIEngine\DTOs\ActionResult;

/**
 * Example: Action Execution Tests
 * 
 * Demonstrates how to use ActionTestCase and ActionFactory
 */
class ActionExecutionTest extends ActionTestCase
{
    /**
     * Test successful action execution
     */
    public function test_action_executes_successfully()
    {
        // Arrange: Register a test action
        $this->registerTestAction('create_product', [
            'executor' => 'model.dynamic',
            'model_class' => \App\Models\Product::class,
            'required_params' => ['name', 'price'],
        ]);
        
        // Act: Execute the action
        $action = ActionFactory::modelAction(\App\Models\Product::class, [
            'name' => 'Sample Phone 15',
            'price' => 999,
        ]);
        
        $result = $this->actionManager->executeAction($action, userId: 1);
        
        // Assert: Check result
        $this->assertActionSuccess($result);
        $this->assertActionHasData($result);
        $this->assertActionExecutionTime($result, maxMs: 1000);
    }
    
    /**
     * Test action execution with missing parameters
     */
    public function test_action_fails_with_missing_parameters()
    {
        // Arrange
        $this->registerTestAction('create_product', [
            'executor' => 'model.dynamic',
            'model_class' => \App\Models\Product::class,
            'required_params' => ['name', 'price'],
        ]);
        
        // Act: Execute with missing price
        $action = ActionFactory::modelAction(\App\Models\Product::class, [
            'name' => 'Sample Phone 15',
            // price is missing
        ]);
        
        $result = $this->actionManager->executeAction($action, userId: 1);
        
        // Assert
        $this->assertActionFailure($result);
        $this->assertStringContainsString('missing', strtolower($result->error ?? ''));
    }
    
    /**
     * Test action execution is tracked
     */
    public function test_action_execution_is_tracked()
    {
        // Arrange
        $actionId = $this->registerModelAction(\App\Models\Product::class);
        $this->clearActionMetrics();
        
        // Act: Execute action multiple times
        $action = ActionFactory::modelAction(\App\Models\Product::class, [
            'name' => 'Test Product',
            'price' => 100,
        ]);
        
        $this->actionManager->executeAction($action, userId: 1);
        $this->actionManager->executeAction($action, userId: 1);
        $this->actionManager->executeAction($action, userId: 2);
        
        // Assert
        $this->assertActionExecutionCount($actionId, expectedCount: 3);
        $this->assertActionExecutionCount($actionId, expectedCount: 2, userId: 1);
        $this->assertActionExecutionCount($actionId, expectedCount: 1, userId: 2);
    }
    
    /**
     * Test mocking action execution
     */
    public function test_mock_action_execution()
    {
        // Arrange: Mock successful execution
        $this->mockActionSuccess('create_product', data: ['id' => 123]);
        
        // Act
        $result = $this->actionManager->executeById(
            actionId: 'create_product',
            params: ['name' => 'Test'],
            userId: 1
        );
        
        // Assert
        $this->assertActionSuccess($result);
        $this->assertEquals(123, $result->data['id']);
    }

    public function test_action_execution_failure_uses_safe_public_error()
    {
        $this->registerTestAction('throwing_action', [
            'executor' => ThrowingActionExecutor::class,
        ]);

        $result = $this->actionManager->executeById(
            actionId: 'throwing_action',
            params: [],
            userId: 1
        );

        $this->assertActionFailure($result);
        $this->assertSame('Custom executor failed. Please try again.', $result->error);
        $this->assertStringNotContainsString('database password leaked', $result->error);
        $this->assertStringNotContainsString(ThrowingActionExecutor::class, $result->error);
    }
    
    /**
     * Test action with spy
     */
    public function test_action_called_with_correct_parameters()
    {
        // Arrange: Spy on action manager
        $spy = $this->spyOnActionExecution('create_product');
        
        // Act
        $this->actionManager->executeById(
            actionId: 'create_product',
            params: ['name' => 'Sample Phone', 'price' => 999],
            userId: 1
        );
        
        // Assert
        $this->assertActionCalledWith($spy, 'create_product', [
            'name' => 'Sample Phone',
            'price' => 999,
        ]);
    }
    
    /**
     * Test remote action execution
     */
    public function test_remote_action_execution()
    {
        // Arrange: Register remote action
        $this->registerTestAction('create_product', [
            'executor' => 'model.remote',
            'model_class' => \App\Models\Product::class,
            'node_slug' => 'test-node',
            'is_remote' => true,
        ]);
        
        // Act
        $action = ActionFactory::remoteAction(
            \App\Models\Product::class,
            'test-node',
            ['name' => 'Remote Product']
        );
        
        $result = $this->actionManager->executeAction($action, userId: 1);
        
        // Assert
        $this->assertActionHasMetadata($result, 'node');
    }
}

class ThrowingActionExecutor
{
    public function execute(array $params, $userId): ActionResult
    {
        throw new \RuntimeException('database password leaked');
    }
}
