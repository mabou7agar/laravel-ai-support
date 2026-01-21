<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Services\AIModelRegistry;
use Illuminate\Support\Facades\Log;

/**
 * Model Selection Service
 * 
 * Handles intelligent model selection based on task type and configuration.
 * Supports auto-selection using AIModelRegistry recommendations.
 */
class ModelSelectionService
{
    public function __construct(
        protected AIModelRegistry $modelRegistry
    ) {}

    /**
     * Select the appropriate model based on task type and configuration
     *
     * @param string $defaultEngine Default engine to use
     * @param string $defaultModel Default model to use
     * @param string $taskType Type of task (default, code, creative, etc.)
     * @param bool $autoSelect Force auto-selection regardless of config
     * @return array ['engine' => string, 'model' => string]
     */
    public function selectModel(
        string $defaultEngine,
        string $defaultModel,
        string $taskType = 'default',
        bool $autoSelect = false
    ): array {
        // Check if auto-selection is enabled
        $shouldAutoSelect = $autoSelect || config('ai-engine.auto_select_model', false);
        
        if (!$shouldAutoSelect) {
            return [
                'engine' => $defaultEngine,
                'model' => $defaultModel,
            ];
        }

        // Get recommended model from registry
        $recommendedModel = $this->modelRegistry->getRecommendedModel($taskType);

        if ($recommendedModel) {
            Log::info('ModelSelectionService: Auto-selected model', [
                'task_type' => $taskType,
                'provider' => $recommendedModel->provider,
                'model' => $recommendedModel->model_id,
                'is_offline' => $recommendedModel->provider === 'ollama',
                'default_engine' => $defaultEngine,
                'default_model' => $defaultModel,
            ]);

            return [
                'engine' => $recommendedModel->provider,
                'model' => $recommendedModel->model_id,
            ];
        }

        // Fallback to defaults if no recommendation found
        Log::debug('ModelSelectionService: No recommendation found, using defaults', [
            'task_type' => $taskType,
            'engine' => $defaultEngine,
            'model' => $defaultModel,
        ]);

        return [
            'engine' => $defaultEngine,
            'model' => $defaultModel,
        ];
    }

    /**
     * Check if auto-selection is enabled globally
     *
     * @return bool
     */
    public function isAutoSelectionEnabled(): bool
    {
        return config('ai-engine.auto_select_model', false);
    }

    /**
     * Get available task types for model selection
     *
     * @return array
     */
    public function getAvailableTaskTypes(): array
    {
        return [
            'default' => 'General purpose tasks',
            'code' => 'Code generation and analysis',
            'creative' => 'Creative writing and content',
            'analysis' => 'Data analysis and reasoning',
            'chat' => 'Conversational interactions',
            'fast' => 'Quick responses (optimized for speed)',
            'quality' => 'High quality responses (optimized for accuracy)',
        ];
    }
}
