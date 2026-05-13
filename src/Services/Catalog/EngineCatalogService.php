<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Catalog;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Repositories\AIModelRepository;

class EngineCatalogService
{
    public function __construct(protected AIModelRepository $models)
    {
    }

    public function flatModels(?string $engine = null): array
    {
        $catalog = $this->catalog();

        if ($engine !== null && $engine !== '') {
            $catalog = array_filter($catalog, static fn (array $item): bool => $item['engine'] === $engine);
        }

        $models = [];
        foreach ($catalog as $engineEntry) {
            foreach ($engineEntry['models'] as $model) {
                $models[] = $model;
            }
        }

        usort($models, static function (array $left, array $right): int {
            return [$left['engine'], $left['model_id']] <=> [$right['engine'], $right['model_id']];
        });

        return array_values($models);
    }

    public function engines(): array
    {
        return array_map(static function (array $entry): array {
            return [
                'engine' => $entry['engine'],
                'name' => $entry['name'],
                'capabilities' => $entry['capabilities'],
                'configured' => $entry['configured'],
                'model_count' => count($entry['models']),
                'default_model' => $entry['default_model'],
            ];
        }, $this->catalog());
    }

    public function catalog(): array
    {
        $catalog = [];

        foreach (EngineEnum::cases() as $engine) {
            $catalog[$engine->value] = [
                'engine' => $engine->value,
                'name' => $engine->label(),
                'capabilities' => $engine->capabilities(),
                'configured' => $this->isEngineConfigured($engine->value),
                'default_model' => $this->defaultModelForEngine($engine->value),
                'models' => [],
            ];

            foreach ((array) config("ai-engine.engines.{$engine->value}.models", []) as $modelId => $config) {
                if (!$this->isModelEnabled($config)) {
                    continue;
                }

                $catalog[$engine->value]['models'][$modelId] = [
                    'engine' => $engine->value,
                    'provider' => $this->engineToProvider($engine->value),
                    'model_id' => (string) $modelId,
                    'name' => (string) $modelId,
                    'source' => 'config',
                    'capabilities' => [],
                    'supports_streaming' => null,
                    'supports_vision' => null,
                    'supports_function_calling' => null,
                ];
            }
        }

        foreach ($this->models->active() as $model) {
            $engine = $this->providerToEngine((string) $model->provider);
            if ($engine === null || !isset($catalog[$engine])) {
                continue;
            }

            $catalog[$engine]['models'][$model->model_id] = [
                'engine' => $engine,
                'provider' => (string) $model->provider,
                'model_id' => (string) $model->model_id,
                'name' => (string) ($model->name ?: $model->model_id),
                'source' => 'database',
                'capabilities' => $model->capabilities ?? [],
                'supports_streaming' => $model->supports_streaming,
                'supports_vision' => $model->supports_vision,
                'supports_function_calling' => $model->supports_function_calling,
            ];
        }

        foreach ($catalog as &$engineEntry) {
            ksort($engineEntry['models']);
            $engineEntry['models'] = array_values($engineEntry['models']);
        }
        unset($engineEntry);

        return array_values($catalog);
    }

    protected function isEngineConfigured(string $engine): bool
    {
        $config = (array) config("ai-engine.engines.{$engine}", []);

        if ($engine === EngineEnum::OLLAMA) {
            return !empty($config['base_url']);
        }

        return trim((string) ($config['api_key'] ?? '')) !== '';
    }

    protected function defaultModelForEngine(string $engine): ?string
    {
        $default = config("ai-engine.engines.{$engine}.default_model");
        if (is_string($default) && $default !== '') {
            return $default;
        }

        $models = (array) config("ai-engine.engines.{$engine}.models", []);
        if ($models !== []) {
            $first = array_key_first($models);

            return is_string($first) ? $first : null;
        }

        return null;
    }

    protected function isModelEnabled(mixed $config): bool
    {
        if (!is_array($config)) {
            return true;
        }

        return (bool) ($config['enabled'] ?? true);
    }

    protected function providerToEngine(string $provider): ?string
    {
        return match ($provider) {
            'google' => EngineEnum::GEMINI,
            'stability', 'stability_ai' => EngineEnum::STABLE_DIFFUSION,
            'elevenlabs' => EngineEnum::ELEVEN_LABS,
            'fal', 'falai', 'fal_ai' => EngineEnum::FAL_AI,
            'openai', 'anthropic', 'deepseek', 'perplexity', 'midjourney', 'azure', 'google_tts', 'serper', 'plagiarism_check', 'unsplash', 'pexels', 'openrouter', 'ollama' => $provider,
            default => null,
        };
    }

    protected function engineToProvider(string $engine): string
    {
        return match ($engine) {
            EngineEnum::GEMINI => 'google',
            EngineEnum::STABLE_DIFFUSION => 'stability',
            EngineEnum::ELEVEN_LABS => 'elevenlabs',
            EngineEnum::FAL_AI => 'fal_ai',
            default => $engine,
        };
    }
}
