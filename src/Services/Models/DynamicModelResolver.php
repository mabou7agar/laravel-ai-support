<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Models;

use LaravelAIEngine\Models\AIModel;
use LaravelAIEngine\Enums\EngineEnum;
use Illuminate\Support\Facades\Cache;

class DynamicModelResolver
{
    // v2: sparse arrays — only keys the database actually knows (pre-v2 entries carried guessed values)
    protected const CACHE_KEY = 'ai_engine:model_resolver:v2';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * Resolve model information from the ai_models table.
     *
     * Only keys the database actually knows are present in the returned array —
     * callers (EntityEnum) fall back to the shipped manifest for missing keys,
     * so this must never emit name-pattern guesses.
     */
    public function resolve(string $modelId): ?array
    {
        $cached = Cache::get(self::CACHE_KEY . ':' . $modelId);
        if ($cached !== null) {
            return $cached;
        }

        $model = AIModel::where('model_id', $modelId)->first();

        if (!$model) {
            return null;
        }

        $metadata = (array) ($model->metadata ?? []);

        $resolved = ['model_id' => $model->model_id];

        if (!empty($model->name)) {
            $resolved['name'] = $model->name;
        }

        if ($engine = $this->mapProviderToEngine((string) $model->provider)) {
            $resolved['engine'] = $engine;
        }

        $driverClass = $metadata['driver_class'] ?? null;
        if (is_string($driverClass) && class_exists($driverClass)) {
            $resolved['driver_class'] = $driverClass;
        }

        $maxTokens = $model->max_tokens ?? $model->getContextWindowSize();
        if ($maxTokens) {
            $resolved['max_tokens'] = (int) $maxTokens;
        }

        if ($model->supports_vision !== null) {
            $resolved['supports_vision'] = (bool) $model->supports_vision;
        }

        if ($model->supports_streaming !== null) {
            $resolved['supports_streaming'] = (bool) $model->supports_streaming;
        }

        if ($creditIndex = $this->resolveCreditIndex($model, $metadata)) {
            $resolved['credit_index'] = $creditIndex;
        }

        if ($contentType = $this->resolveContentType($model, $metadata)) {
            $resolved['content_type'] = $contentType;
        }

        Cache::put(self::CACHE_KEY . ':' . $modelId, $resolved, self::CACHE_TTL);

        return $resolved;
    }

    /**
     * Map provider name to an EngineEnum value, null when unknown
     */
    protected function mapProviderToEngine(string $provider): ?string
    {
        $provider = strtolower($provider);

        $mapped = match ($provider) {
            'google'                        => EngineEnum::Gemini->value,
            'stability'                     => EngineEnum::StableDiffusion->value,
            'fal', 'fal-ai'                 => EngineEnum::FalAI->value,
            'cloudflare'                    => EngineEnum::CloudflareWorkersAI->value,
            'hugging_face'                  => EngineEnum::HuggingFace->value,
            'comfy'                         => EngineEnum::ComfyUI->value,
            'xai'                           => EngineEnum::Xai->value,
            default                         => $provider,
        };

        return EngineEnum::tryFrom($mapped)?->value;
    }

    /**
     * Credit index from explicit metadata, else derived from real pricing
     */
    protected function resolveCreditIndex(AIModel $model, array $metadata): ?float
    {
        if (isset($metadata['credit_index'])) {
            return (float) $metadata['credit_index'];
        }

        $input = $model->getInputPrice();
        $output = $model->getOutputPrice();
        if ($input && $output) {
            // Average of input and output, normalized to GPT-4o baseline
            $avgPrice = ($input + $output) / 2;
            $gpt4oBaseline = 0.000015; // Approximate GPT-4o price
            return $avgPrice / $gpt4oBaseline;
        }

        return null;
    }

    /**
     * Content type from explicit metadata or declared capabilities
     */
    protected function resolveContentType(AIModel $model, array $metadata): ?string
    {
        if (isset($metadata['content_type'])) {
            return (string) $metadata['content_type'];
        }

        $capabilities = array_map('strtolower', array_map('strval', (array) ($model->capabilities ?? [])));
        if ($capabilities === []) {
            return null;
        }

        if (array_intersect($capabilities, ['video_generation', 'text_to_video', 'image_to_video', 'reference_to_video'])) {
            return 'video';
        }

        if (array_intersect($capabilities, ['image_generation', 'image_editing', 'text_to_image', 'vision', 'image_analysis'])) {
            return 'image';
        }

        if (array_intersect($capabilities, ['audio_generation', 'tts', 'transcription', 'speech_to_text', 'text_to_speech'])) {
            return 'audio';
        }

        if (array_intersect($capabilities, ['chat', 'completion', 'text_generation'])) {
            return 'text';
        }

        return null;
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

        \LaravelAIEngine\Enums\EntityEnum::flushRuntimeCache();
    }

    /**
     * Check if model exists in database
     */
    public function exists(string $modelId): bool
    {
        return AIModel::where('model_id', $modelId)->exists();
    }
}
