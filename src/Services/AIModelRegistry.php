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
     * 
     * Supports offline fallback to Ollama when no internet connection is available.
     * 
     * @param string $task Task type: vision, coding, reasoning, fast, cheap, quality
     * @param string|null $provider Specific provider (openai, anthropic, ollama, etc.)
     * @param bool $offlineMode Force offline mode (use Ollama)
     * @return AIModel|null
     */
    public function getRecommendedModel(string $task, ?string $provider = null, bool $offlineMode = false): ?AIModel
    {
        // If offline mode or no internet, prefer Ollama
        if ($offlineMode || !$this->hasInternetConnection()) {
            $ollamaModel = $this->getRecommendedOllamaModel($task);
            if ($ollamaModel) {
                return $ollamaModel;
            }
        }

        $query = AIModel::active();

        if ($provider) {
            $query->where('provider', $provider);
        }

        $model = match ($task) {
            'vision' => $query->vision()->orderBy('pricing->input')->first(),
            'coding' => $query->whereJsonContains('capabilities', 'coding')->first(),
            'reasoning' => $query->whereJsonContains('capabilities', 'reasoning')->first(),
            'fast' => $query->orderBy('pricing->input')->first(),
            'cheap' => $query->orderBy('pricing->input')->first(),
            'quality' => $query->orderByDesc('context_window->input')->first(),
            default => $query->chat()->orderBy('pricing->input')->first(),
        };

        // Fallback to Ollama if no online model available
        if (!$model) {
            return $this->getRecommendedOllamaModel($task);
        }

        return $model;
    }

    /**
     * Get recommended Ollama model for offline use
     */
    protected function getRecommendedOllamaModel(string $task): ?AIModel
    {
        $query = AIModel::active()->where('provider', 'ollama');

        return match ($task) {
            'vision' => $query->vision()->first(),
            'coding' => $query->whereJsonContains('capabilities', 'coding')->first(),
            'reasoning' => $query->orderByDesc('context_window->input')->first(),
            'fast' => $query->orderBy('model_id')->first(), // Smaller models are faster
            'cheap' => $query->first(), // Ollama is free
            'quality' => $query->orderByDesc('context_window->input')->first(),
            default => $query->first(),
        };
    }

    /**
     * Check if internet connection is available
     */
    protected function hasInternetConnection(): bool
    {
        try {
            // Quick check to OpenAI (most common provider)
            $apiKey = config('ai-engine.engines.openai.api_key');
            if (!$apiKey) {
                return false; // No API key = likely offline scenario
            }

            // Try a lightweight HEAD request with 2 second timeout
            $response = Http::timeout(2)->head('https://api.openai.com');
            return $response->successful();
        } catch (\Exception $e) {
            // Connection failed = offline
            return false;
        }
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

                // Sync GPT, O1, O3 models
                if (!str_starts_with($modelId, 'gpt-')
                    && !str_starts_with($modelId, 'o1-')
                    && !str_starts_with($modelId, 'o3-')
                    && !str_starts_with($modelId, 'o1')
                    && !str_starts_with($modelId, 'o3')) {
                    continue;
                }

                // Skip date snapshots and variants - only sync main models
                // Skip: gpt-4o-2024-11-20, gpt-3.5-turbo-0125, gpt-4-1106-preview, etc.
                if (preg_match('/-\d{4}-\d{2}-\d{2}$/', $modelId) ||  // Full date suffix
                    preg_match('/-\d{4}$/', $modelId) ||               // Short date suffix (0125, 1106)
                    preg_match('/-\d{4}-/', $modelId) ||               // Date in middle
                    str_ends_with($modelId, '-preview') ||             // Preview variants
                    str_ends_with($modelId, '-16k') ||                 // 16k context variants
                    str_ends_with($modelId, '-mini') && !str_contains($modelId, 'gpt-4') && !str_contains($modelId, 'gpt-5') && !str_contains($modelId, 'o3') || // Mini variants except main ones
                    str_contains($modelId, '-chat-latest') ||          // Chat latest aliases
                    str_contains($modelId, '-codex') ||                // Codex variants
                    str_contains($modelId, '-transcribe') ||           // Transcribe variants
                    str_contains($modelId, '-tts') ||                  // TTS variants
                    str_contains($modelId, '-diarize') ||              // Diarize variants
                    str_contains($modelId, '-search-') ||              // Search variants
                    str_starts_with($modelId, 'gpt-audio') ||          // Audio models
                    str_starts_with($modelId, 'gpt-realtime') ||       // Realtime models
                    str_starts_with($modelId, 'gpt-image')) {          // Image models (use dall-e instead)
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
                        'supports_vision' => str_contains($modelId, 'vision')
                            || str_contains($modelId, 'gpt-4')
                            || str_contains($modelId, 'gpt-5'),
                        'supports_function_calling' => !str_starts_with($modelId, 'o1')
                            && !str_starts_with($modelId, 'o3'),
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
        // Anthropic doesn't have a public models endpoint
        // Seed predefined Claude 4 models
        $claudeModels = [
            [
                'model_id' => 'claude-4.5-sonnet',
                'name' => 'Claude 4.5 Sonnet',
                'description' => 'Latest Claude model with best quality',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 200000, 'output' => 8192],
            ],
            [
                'model_id' => 'claude-4-opus',
                'name' => 'Claude 4 Opus',
                'description' => 'Most capable Claude model for complex reasoning',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 200000, 'output' => 8192],
            ],
            [
                'model_id' => 'claude-4-sonnet',
                'name' => 'Claude 4 Sonnet',
                'description' => 'Balanced Claude 4 model',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 200000, 'output' => 8192],
            ],
            [
                'model_id' => 'claude-3-5-sonnet-20241022',
                'name' => 'Claude 3.5 Sonnet',
                'description' => 'Previous generation Claude model',
                'capabilities' => ['chat', 'vision', 'coding', 'function_calling'],
                'context_window' => ['input' => 200000, 'output' => 8192],
            ],
            [
                'model_id' => 'claude-3-5-haiku-20241022',
                'name' => 'Claude 3.5 Haiku',
                'description' => 'Fast and affordable Claude model',
                'capabilities' => ['chat', 'coding', 'function_calling'],
                'context_window' => ['input' => 200000, 'output' => 8192],
            ],
        ];

        $synced = 0;
        $new = 0;

        foreach ($claudeModels as $modelData) {
            $existing = AIModel::where('model_id', $modelData['model_id'])->first();

            if (!$existing) {
                AIModel::create([
                    'provider' => 'anthropic',
                    'model_id' => $modelData['model_id'],
                    'name' => $modelData['name'],
                    'description' => $modelData['description'],
                    'capabilities' => $modelData['capabilities'],
                    'context_window' => $modelData['context_window'],
                    'supports_streaming' => true,
                    'supports_vision' => in_array('vision', $modelData['capabilities']),
                    'supports_function_calling' => true,
                    'is_active' => true,
                    'released_at' => now(),
                ]);
                $new++;
            }
            $synced++;
        }

        return [
            'success' => true,
            'message' => "Synced {$synced} Anthropic models ({$new} new)",
            'total' => $synced,
            'new' => $new,
        ];
    }

    /**
     * Sync models from Google Gemini API
     */
    public function syncGeminiModels(): array
    {
        // Google Gemini models - predefined since API requires different auth
        $geminiModels = [
            [
                'model_id' => 'gemini-3-pro-preview',
                'name' => 'Gemini 3 Pro Preview',
                'description' => 'Best multimodal understanding, most powerful agentic model',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 65536],
            ],
            [
                'model_id' => 'gemini-3-pro-image',
                'name' => 'Gemini 3 Pro Image',
                'description' => 'Native image generation variant',
                'capabilities' => ['chat', 'vision', 'image_generation'],
                'context_window' => ['input' => 65536, 'output' => 32768],
            ],
            [
                'model_id' => 'gemini-2.5-pro',
                'name' => 'Gemini 2.5 Pro',
                'description' => 'Complex reasoning and analysis',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-2.5-flash',
                'name' => 'Gemini 2.5 Flash',
                'description' => 'Fast and efficient',
                'capabilities' => ['chat', 'vision', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-2.0-pro',
                'name' => 'Gemini 2.0 Pro',
                'description' => 'Reliable reasoning model',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-2.0-flash',
                'name' => 'Gemini 2.0 Flash',
                'description' => 'Balanced speed and quality',
                'capabilities' => ['chat', 'vision', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-2.0-flash-thinking',
                'name' => 'Gemini 2.0 Flash Thinking',
                'description' => 'Reasoning-focused flash model',
                'capabilities' => ['chat', 'vision', 'reasoning', 'coding'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-1.5-pro',
                'name' => 'Gemini 1.5 Pro',
                'description' => 'Previous generation reliable model',
                'capabilities' => ['chat', 'vision', 'coding', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
            [
                'model_id' => 'gemini-1.5-flash',
                'name' => 'Gemini 1.5 Flash',
                'description' => 'Fast and affordable',
                'capabilities' => ['chat', 'vision', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
            ],
        ];

        $synced = 0;
        $new = 0;

        foreach ($geminiModels as $modelData) {
            $existing = AIModel::where('model_id', $modelData['model_id'])->first();

            if (!$existing) {
                AIModel::create([
                    'provider' => 'google',
                    'model_id' => $modelData['model_id'],
                    'name' => $modelData['name'],
                    'description' => $modelData['description'],
                    'capabilities' => $modelData['capabilities'],
                    'context_window' => $modelData['context_window'],
                    'supports_streaming' => true,
                    'supports_vision' => in_array('vision', $modelData['capabilities']),
                    'supports_function_calling' => in_array('function_calling', $modelData['capabilities']),
                    'is_active' => true,
                    'released_at' => now(),
                ]);
                $new++;
            }
            $synced++;
        }

        return [
            'success' => true,
            'message' => "Synced {$synced} Gemini models ({$new} new)",
            'total' => $synced,
            'new' => $new,
        ];
    }

    /**
     * Sync models from DeepSeek API
     */
    public function syncDeepSeekModels(): array
    {
        // DeepSeek models - predefined
        $deepseekModels = [
            [
                'model_id' => 'deepseek-chat',
                'name' => 'DeepSeek Chat',
                'description' => 'General purpose chat model',
                'capabilities' => ['chat', 'coding', 'function_calling'],
                'context_window' => ['input' => 64000, 'output' => 8192],
                'pricing' => ['input' => 0.14, 'output' => 0.28],
            ],
            [
                'model_id' => 'deepseek-coder',
                'name' => 'DeepSeek Coder',
                'description' => 'Specialized coding model',
                'capabilities' => ['chat', 'coding', 'function_calling'],
                'context_window' => ['input' => 64000, 'output' => 8192],
                'pricing' => ['input' => 0.14, 'output' => 0.28],
            ],
            [
                'model_id' => 'deepseek-v3',
                'name' => 'DeepSeek V3',
                'description' => 'Latest DeepSeek model with advanced reasoning',
                'capabilities' => ['chat', 'reasoning', 'coding', 'function_calling'],
                'context_window' => ['input' => 128000, 'output' => 8192],
                'pricing' => ['input' => 0.27, 'output' => 1.10],
            ],
            [
                'model_id' => 'deepseek-r1',
                'name' => 'DeepSeek R1',
                'description' => 'Reasoning-focused model',
                'capabilities' => ['chat', 'reasoning', 'coding'],
                'context_window' => ['input' => 64000, 'output' => 8192],
                'pricing' => ['input' => 0.55, 'output' => 2.19],
            ],
            [
                'model_id' => 'deepseek-r1-lite',
                'name' => 'DeepSeek R1 Lite',
                'description' => 'Lightweight reasoning model',
                'capabilities' => ['chat', 'reasoning', 'coding'],
                'context_window' => ['input' => 64000, 'output' => 8192],
                'pricing' => ['input' => 0.14, 'output' => 0.28],
            ],
        ];

        $synced = 0;
        $new = 0;

        foreach ($deepseekModels as $modelData) {
            $existing = AIModel::where('model_id', $modelData['model_id'])->first();

            if (!$existing) {
                AIModel::create([
                    'provider' => 'deepseek',
                    'model_id' => $modelData['model_id'],
                    'name' => $modelData['name'],
                    'description' => $modelData['description'],
                    'capabilities' => $modelData['capabilities'],
                    'context_window' => $modelData['context_window'],
                    'pricing' => $modelData['pricing'],
                    'supports_streaming' => true,
                    'supports_vision' => false,
                    'supports_function_calling' => in_array('function_calling', $modelData['capabilities']),
                    'is_active' => true,
                    'released_at' => now(),
                ]);
                $new++;
            }
            $synced++;
        }

        return [
            'success' => true,
            'message' => "Synced {$synced} DeepSeek models ({$new} new)",
            'total' => $synced,
            'new' => $new,
        ];
    }

    /**
     * Sync models from OpenRouter API
     */
    public function syncOpenRouterModels(): array
    {
        try {
            $response = Http::withHeaders([
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
        $results['google'] = $this->syncGeminiModels();
        $results['deepseek'] = $this->syncDeepSeekModels();
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

        // Vision support
        if (str_contains($modelId, 'vision')
            || str_contains($modelId, 'gpt-4')
            || str_contains($modelId, 'gpt-5')) {
            $capabilities[] = 'vision';
        }

        // Reasoning models (O1, O3, GPT-5)
        if (str_contains($modelId, 'o1')
            || str_contains($modelId, 'o3')
            || str_contains($modelId, 'gpt-5')) {
            $capabilities[] = 'reasoning';
        }

        // Function calling (not supported by O-series)
        if ((str_contains($modelId, 'gpt-4') || str_contains($modelId, 'gpt-3.5') || str_contains($modelId, 'gpt-5'))
            && !str_starts_with($modelId, 'o1')
            && !str_starts_with($modelId, 'o3')) {
            $capabilities[] = 'function_calling';
        }

        // Coding capabilities
        if (str_contains($modelId, 'gpt-5') || str_contains($modelId, 'gpt-4o')) {
            $capabilities[] = 'coding';
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
