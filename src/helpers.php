<?php

use LaravelAIEngine\Services\VectorizableModelsRegistry;

if (!function_exists('vectorizable_models')) {
    /**
     * Get all registered vectorizable models
     * 
     * @return array<string>
     */
    function vectorizable_models(): array
    {
        return VectorizableModelsRegistry::all();
    }
}

if (!function_exists('is_vectorizable')) {
    /**
     * Check if a model class is vectorizable
     * 
     * @param string $modelClass
     * @return bool
     */
    function is_vectorizable(string $modelClass): bool
    {
        return VectorizableModelsRegistry::isRegistered($modelClass);
    }
}
