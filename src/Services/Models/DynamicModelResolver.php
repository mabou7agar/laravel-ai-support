<?php

namespace LaravelAIEngine\Services\Models;

use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Enums\EngineEnum;
use Illuminate\Support\Facades\Cache;

class DynamicModelResolver
{
    protected const CACHE_KEY = 'ai_engine:model_resolver';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Resolve model information from database or fallback to EntityEnum
     */
    public function resolve(string $modelId): ?array
    {
        // Try to get from cache first
        $cached = Cache::get(self::CACHE_KEY . ':' . $modelId);
        if ($cached !== null) {
            return $cached;
        }

        // Try to find in database
        $model = AIModel::where('model_id', $modelId)->first();
        
        if ($model) {
            $resolved = [
                'model_id' => $model->model_id,
                'name' => $model->name,
                'provider' => $model->provider,
                'engine' => $this->mapProviderToEngine($model->provider),
                'driver_class' => $this->getDriverClass($model->provider, $model->model_id),
                'max_tokens' => $model->context_window ?? $this->guessMaxTokens($model->model_id),
                'supports_vision' => $model->supports_vision ?? false,
                'supports_streaming' => $model->supports_streaming ?? true,
                'credit_index' => $this->calculateCreditIndex($model),
                'content_type' => $this->getContentType($model),
            ];

            Cache::put(self::CACHE_KEY . ':' . $modelId, $resolved, self::CACHE_TTL);
            return $resolved;
        }

        return null;
    }

    /**
     * Map provider name to EngineEnum
     */
    protected function mapProviderToEngine(string $provider): string
    {
        return match(strtolower($provider)) {
            'openai' => EngineEnum::OPENAI,
            'anthropic' => EngineEnum::ANTHROPIC,
            'google', 'gemini' => EngineEnum::GEMINI,
            'stability', 'stable-diffusion' => EngineEnum::STABLE_DIFFUSION,
            'elevenlabs' => EngineEnum::ELEVENLABS,
            'fal', 'fal-ai' => EngineEnum::FAL_AI,
            'deepseek' => EngineEnum::DEEPSEEK,
            'perplexity' => EngineEnum::PERPLEXITY,
            'openrouter' => EngineEnum::OPENROUTER,
            default => EngineEnum::OPENAI,
        };
    }

    /**
     * Get driver class based on provider and model
     */
    protected function getDriverClass(string $provider, string $modelId): string
    {
        // Map to appropriate driver based on provider and model type
        return match(strtolower($provider)) {
            'openai' => $this->getOpenAIDriver($modelId),
            'anthropic' => $this->getAnthropicDriver($modelId),
            'google', 'gemini' => $this->getGeminiDriver($modelId),
            default => \LaravelAIEngine\Drivers\OpenAI\GPT4ODriver::class,
        };
    }

    /**
     * Get OpenAI driver based on model
     */
    protected function getOpenAIDriver(string $modelId): string
    {
        if (str_contains($modelId, 'gpt-4o-mini')) {
            return \LaravelAIEngine\Drivers\OpenAI\GPT4OMiniDriver::class;
        }
        if (str_contains($modelId, 'gpt-4o') || str_contains($modelId, 'gpt-5') || str_contains($modelId, 'gpt-4.1')) {
            return \LaravelAIEngine\Drivers\OpenAI\GPT4ODriver::class;
        }
        if (str_contains($modelId, 'gpt-3.5')) {
            return \LaravelAIEngine\Drivers\OpenAI\GPT35TurboDriver::class;
        }
        if (str_contains($modelId, 'dall-e-3')) {
            return \LaravelAIEngine\Drivers\OpenAI\DallE3Driver::class;
        }
        if (str_contains($modelId, 'dall-e-2')) {
            return \LaravelAIEngine\Drivers\OpenAI\DallE2Driver::class;
        }
        if (str_contains($modelId, 'whisper')) {
            return \LaravelAIEngine\Drivers\OpenAI\WhisperDriver::class;
        }
        
        // Default to GPT-4O driver for unknown OpenAI models
        return \LaravelAIEngine\Drivers\OpenAI\GPT4ODriver::class;
    }

    /**
     * Get Anthropic driver based on model
     */
    protected function getAnthropicDriver(string $modelId): string
    {
        if (str_contains($modelId, 'sonnet')) {
            return \LaravelAIEngine\Drivers\Anthropic\Claude35SonnetDriver::class;
        }
        if (str_contains($modelId, 'haiku')) {
            return \LaravelAIEngine\Drivers\Anthropic\Claude3HaikuDriver::class;
        }
        if (str_contains($modelId, 'opus')) {
            return \LaravelAIEngine\Drivers\Anthropic\Claude3OpusDriver::class;
        }
        
        return \LaravelAIEngine\Drivers\Anthropic\Claude35SonnetDriver::class;
    }

