<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EngineEnum;

/**
 * Model Resolver Service
 * 
 * Bridges the gap between:
 * - Static EntityEnum (hardcoded models)
 * - Dynamic AIModel (database models)
 * 
 * Provides a unified interface to work with both.
 */
class ModelResolver
{
    /**
     * Resolve a model by ID
     * Checks database first, falls back to EntityEnum
     * 
     * @param string $modelId
     * @return AIModel|EntityEnum|null
     */
    public function resolve(string $modelId)
    {
        // Try database first (dynamic models like GPT-5)
        $dbModel = AIModel::where('model_id', $modelId)
            ->where('is_active', true)
            ->first();

        if ($dbModel) {
            return $dbModel;
        }

        // Fall back to EntityEnum (hardcoded models)
        try {
            return new EntityEnum($modelId);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get engine for a model
     * 
     * @param string $modelId
     * @return EngineEnum|null
     */
    public function getEngine(string $modelId): ?EngineEnum
    {
        $model = $this->resolve($modelId);

        if (!$model) {
            return null;
        }

        // If it's an AIModel (from database)
        if ($model instanceof AIModel) {
            return $model->getEngineEnum();
        }

        // If it's an EntityEnum (hardcoded)
        if ($model instanceof EntityEnum) {
            return $model->engine();
        }

        return null;
    }

    /**
     * Check if a model exists (in database or enum)
     * 
     * @param string $modelId
     * @return bool
     */
    public function exists(string $modelId): bool
    {
        return $this->resolve($modelId) !== null;
    }

    /**
     * Get all available models (database + enum)
     * 
     * @return array
     */
    public function getAllModels(): array
    {
        $models = [];

        // Get database models
        $dbModels = AIModel::active()->get();
        foreach ($dbModels as $model) {
            $models[$model->model_id] = [
                'source' => 'database',
                'model_id' => $model->model_id,
                'name' => $model->name,
                'provider' => $model->provider,
                'capabilities' => $model->capabilities,
                'pricing' => $model->pricing,
                'context_window' => $model->context_window,
            ];
        }

        // Get EntityEnum models (only if not in database)
        $enumClass = EntityEnum::class;
        $reflection = new \ReflectionClass($enumClass);
        $constants = $reflection->getConstants();

        foreach ($constants as $name => $value) {
            if (!isset($models[$value])) {
                try {
                    $entity = new EntityEnum($value);
                    $models[$value] = [
                        'source' => 'enum',
                        'model_id' => $value,
                        'name' => $name,
                        'provider' => $entity->engine()->value,
                        'capabilities' => [],
                        'pricing' => null,
                        'context_window' => null,
                    ];
                } catch (\Exception $e) {
                    // Skip invalid enums
                }
            }
        }

        return $models;
    }

    /**
     * Get model metadata
     * 
     * @param string $modelId
     * @return array|null
     */
    public function getMetadata(string $modelId): ?array
    {
        $model = $this->resolve($modelId);

        if (!$model) {
            return null;
        }

        // If it's an AIModel (from database)
        if ($model instanceof AIModel) {
            return [
                'source' => 'database',
                'model_id' => $model->model_id,
                'name' => $model->name,
                'provider' => $model->provider,
                'version' => $model->version,
                'capabilities' => $model->capabilities,
                'pricing' => $model->pricing,
                'context_window' => $model->context_window,
                'supports_streaming' => $model->supports_streaming,
                'supports_vision' => $model->supports_vision,
                'supports_function_calling' => $model->supports_function_calling,
                'is_deprecated' => $model->is_deprecated,
                'released_at' => $model->released_at,
            ];
        }

        // If it's an EntityEnum (hardcoded)
        if ($model instanceof EntityEnum) {
            return [
                'source' => 'enum',
                'model_id' => $model->value,
                'name' => $model->value,
                'provider' => $model->engine()->value,
                'version' => null,
                'capabilities' => [],
                'pricing' => null,
                'context_window' => null,
                'supports_streaming' => false,
                'supports_vision' => false,
                'supports_function_calling' => false,
                'is_deprecated' => false,
                'released_at' => null,
            ];
        }

        return null;
    }

    /**
     * Prefer database models over enum
     * This ensures dynamic models (GPT-5) take precedence
     * 
     * @param string $modelId
     * @return string
     */
    public function getPreferredSource(string $modelId): string
    {
        $dbModel = AIModel::where('model_id', $modelId)->first();
        return $dbModel ? 'database' : 'enum';
    }
}
