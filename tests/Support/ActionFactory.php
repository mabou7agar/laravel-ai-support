<?php

namespace LaravelAIEngine\Tests\Support;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Services\Actions\ExtractionResult;

/**
 * Action Factory
 * 
 * Factory methods for creating test actions and results
 */
class ActionFactory
{
    /**
     * Create a button action
     */
    public static function button(array $attributes = []): InteractiveAction
    {
        return new InteractiveAction(
            id: $attributes['id'] ?? 'button_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: $attributes['label'] ?? '🎯 Test Button',
            description: $attributes['description'] ?? 'Test button action',
            data: $attributes['data'] ?? []
        );
    }
    
    /**
     * Create a quick reply action
     */
    public static function quickReply(string $label, string $reply): InteractiveAction
    {
        return new InteractiveAction(
            id: 'quick_reply_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::QUICK_REPLY),
            label: $label,
            data: ['reply' => $reply]
        );
    }
    
    /**
     * Create a model action
     */
    public static function modelAction(string $modelClass, array $params = []): InteractiveAction
    {
        $modelName = class_basename($modelClass);
        
        return new InteractiveAction(
            id: 'create_' . strtolower($modelName) . '_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: "🎯 Create {$modelName}",
            description: "Create a new {$modelName}",
            data: [
                'action_id' => 'create_' . strtolower($modelName),
                'executor' => 'model.dynamic',
                'model_class' => $modelClass,
                'params' => $params,
                'ready_to_execute' => true,
            ]
        );
    }
    
    /**
     * Create a remote action
     */
    public static function remoteAction(string $modelClass, string $nodeSlug, array $params = []): InteractiveAction
    {
        $modelName = class_basename($modelClass);
        
        return new InteractiveAction(
            id: 'remote_create_' . strtolower($modelName) . '_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: "🌐 Create {$modelName} (Remote)",
            description: "Create a new {$modelName} on remote node",
            data: [
                'action_id' => 'create_' . strtolower($modelName),
                'executor' => 'model.remote',
                'model_class' => $modelClass,
                'node_slug' => $nodeSlug,
                'params' => $params,
                'ready_to_execute' => true,
            ]
        );
    }
    
    /**
     * Create an incomplete action (missing parameters)
     */
    public static function incompleteAction(string $modelClass, array $params, array $missing): InteractiveAction
    {
        $modelName = class_basename($modelClass);
        
        return new InteractiveAction(
            id: 'incomplete_' . strtolower($modelName) . '_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: "🎯 Create {$modelName} (Incomplete)",
            description: "Missing: " . implode(', ', $missing),
            data: [
                'action_id' => 'create_' . strtolower($modelName),
                'executor' => 'model.dynamic',
                'model_class' => $modelClass,
                'params' => $params,
                'missing_fields' => $missing,
                'ready_to_execute' => false,
            ]
        );
    }
    
    /**
     * Create a successful result
     */
    public static function successResult(array $attributes = []): ActionResult
    {
        return ActionResult::success(
            message: $attributes['message'] ?? '✅ Action completed successfully',
            data: $attributes['data'] ?? ['id' => 1],
            metadata: $attributes['metadata'] ?? []
        );
    }
    
    /**
     * Create a failure result
     */
    public static function failureResult(array $attributes = []): ActionResult
    {
        return ActionResult::failure(
            error: $attributes['error'] ?? 'Action failed',
            data: $attributes['data'] ?? null,
            metadata: $attributes['metadata'] ?? []
        );
    }
    
    /**
     * Create a model creation result
     */
    public static function modelCreatedResult(string $modelClass, int $id = 1): ActionResult
    {
        $modelName = class_basename($modelClass);
        
        return ActionResult::success(
            message: "✅ {$modelName} created successfully!",
            data: ['id' => $id, 'model_class' => $modelClass],
            metadata: ['model_class' => $modelClass]
        );
    }
    
    /**
     * Create a validation error result
     */
    public static function validationErrorResult(array $errors): ActionResult
    {
        return ActionResult::failure(
            error: 'Validation failed',
            data: ['errors' => $errors],
            metadata: ['type' => 'validation_error']
        );
    }
    
    /**
     * Create an extraction result
     */
    public static function extractionResult(array $params, array $missing = [], float $confidence = 1.0): ExtractionResult
    {
        return new ExtractionResult(
            params: $params,
            missing: $missing,
            confidence: $confidence,
            durationMs: rand(50, 200)
        );
    }
    
    /**
     * Create a complete extraction (all fields extracted)
     */
    public static function completeExtraction(array $params): ExtractionResult
    {
        return self::extractionResult($params, [], 1.0);
    }
    
    /**
     * Create an incomplete extraction (missing fields)
     */
    public static function incompleteExtraction(array $params, array $missing): ExtractionResult
    {
        $confidence = count($params) / (count($params) + count($missing));
        return self::extractionResult($params, $missing, $confidence);
    }
    
    /**
     * Create a low confidence extraction
     */
    public static function lowConfidenceExtraction(array $params): ExtractionResult
    {
        return self::extractionResult($params, [], 0.5);
    }
    
    /**
     * Create an action definition
     */
    public static function actionDefinition(array $attributes = []): array
    {
        return array_merge([
            'id' => 'test_action',
            'label' => 'Test Action',
            'description' => 'Test action for testing',
            'executor' => 'test.executor',
            'required_params' => ['name'],
            'optional_params' => ['description'],
            'triggers' => ['test', 'create test'],
            'type' => 'test_action',
            'enabled' => true,
            'version' => '1.0.0',
        ], $attributes);
    }
    
    /**
     * Create a model action definition
     */
    public static function modelActionDefinition(string $modelClass, array $overrides = []): array
    {
        $modelName = class_basename($modelClass);
        
        return array_merge([
            'id' => 'create_' . strtolower($modelName),
            'label' => "🎯 Create {$modelName}",
            'description' => "Create a new {$modelName}",
            'executor' => 'model.dynamic',
            'model_class' => $modelClass,
            'required_params' => ['name'],
            'optional_params' => ['description'],
            'triggers' => [strtolower($modelName), "create {$modelName}"],
            'type' => 'model_action',
            'enabled' => true,
        ], $overrides);
    }
    
    /**
     * Create multiple actions
     */
    public static function batch(int $count, callable $factory): array
    {
        $actions = [];
        
        for ($i = 0; $i < $count; $i++) {
            $actions[] = $factory($i);
        }
        
        return $actions;
    }
}
