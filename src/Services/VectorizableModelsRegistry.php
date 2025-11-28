<?php

namespace LaravelAIEngine\Services;

/**
 * Registry for all models using the Vectorizable trait
 * 
 * This service maintains a list of all models that use the Vectorizable trait.
 * Models are automatically registered when they boot.
 */
class VectorizableModelsRegistry
{
    /**
     * Registered vectorizable model classes
     * 
     * @var array<string>
     */
    protected static array $models = [];

    /**
     * Register a model class
     * 
     * @param string $modelClass
     * @return void
     */
    public static function register(string $modelClass): void
    {
        if (!in_array($modelClass, static::$models)) {
            static::$models[] = $modelClass;
        }
    }

    /**
     * Get all registered vectorizable models
     * 
     * @return array<string>
     */
    public static function all(): array
    {
        return static::$models;
    }

    /**
     * Get count of registered models
     * 
     * @return int
     */
    public static function count(): int
    {
        return count(static::$models);
    }

    /**
     * Check if a model is registered
     * 
     * @param string $modelClass
     * @return bool
     */
    public static function isRegistered(string $modelClass): bool
    {
        return in_array($modelClass, static::$models);
    }

    /**
     * Clear all registered models (useful for testing)
     * 
     * @return void
     */
    public static function clear(): void
    {
        static::$models = [];
    }

    /**
     * Get models by namespace
     * 
     * @param string $namespace
     * @return array<string>
     */
    public static function getByNamespace(string $namespace): array
    {
        return array_filter(static::$models, function ($model) use ($namespace) {
            return str_starts_with($model, $namespace);
        });
    }
}
