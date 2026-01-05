<?php

namespace LaravelAIEngine\Tests\Support;

use LaravelAIEngine\Services\Actions\ActionManager;
use LaravelAIEngine\Services\Actions\ActionRegistry;
use LaravelAIEngine\Services\Actions\ActionExecutionPipeline;
use LaravelAIEngine\Services\Actions\ActionParameterExtractor;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Mockery;

/**
 * Action Test Case
 * 
 * Base test case with helpers for testing actions
 */
abstract class ActionTestCase extends BaseTestCase
{
    protected ActionManager $actionManager;
    protected ActionRegistry $actionRegistry;
    protected ActionExecutionPipeline $actionPipeline;
    protected ActionParameterExtractor $actionExtractor;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->actionManager = app(ActionManager::class);
        $this->actionRegistry = app(ActionRegistry::class);
        $this->actionPipeline = app(ActionExecutionPipeline::class);
        $this->actionExtractor = app(ActionParameterExtractor::class);
    }
    
    // ==================== Action Registration Helpers ====================
    
    /**
     * Register a test action
     */
    protected function registerTestAction(string $id, array $definition = []): void
    {
        $this->actionRegistry->register($id, array_merge([
            'label' => 'Test Action',
            'description' => 'Test action for testing',
            'executor' => 'test.executor',
            'required_params' => [],
            'optional_params' => [],
        ], $definition));
    }
    
    /**
     * Register a model action for testing
     */
    protected function registerModelAction(string $modelClass, array $overrides = []): string
    {
        $modelName = class_basename($modelClass);
        $actionId = 'create_' . strtolower($modelName);
        
        $this->actionRegistry->register($actionId, array_merge([
            'label' => "Create {$modelName}",
            'description' => "Create a new {$modelName}",
            'executor' => 'model.dynamic',
            'model_class' => $modelClass,
            'required_params' => ['name'],
            'optional_params' => ['description'],
            'type' => 'model_action',
        ], $overrides));
        
        return $actionId;
    }
    
    // ==================== Mock Helpers ====================
    
    /**
     * Mock ActionManager to return specific result
     */
    protected function mockActionExecution(string $actionId, ActionResult $result): void
    {
        $mock = Mockery::mock(ActionManager::class);
        $mock->shouldReceive('executeById')
            ->with($actionId, Mockery::any(), Mockery::any(), Mockery::any())
            ->andReturn($result);
        
        $this->app->instance(ActionManager::class, $mock);
    }
    
    /**
     * Mock ActionManager to return success
     */
    protected function mockActionSuccess(string $actionId, mixed $data = null): void
    {
        $result = ActionResult::success(
            message: 'Action executed successfully',
            data: $data
        );
        
        $this->mockActionExecution($actionId, $result);
    }
    
    /**
     * Mock ActionManager to return failure
     */
    protected function mockActionFailure(string $actionId, string $error = 'Action failed'): void
    {
        $result = ActionResult::failure(error: $error);
        $this->mockActionExecution($actionId, $result);
    }
    
    /**
     * Mock parameter extraction
     */
    protected function mockParameterExtraction(array $params, array $missing = [], float $confidence = 1.0): void
    {
        $mock = Mockery::mock(ActionParameterExtractor::class);
        $mock->shouldReceive('extract')
            ->andReturn(new \LaravelAIEngine\Services\Actions\ExtractionResult(
                params: $params,
                missing: $missing,
                confidence: $confidence
            ));
        
        $this->app->instance(ActionParameterExtractor::class, $mock);
    }
    
    // ==================== Factory Helpers ====================
    
    /**
     * Create a test InteractiveAction
     */
    protected function createTestAction(array $attributes = []): InteractiveAction
    {
        return new InteractiveAction(
            id: $attributes['id'] ?? 'test_action_' . uniqid(),
            type: ActionTypeEnum::from($attributes['type'] ?? ActionTypeEnum::BUTTON),
            label: $attributes['label'] ?? 'Test Action',
            description: $attributes['description'] ?? 'Test action description',
            data: $attributes['data'] ?? [
                'action_id' => 'test_action',
                'params' => [],
            ]
        );
    }
    
    /**
     * Create a test ActionResult
     */
    protected function createTestResult(bool $success = true, array $attributes = []): ActionResult
    {
        if ($success) {
            return ActionResult::success(
                message: $attributes['message'] ?? 'Success',
                data: $attributes['data'] ?? null,
                metadata: $attributes['metadata'] ?? []
            );
        }
        
        return ActionResult::failure(
            error: $attributes['error'] ?? 'Failure',
            data: $attributes['data'] ?? null,
            metadata: $attributes['metadata'] ?? []
        );
    }
    
    // ==================== Assertion Helpers ====================
    
    /**
     * Assert action was executed
     */
    protected function assertActionExecuted(string $actionId, ?int $userId = null): void
    {
        // Check if action exists in registry
        $this->assertTrue(
            $this->actionRegistry->has($actionId),
            "Action '{$actionId}' was not found in registry"
        );
        
        // If ActionMetric model exists, check database
        if (class_exists(\LaravelAIEngine\Models\ActionMetric::class)) {
            $query = \LaravelAIEngine\Models\ActionMetric::where('action_id', $actionId);
            
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            
            $this->assertTrue(
                $query->exists(),
                "Action '{$actionId}' was not executed" . ($userId ? " by user {$userId}" : '')
            );
        }
    }
    
    /**
     * Assert action was not executed
     */
    protected function assertActionNotExecuted(string $actionId, ?int $userId = null): void
    {
        if (class_exists(\LaravelAIEngine\Models\ActionMetric::class)) {
            $query = \LaravelAIEngine\Models\ActionMetric::where('action_id', $actionId);
            
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            
            $this->assertFalse(
                $query->exists(),
                "Action '{$actionId}' was executed" . ($userId ? " by user {$userId}" : '')
            );
        }
    }
    
    /**
     * Assert action result is successful
     */
    protected function assertActionSuccess(ActionResult $result, ?string $message = null): void
    {
        $this->assertTrue(
            $result->success,
            'Expected action to succeed but it failed' . ($result->error ? ": {$result->error}" : '')
        );
        
        if ($message !== null) {
            $this->assertEquals($message, $result->message);
        }
    }
    
    /**
     * Assert action result is failure
     */
    protected function assertActionFailure(ActionResult $result, ?string $error = null): void
    {
        $this->assertFalse(
            $result->success,
            'Expected action to fail but it succeeded'
        );
        
        if ($error !== null) {
            $this->assertStringContainsString($error, $result->error ?? '');
        }
    }
    
    /**
     * Assert action result has data
     */
    protected function assertActionHasData(ActionResult $result, ?string $key = null): void
    {
        $this->assertTrue(
            $result->hasData(),
            'Expected action result to have data'
        );
        
        if ($key !== null) {
            $this->assertIsArray($result->data);
            $this->assertArrayHasKey($key, $result->data);
        }
    }
    
    /**
     * Assert action result has metadata
     */
    protected function assertActionHasMetadata(ActionResult $result, string $key, mixed $value = null): void
    {
        $this->assertArrayHasKey($key, $result->metadata);
        
        if ($value !== null) {
            $this->assertEquals($value, $result->metadata[$key]);
        }
    }
    
    /**
     * Assert action is registered
     */
    protected function assertActionRegistered(string $actionId): void
    {
        $this->assertTrue(
            $this->actionRegistry->has($actionId),
            "Action '{$actionId}' is not registered"
        );
    }
    
    /**
     * Assert action is not registered
     */
    protected function assertActionNotRegistered(string $actionId): void
    {
        $this->assertFalse(
            $this->actionRegistry->has($actionId),
            "Action '{$actionId}' is registered but should not be"
        );
    }
    
    /**
     * Assert action has required parameters
     */
    protected function assertActionHasRequiredParams(string $actionId, array $params): void
    {
        $action = $this->actionRegistry->get($actionId);
        
        $this->assertNotNull($action, "Action '{$actionId}' not found");
        
        $requiredParams = $action['required_params'] ?? [];
        
        foreach ($params as $param) {
            $this->assertContains(
                $param,
                $requiredParams,
                "Action '{$actionId}' does not have required parameter '{$param}'"
            );
        }
    }
    
    /**
     * Assert parameters were extracted
     */
    protected function assertParametersExtracted(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey(
                $key,
                $actual,
                "Expected parameter '{$key}' was not extracted"
            );
            
            $this->assertEquals(
                $value,
                $actual[$key],
                "Parameter '{$key}' has incorrect value"
            );
        }
    }
    
    /**
     * Assert extraction is complete (no missing fields)
     */
    protected function assertExtractionComplete(\LaravelAIEngine\Services\Actions\ExtractionResult $result): void
    {
        $this->assertTrue(
            $result->isComplete(),
            'Expected extraction to be complete but has missing fields: ' . implode(', ', $result->missing)
        );
    }
    
    /**
     * Assert extraction has missing fields
     */
    protected function assertExtractionIncomplete(\LaravelAIEngine\Services\Actions\ExtractionResult $result, array $expectedMissing = []): void
    {
        $this->assertFalse(
            $result->isComplete(),
            'Expected extraction to be incomplete but all fields were extracted'
        );
        
        if (!empty($expectedMissing)) {
            foreach ($expectedMissing as $field) {
                $this->assertContains(
                    $field,
                    $result->missing,
                    "Expected field '{$field}' to be missing"
                );
            }
        }
    }
    
    /**
     * Assert extraction has high confidence
     */
    protected function assertHighConfidence(\LaravelAIEngine\Services\Actions\ExtractionResult $result, float $threshold = 0.8): void
    {
        $this->assertGreaterThanOrEqual(
            $threshold,
            $result->confidence,
            "Expected confidence >= {$threshold} but got {$result->confidence}"
        );
    }
    
    /**
     * Assert action execution time is within limit
     */
    protected function assertActionExecutionTime(ActionResult $result, int $maxMs): void
    {
        $this->assertNotNull($result->durationMs, 'Action result does not have duration');
        
        $this->assertLessThanOrEqual(
            $maxMs,
            $result->durationMs,
            "Action took {$result->durationMs}ms but should be <= {$maxMs}ms"
        );
    }
    
    // ==================== Spy Helpers ====================
    
    /**
     * Spy on action execution
     */
    protected function spyOnActionExecution(string $actionId): Mockery\MockInterface
    {
        $spy = Mockery::spy(ActionManager::class);
        $this->app->instance(ActionManager::class, $spy);
        return $spy;
    }
    
    /**
     * Assert action was called with parameters
     */
    protected function assertActionCalledWith(Mockery\MockInterface $spy, string $actionId, array $params): void
    {
        $spy->shouldHaveReceived('executeById')
            ->with($actionId, $params, Mockery::any(), Mockery::any())
            ->once();
    }
    
    // ==================== Database Helpers ====================
    
    /**
     * Clear action metrics
     */
    protected function clearActionMetrics(): void
    {
        if (class_exists(\LaravelAIEngine\Models\ActionMetric::class)) {
            \LaravelAIEngine\Models\ActionMetric::query()->delete();
        }
    }
    
    /**
     * Get action execution count
     */
    protected function getActionExecutionCount(string $actionId, ?int $userId = null): int
    {
        if (!class_exists(\LaravelAIEngine\Models\ActionMetric::class)) {
            return 0;
        }
        
        $query = \LaravelAIEngine\Models\ActionMetric::where('action_id', $actionId);
        
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        
        return $query->count();
    }
    
    /**
     * Assert action execution count
     */
    protected function assertActionExecutionCount(string $actionId, int $expectedCount, ?int $userId = null): void
    {
        $actualCount = $this->getActionExecutionCount($actionId, $userId);
        
        $this->assertEquals(
            $expectedCount,
            $actualCount,
            "Expected action '{$actionId}' to be executed {$expectedCount} times but was executed {$actualCount} times"
        );
    }
}
