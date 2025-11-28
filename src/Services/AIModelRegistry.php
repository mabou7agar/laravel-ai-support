<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Models\AIModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Model Registry Service
 * 
 * Manages AI models dynamically, supports auto-discovery of new models
 */
class AIModelRegistry
{
    /**
     * Get all available models
     */
    public function getAllModels(): Collection
    {
        return AIModel::active()->get();
    }

    /**
     * Get models by provider
     */
    public function getModelsByProvider(string $provider): Collection
    {
        return AIModel::byProvider($provider)->get();
    }

    /**
     * Get model by ID
     */
    public function getModel(string $modelId): ?AIModel
    {
        return AIModel::findByModelId($modelId);
    }

    /**
     * Check if model exists and is active
     */
    public function isModelAvailable(string $modelId): bool
    {
        $model = $this->getModel($modelId);
        return $model && $model->is_active && !$model->is_deprecated;
    }

    /**
     * Get recommended model for a task
     */
    public function getRecommendedModel(string $task, ?string $provider = null): ?AIModel
    {
        $query = AIModel::active();

        if ($provider) {
            $query->where('provider', $provider);
        }

        return match ($task) {
            'vision' => $query->vision()->orderBy('pricing->input')->first(),
            'coding' => $query->whereJsonContains('capabilities', 'coding')->first(),
            'reasoning' => $query->whereJsonContains('capabilities', 'reasoning')->first(),
            'fast' => $query->orderBy('pricing->input')->first(),
            'cheap' => $query->orderBy('pricing->input')->first(),
            'quality' => $query->orderByDesc('context_window->input')->first(),
            default => $query->chat()->orderBy('pricing->input')->first(),
        };
    }

    /**
     * Register a new model
     */
    public function registerModel(array $data): AIModel
    {
        return AIModel::create($data);
    }

    /**
     * Update model information
     */
    public function updateModel(string $modelId, array $data): bool
    {
        $model = AIModel::where('model_id', $modelId)->first();
        
        if (!$model) {
            return false;
        }

        $model->update($data);
        return true;
    }

    /**
     * Deprecate a model
     */
    public function deprecateModel(string $modelId): bool
    {
        $model = AIModel::where('model_id', $modelId)->first();
        
        if (!$model) {
            return false;
        }

        $model->deprecate();
        return true;
    }