    /**
     * Get Gemini driver based on model
     */
    protected function getGeminiDriver(string $modelId): string
    {
        if (str_contains($modelId, 'flash')) {
            return \LaravelAIEngine\Drivers\Gemini\Gemini15FlashDriver::class;
        }
        
        return \LaravelAIEngine\Drivers\Gemini\Gemini15ProDriver::class;
    }

    /**
     * Guess max tokens based on model name
     */
    protected function guessMaxTokens(string $modelId): int
    {
        // GPT-5 and newer models
        if (str_contains($modelId, 'gpt-5') || str_contains($modelId, 'gpt-4.1')) {
            return 200000;
        }
        
        // GPT-4o models
        if (str_contains($modelId, 'gpt-4o')) {
            return 128000;
        }
        
        // Claude models
        if (str_contains($modelId, 'claude')) {
            return 200000;
        }
        
        // Gemini models
        if (str_contains($modelId, 'gemini-1.5-pro')) {
            return 2097152;
        }
        if (str_contains($modelId, 'gemini')) {
            return 1048576;
        }
        
        // GPT-3.5
        if (str_contains($modelId, 'gpt-3.5')) {
            return 16385;
        }
        
        // Default
        return 128000;
    }

    /**
     * Calculate credit index based on model
     */
    protected function calculateCreditIndex(AIModel $model): float
    {
        // If model has pricing info, calculate based on that
        if ($model->input_price_per_token && $model->output_price_per_token) {
            // Average of input and output, normalized to GPT-4o baseline
            $avgPrice = ($model->input_price_per_token + $model->output_price_per_token) / 2;
            $gpt4oBaseline = 0.000015; // Approximate GPT-4o price
            return $avgPrice / $gpt4oBaseline;
        }

        // Fallback to model name-based estimation
        $modelId = strtolower($model->model_id);
        
        if (str_contains($modelId, 'gpt-5-pro') || str_contains($modelId, 'gpt-4.1')) {
            return 3.0;
        }
        if (str_contains($modelId, 'gpt-5') && !str_contains($modelId, 'mini') && !str_contains($modelId, 'nano')) {
            return 2.5;
        }
        if (str_contains($modelId, 'gpt-4o') && !str_contains($modelId, 'mini')) {
            return 2.0;
        }
        if (str_contains($modelId, 'gpt-5-mini') || str_contains($modelId, 'gpt-4o-mini')) {
            return 0.5;
        }
        if (str_contains($modelId, 'gpt-5-nano') || str_contains($modelId, 'gpt-3.5')) {
            return 0.3;
        }
        if (str_contains($modelId, 'claude-3.5-sonnet') || str_contains($modelId, 'claude-3-opus')) {
            return 1.8;
        }
        if (str_contains($modelId, 'claude') && str_contains($modelId, 'haiku')) {
            return 0.8;
        }
        
        // Default
        return 1.0;
    }

    /**
     * Get content type for model
     */
    protected function getContentType(AIModel $model): string
    {
        $modelId = strtolower($model->model_id);
        
        if (str_contains($modelId, 'dall-e') || str_contains($modelId, 'stable-diffusion') || 
            str_contains($modelId, 'flux') || str_contains($modelId, 'midjourney')) {
            return 'image';
        }
        
        if (str_contains($modelId, 'whisper') || str_contains($modelId, 'audio')) {
            return 'audio';
        }
        
        if (str_contains($modelId, 'video') || str_contains($modelId, 'kling') || 
            str_contains($modelId, 'luma')) {
            return 'video';
        }
        
        return 'text';
    }

    /**
     * Clear cache for a specific model or all models
     */
    public function clearCache(?string $modelId = null): void
    {
        if ($modelId) {
            Cache::forget(self::CACHE_KEY . ':' . $modelId);
        } else {
            // Clear all model resolver caches
            $models = AIModel::pluck('model_id');
            foreach ($models as $id) {
                Cache::forget(self::CACHE_KEY . ':' . $id);
            }
        }
    }

    /**
     * Check if model exists in database
     */
    public function exists(string $modelId): bool
    {
        return AIModel::where('model_id', $modelId)->exists();
    }
}
