# Action Testing Guide

Comprehensive guide for testing the Laravel AI Engine action system.

## Table of Contents

- [Quick Start](#quick-start)
- [ActionTestCase](#actiontestcase)
- [ActionFactory](#actionfactory)
- [Assertions](#assertions)
- [Mocking & Spying](#mocking--spying)
- [Examples](#examples)

## Quick Start

```php
use LaravelAIEngine\Tests\Support\ActionTestCase;
use LaravelAIEngine\Tests\Support\ActionFactory;

class MyActionTest extends ActionTestCase
{
    public function test_action_execution()
    {
        // Arrange
        $action = ActionFactory::modelAction(\App\Models\Product::class, [
            'name' => 'iPhone 15',
            'price' => 999,
        ]);
        
        // Act
        $result = $this->actionManager->executeAction($action, userId: 1);
        
        // Assert
        $this->assertActionSuccess($result);
        $this->assertActionHasData($result);
    }
}
```

## ActionTestCase

Base test case with helpers for testing actions.

### Available Properties

```php
protected ActionManager $actionManager;
protected ActionRegistry $actionRegistry;
protected ActionExecutionPipeline $actionPipeline;
protected ActionParameterExtractor $actionExtractor;
```

### Registration Helpers

#### `registerTestAction(string $id, array $definition = [])`

Register a test action for testing.

```php
$this->registerTestAction('test_action', [
    'label' => 'Test Action',
    'executor' => 'model.dynamic',
    'required_params' => ['name', 'price'],
]);
```

#### `registerModelAction(string $modelClass, array $overrides = [])`

Register a model action and return its ID.

```php
$actionId = $this->registerModelAction(\App\Models\Product::class, [
    'required_params' => ['name', 'price', 'category'],
]);
```

### Mock Helpers

#### `mockActionExecution(string $actionId, ActionResult $result)`

Mock action execution to return specific result.

```php
$this->mockActionExecution('create_product', 
    ActionResult::success('Product created!', ['id' => 123])
);
```

#### `mockActionSuccess(string $actionId, mixed $data = null)`

Mock successful action execution.

```php
$this->mockActionSuccess('create_product', data: ['id' => 123]);
```

#### `mockActionFailure(string $actionId, string $error = 'Action failed')`

Mock failed action execution.

```php
$this->mockActionFailure('create_product', 'Validation failed');
```

#### `mockParameterExtraction(array $params, array $missing = [], float $confidence = 1.0)`

Mock parameter extraction.

```php
$this->mockParameterExtraction(
    params: ['name' => 'iPhone', 'price' => 999],
    missing: [],
    confidence: 0.95
);
```

### Factory Helpers

#### `createTestAction(array $attributes = [])`

Create a test InteractiveAction.

```php
$action = $this->createTestAction([
    'id' => 'my_action',
    'label' => 'My Action',
    'data' => ['params' => ['name' => 'Test']],
]);
```

#### `createTestResult(bool $success = true, array $attributes = [])`

Create a test ActionResult.

```php
$result = $this->createTestResult(success: true, [
    'message' => 'Success!',
    'data' => ['id' => 1],
]);
```

### Spy Helpers

#### `spyOnActionExecution(string $actionId)`

Spy on action execution.

```php
$spy = $this->spyOnActionExecution('create_product');

// ... execute actions ...

$this->assertActionCalledWith($spy, 'create_product', [
    'name' => 'iPhone',
    'price' => 999,
]);
```

### Database Helpers

#### `clearActionMetrics()`

Clear all action metrics from database.

```php
$this->clearActionMetrics();
```

#### `getActionExecutionCount(string $actionId, ?int $userId = null)`

Get action execution count.

```php
$count = $this->getActionExecutionCount('create_product', userId: 1);
```

## ActionFactory

Factory methods for creating test actions and results.

### Action Factories

#### `button(array $attributes = [])`

Create a button action.

```php
$action = ActionFactory::button([
    'label' => '🎯 Create Product',
    'data' => ['params' => ['name' => 'Test']],
]);
```

#### `quickReply(string $label, string $reply)`

Create a quick reply action.

```php
$action = ActionFactory::quickReply('Yes', 'Yes, proceed');
```

#### `modelAction(string $modelClass, array $params = [])`

Create a model action.

```php
$action = ActionFactory::modelAction(\App\Models\Product::class, [
    'name' => 'iPhone 15',
    'price' => 999,
]);
```

#### `remoteAction(string $modelClass, string $nodeSlug, array $params = [])`

Create a remote action.

```php
$action = ActionFactory::remoteAction(
    \App\Models\Product::class,
    'remote-node',
    ['name' => 'Remote Product']
);
```

#### `incompleteAction(string $modelClass, array $params, array $missing)`

Create an incomplete action (missing parameters).

```php
$action = ActionFactory::incompleteAction(
    \App\Models\Product::class,
    ['name' => 'iPhone'],
    ['price', 'category']
);
```

### Result Factories

#### `successResult(array $attributes = [])`

Create a successful result.

```php
$result = ActionFactory::successResult([
    'message' => '✅ Success!',
    'data' => ['id' => 1],
]);
```

#### `failureResult(array $attributes = [])`

Create a failure result.

```php
$result = ActionFactory::failureResult([
    'error' => 'Validation failed',
]);
```

#### `modelCreatedResult(string $modelClass, int $id = 1)`

Create a model creation result.

```php
$result = ActionFactory::modelCreatedResult(\App\Models\Product::class, id: 123);
```

#### `validationErrorResult(array $errors)`

Create a validation error result.

```php
$result = ActionFactory::validationErrorResult([
    'name' => ['Name is required'],
    'price' => ['Price must be positive'],
]);
```

### Extraction Factories

#### `extractionResult(array $params, array $missing = [], float $confidence = 1.0)`

Create an extraction result.

```php
$result = ActionFactory::extractionResult(
    params: ['name' => 'iPhone', 'price' => 999],
    missing: [],
    confidence: 0.95
);
```

#### `completeExtraction(array $params)`

Create a complete extraction (all fields extracted).

```php
$result = ActionFactory::completeExtraction([
    'name' => 'iPhone',
    'price' => 999,
    'category' => 'Electronics',
]);
```

#### `incompleteExtraction(array $params, array $missing)`

Create an incomplete extraction.

```php
$result = ActionFactory::incompleteExtraction(
    params: ['name' => 'iPhone'],
    missing: ['price', 'category']
);
```

#### `lowConfidenceExtraction(array $params)`

Create a low confidence extraction.

```php
$result = ActionFactory::lowConfidenceExtraction(['name' => 'iPhone']);
```

### Definition Factories

#### `actionDefinition(array $attributes = [])`

Create an action definition.

```php
$definition = ActionFactory::actionDefinition([
    'id' => 'test_action',
    'required_params' => ['name', 'price'],
]);
```

#### `modelActionDefinition(string $modelClass, array $overrides = [])`

Create a model action definition.

```php
$definition = ActionFactory::modelActionDefinition(\App\Models\Product::class, [
    'required_params' => ['name', 'price', 'category'],
]);
```

### Batch Factory

#### `batch(int $count, callable $factory)`

Create multiple items.

```php
$actions = ActionFactory::batch(5, fn($i) => 
    ActionFactory::button(['label' => "Action {$i}"])
);
```

## Assertions

### Action Execution Assertions

#### `assertActionExecuted(string $actionId, ?int $userId = null)`

Assert action was executed.

```php
$this->assertActionExecuted('create_product');
$this->assertActionExecuted('create_product', userId: 1);
```

#### `assertActionNotExecuted(string $actionId, ?int $userId = null)`

Assert action was not executed.

```php
$this->assertActionNotExecuted('delete_product');
```

#### `assertActionExecutionCount(string $actionId, int $expectedCount, ?int $userId = null)`

Assert action execution count.

```php
$this->assertActionExecutionCount('create_product', expectedCount: 3);
$this->assertActionExecutionCount('create_product', expectedCount: 2, userId: 1);
```

### Result Assertions

#### `assertActionSuccess(ActionResult $result, ?string $message = null)`

Assert action result is successful.

```php
$this->assertActionSuccess($result);
$this->assertActionSuccess($result, message: '✅ Product created!');
```

#### `assertActionFailure(ActionResult $result, ?string $error = null)`

Assert action result is failure.

```php
$this->assertActionFailure($result);
$this->assertActionFailure($result, error: 'Validation failed');
```

#### `assertActionHasData(ActionResult $result, ?string $key = null)`

Assert action result has data.

```php
$this->assertActionHasData($result);
$this->assertActionHasData($result, key: 'id');
```

#### `assertActionHasMetadata(ActionResult $result, string $key, mixed $value = null)`

Assert action result has metadata.

```php
$this->assertActionHasMetadata($result, 'model_class');
$this->assertActionHasMetadata($result, 'model_class', \App\Models\Product::class);
```

#### `assertActionExecutionTime(ActionResult $result, int $maxMs)`

Assert action execution time is within limit.

```php
$this->assertActionExecutionTime($result, maxMs: 1000);
```

### Registry Assertions

#### `assertActionRegistered(string $actionId)`

Assert action is registered.

```php
$this->assertActionRegistered('create_product');
```

#### `assertActionNotRegistered(string $actionId)`

Assert action is not registered.

```php
$this->assertActionNotRegistered('deleted_action');
```

#### `assertActionHasRequiredParams(string $actionId, array $params)`

Assert action has required parameters.

```php
$this->assertActionHasRequiredParams('create_product', ['name', 'price']);
```

### Extraction Assertions

#### `assertParametersExtracted(array $expected, array $actual)`

Assert parameters were extracted.

```php
$this->assertParametersExtracted(
    expected: ['name' => 'iPhone', 'price' => 999],
    actual: $result->params
);
```

#### `assertExtractionComplete(ExtractionResult $result)`

Assert extraction is complete (no missing fields).

```php
$this->assertExtractionComplete($result);
```

#### `assertExtractionIncomplete(ExtractionResult $result, array $expectedMissing = [])`

Assert extraction has missing fields.

```php
$this->assertExtractionIncomplete($result, ['price', 'category']);
```

#### `assertHighConfidence(ExtractionResult $result, float $threshold = 0.8)`

Assert extraction has high confidence.

```php
$this->assertHighConfidence($result);
$this->assertHighConfidence($result, threshold: 0.9);
```

### Spy Assertions

#### `assertActionCalledWith(MockInterface $spy, string $actionId, array $params)`

Assert action was called with specific parameters.

```php
$spy = $this->spyOnActionExecution('create_product');

// ... execute action ...

$this->assertActionCalledWith($spy, 'create_product', [
    'name' => 'iPhone',
    'price' => 999,
]);
```

## Examples

See the `tests/Feature/Actions/` directory for complete examples:

- **ActionExecutionTest.php** - Action execution examples
- **ParameterExtractionTest.php** - Parameter extraction examples
- **ActionRegistryTest.php** - Action registry examples

## Best Practices

1. **Use ActionTestCase** - Extend from ActionTestCase for all action tests
2. **Use ActionFactory** - Use factory methods instead of manual construction
3. **Clear State** - Clear action metrics and registry between tests
4. **Mock External Services** - Mock AI services and remote nodes
5. **Test Edge Cases** - Test missing params, validation errors, timeouts
6. **Use Descriptive Names** - Name tests clearly: `test_action_fails_with_missing_parameters`
7. **Arrange-Act-Assert** - Follow AAA pattern in all tests

## Running Tests

```bash
# Run all action tests
php artisan test --filter=Actions

# Run specific test
php artisan test --filter=ActionExecutionTest

# Run with coverage
php artisan test --coverage --filter=Actions
```