    /**
     * Sync models from OpenAI API
     */
    public function syncOpenAIModels(): array
    {
        try {
            $apiKey = config('ai-engine.engines.openai.api_key');
            
            if (!$apiKey) {
                return ['error' => 'OpenAI API key not configured'];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
            ])->get('https://api.openai.com/v1/models');

            if (!$response->successful()) {
                return ['error' => 'Failed to fetch models from OpenAI'];
            }

            $models = $response->json('data', []);
            $synced = [];
            $newModels = [];

            foreach ($models as $modelData) {
                $modelId = $modelData['id'];
                
                // Only sync GPT and O1 models
                if (!str_starts_with($modelId, 'gpt-') && !str_starts_with($modelId, 'o1-')) {
                    continue;
                }

                $existing = AIModel::where('model_id', $modelId)->first();
                
                if (!$existing) {
                    // Auto-detect capabilities
                    $capabilities = $this->detectCapabilities($modelId);
                    
                    $model = AIModel::create([
                        'provider' => 'openai',
                        'model_id' => $modelId,
                        'name' => $this->formatModelName($modelId),
                        'description' => 'Auto-discovered OpenAI model',
                        'capabilities' => $capabilities,
                        'supports_streaming' => true,
                        'supports_vision' => str_contains($modelId, 'vision') || str_contains($modelId, 'gpt-4'),
                        'supports_function_calling' => !str_starts_with($modelId, 'o1-'),
                        'is_active' => true,
                        'released_at' => now(),
                        'metadata' => $modelData,
                    ]);
                    
                    $newModels[] = $modelId;
                }
                
                $synced[] = $modelId;
            }

            return [
                'success' => true,
                'total' => count($synced),
                'new' => count($newModels),
                'new_models' => $newModels,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync OpenAI models', [
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Sync models from Anthropic API
     */
    public function syncAnthropicModels(): array
    {
        // Anthropic doesn't have a models endpoint yet
        // Return predefined models
        return [
            'success' => true,
            'message' => 'Anthropic models are manually maintained',
        ];
    }

    /**
     * Sync models from OpenRouter API
     */
    public function syncOpenRouterModels(): array
    {
        try {
            $apiKey = config('ai-engine.engines.openrouter.api_key');
            
            if (!$apiKey) {
                return ['error' => 'OpenRouter API key not configured'];
            }

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])->get('https://openrouter.ai/api/v1/models');

            if (!$response->successful()) {
                return ['error' => 'Failed to fetch models from OpenRouter'];
            }

            $models = $response->json('data', []);
            $synced = [];
            $newModels = [];

            foreach ($models as $modelData) {
                $modelId = $modelData['id'];
                
                $existing = AIModel::where('model_id', $modelId)->first();
                
                if (!$existing) {
                    // Extract pricing
                    $pricing = null;
                    if (isset($modelData['pricing'])) {
                        $pricing = [
                            'input' => (float) ($modelData['pricing']['prompt'] ?? 0),
                            'output' => (float) ($modelData['pricing']['completion'] ?? 0),
                        ];
                    }

                    // Extract context window
                    $contextWindow = null;
                    if (isset($modelData['context_length'])) {
                        $contextWindow = [
                            'input' => (int) $modelData['context_length'],
                            'output' => (int) ($modelData['top_provider']['max_completion_tokens'] ?? 4096),
                        ];
                    }

                    $model = AIModel::create([
                        'provider' => 'openrouter',
                        'model_id' => $modelId,
                        'name' => $modelData['name'] ?? $this->formatModelName($modelId),
                        'description' => $modelData['description'] ?? 'OpenRouter model',
                        'capabilities' => $this->detectOpenRouterCapabilities($modelData),
                        'context_window' => $contextWindow,
                        'pricing' => $pricing,
                        'supports_streaming' => true,
                        'supports_vision' => str_contains(strtolower($modelData['name'] ?? ''), 'vision') 
                            || str_contains(strtolower($modelId), 'vision'),
                        'supports_function_calling' => isset($modelData['architecture']['modality']) 
                            && str_contains($modelData['architecture']['modality'], 'text'),
                        'is_active' => true,
                        'released_at' => now(),
                        'metadata' => $modelData,
                    ]);
                    
                    $newModels[] = $modelId;
                }
                
                $synced[] = $modelId;
            }

            return [
                'success' => true,
                'total' => count($synced),
                'new' => count($newModels),
                'new_models' => $newModels,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to sync OpenRouter models', [
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Sync all providers
     */
    public function syncAllModels(): array
    {
        $results = [];

        $results['openai'] = $this->syncOpenAIModels();
        $results['anthropic'] = $this->syncAnthropicModels();
        $results['openrouter'] = $this->syncOpenRouterModels();

        return $results;
    }

    /**
     * Detect capabilities from OpenRouter model data
     */
    protected function detectOpenRouterCapabilities(array $modelData): array
    {
        $capabilities = ['chat'];

        $name = strtolower($modelData['name'] ?? '');
        $id = strtolower($modelData['id'] ?? '');

        if (str_contains($name, 'vision') || str_contains($id, 'vision')) {
            $capabilities[] = 'vision';
        }

        if (str_contains($name, 'code') || str_contains($id, 'code')) {
            $capabilities[] = 'coding';
        }

        if (isset($modelData['architecture']['modality'])) {
            $modality = $modelData['architecture']['modality'];
            if (str_contains($modality, 'image')) {
                $capabilities[] = 'vision';
            }
            if (str_contains($modality, 'text')) {
                $capabilities[] = 'function_calling';
            }
        }

        return $capabilities;
    }

    /**
     * Detect capabilities from model ID
     */
    protected function detectCapabilities(string $modelId): array
    {
        $capabilities = ['chat'];

        if (str_contains($modelId, 'vision') || str_contains($modelId, 'gpt-4')) {
            $capabilities[] = 'vision';
        }

        if (str_contains($modelId, 'o1')) {
            $capabilities[] = 'reasoning';
        }

        if (str_contains($modelId, 'gpt-4') || str_contains($modelId, 'gpt-3.5')) {
            $capabilities[] = 'function_calling';
        }

        return $capabilities;
    }

    /**
     * Format model name from ID
     */
    protected function formatModelName(string $modelId): string
    {
        // gpt-4o -> GPT-4o
        // gpt-5-turbo -> GPT-5 Turbo
        $name = str_replace('-', ' ', $modelId);
        $name = ucwords($name);
        $name = str_replace('Gpt', 'GPT', $name);
        $name = str_replace('O1', 'O1', $name);
        
        return $name;
    }

    /**
     * Get model statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => AIModel::count(),
            'active' => AIModel::active()->count(),
            'deprecated' => AIModel::where('is_deprecated', true)->count(),
            'by_provider' => AIModel::active()
                ->get()
                ->groupBy('provider')
                ->map(fn($models) => $models->count())
                ->toArray(),
            'with_vision' => AIModel::active()->vision()->count(),
            'with_function_calling' => AIModel::active()->functionCalling()->count(),
        ];
    }

    /**
     * Get cheapest model for a provider
     */
    public function getCheapestModel(?string $provider = null): ?AIModel
    {
        $query = AIModel::active();

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query->orderBy('pricing->input')->first();
    }

    /**
     * Get most capable model
     */
    public function getMostCapableModel(?string $provider = null): ?AIModel
    {
        $query = AIModel::active();

        if ($provider) {
            $query->where('provider', $provider);
        }

        return $query
            ->orderByDesc('context_window->input')
            ->orderByDesc('supports_vision')
            ->orderByDesc('supports_function_calling')
            ->first();
    }

    /**
     * Search models
     */
    public function search(string $query): Collection
    {
        return AIModel::active()
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('model_id', 'like', "%{$query}%")
                  ->orWhere('description', 'like', "%{$query}%");
            })
            ->get();
    }
}
