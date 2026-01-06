<?php

namespace LaravelAIEngine\Traits;

/**
 * Trait HasAIActions
 * 
 * Add this trait to any model to enable AI-powered actions in chat.
 * The system will automatically discover models with this trait and register actions.
 */
trait HasAIActions
{
    /**
     * Define expected format for AI data extraction
     * 
     * Check if model implements AIConfigurable interface or has customInitializeAI static method
     * 
     * @return array{required: array, optional: array, triggers?: array}
     */
    public static function initializeAI(): array
    {
        // Check if model implements AIConfigurable interface
        if (is_subclass_of(static::class, \LaravelAIEngine\Contracts\AIConfigurable::class)) {
            return static::customInitializeAI();
        }
        
        // Fallback: check if static method exists (for backward compatibility)
        if (method_exists(static::class, 'customInitializeAI')) {
            return static::customInitializeAI();
        }
        
        // Default behavior
        $model = new static();
        $fillable = $model->getFillable();

        return [
            'required' => array_slice($fillable, 0, min(2, count($fillable))),
            'optional' => array_slice($fillable, min(2, count($fillable))),
            'triggers' => [],
        ];
    }

    /**
     * Execute AI action (create, update, delete)
     * 
     * Check if model implements AIConfigurable interface or has customExecuteAI static method
     * 
     * @param string $action The action to perform (create, update, delete)
     * @param array $data The data extracted from conversation
     * @return mixed Model instance or array with success/error
     */
    public static function executeAI(string $action, array $data)
    {
        // Check if model implements AIConfigurable interface
        if (is_subclass_of(static::class, \LaravelAIEngine\Contracts\AIConfigurable::class)) {
            return static::customExecuteAI($action, $data);
        }
        
        // Fallback: check if static method exists (for backward compatibility)
        if (method_exists(static::class, 'customExecuteAI')) {
            return static::customExecuteAI($action, $data);
        }
        
        // Default behavior
        // Resolve AI relationships if the model uses ResolvesAIRelationships trait
        if (method_exists(static::class, 'resolveAIRelationships')) {
            $data = static::resolveAIRelationships($data);
        }
        
        switch ($action) {
            case 'create':
                return static::create($data);
                
            case 'update':
                if (!isset($data['id'])) {
                    return ['success' => false, 'error' => 'ID required for update'];
                }
                $model = static::find($data['id']);
                if (!$model) {
                    return ['success' => false, 'error' => 'Record not found'];
                }
                $model->update($data);
                return $model;
                
            case 'delete':
                if (!isset($data['id'])) {
                    return ['success' => false, 'error' => 'ID required for delete'];
                }
                $model = static::find($data['id']);
                if (!$model) {
                    return ['success' => false, 'error' => 'Record not found'];
                }
                $model->delete();
                return ['success' => true, 'message' => 'Deleted successfully'];
                
            default:
                return ['success' => false, 'error' => "Unknown action: {$action}"];
        }
    }
}
