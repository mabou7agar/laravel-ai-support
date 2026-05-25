<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenRouter;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Models\AIModel;

class OpenRouterCostOptimizer
{
    public function apply(array &$payload, AIRequest $request): void
    {
        $parameters = $request->getParameters();
        $config = (array) config('ai-engine.engines.openrouter.cost_optimization', []);
        $setting = $parameters['cost_optimization'] ?? $config['enabled'] ?? false;

        if (!$this->enabled($setting)) {
            return;
        }

        $mode = is_string($setting) && $setting !== '1'
            ? $setting
            : (string) ($parameters['cost_optimization_mode'] ?? $config['mode'] ?? 'free_first');

        $models = $this->optimizedModels($payload, $request, $config, $mode);
        if ($models !== []) {
            $payload['models'] = $models;
            unset($payload['model']);
        }

        $provider = (array) ($payload['provider'] ?? []);
        $sortByPrice = (bool) ($parameters['sort_by_price'] ?? $config['sort_by_price'] ?? true);
        if ($sortByPrice && !isset($provider['order'])) {
            $provider['sort'] = array_replace([
                'by' => 'price',
                'partition' => 'none',
            ], (array) ($provider['sort'] ?? []));
        }

        $latency = $parameters['preferred_max_latency_p90']
            ?? $config['preferred_max_latency_p90']
            ?? null;
        if ($latency !== null && $latency !== '') {
            $provider['preferred_max_latency'] = array_replace(
                (array) ($provider['preferred_max_latency'] ?? []),
                ['p90' => is_numeric($latency) ? (float) $latency : $latency]
            );
        }

        $maxPrice = $parameters['max_price'] ?? $config['max_price'] ?? null;
        if (is_array($maxPrice)) {
            $maxPrice = array_filter($maxPrice, static fn ($value): bool => $value !== null && $value !== '');
            if ($maxPrice !== []) {
                $provider['max_price'] = $maxPrice;
            }
        }

        if ($provider !== []) {
            $payload['provider'] = $provider;
        }
    }

    public function enabled(mixed $setting): bool
    {
        if (is_bool($setting)) {
            return $setting;
        }

        if (is_string($setting)) {
            return in_array(strtolower(trim($setting)), ['1', 'true', 'yes', 'on', 'free_first', 'cheapest'], true);
        }

        return (bool) $setting;
    }

    public function optimizedModels(array $payload, AIRequest $request, array $config, string $mode): array
    {
        $configuredModels = array_values(array_filter((array) ($payload['models'] ?? []), 'is_string'));
        if ($configuredModels !== []) {
            return $this->dedupeModels($configuredModels);
        }

        $requestedModel = (string) ($payload['model'] ?? $request->getModel()->value);
        $includeRequested = (bool) ($request->getParameters()['include_requested_model_fallback']
            ?? $config['include_requested_model_fallback']
            ?? true);

        $models = [];
        if (in_array($mode, ['free_first', 'cheapest'], true)) {
            $models = array_merge($models, $this->freeModels($config));
        }

        if ($includeRequested && $requestedModel !== '') {
            $models[] = $requestedModel;
        }

        return $this->dedupeModels($models);
    }

    public function freeModels(array $config): array
    {
        $models = array_values(array_filter((array) ($config['free_models'] ?? []), 'is_string'));

        try {
            $catalogModels = AIModel::active()
                ->where('provider', EngineEnum::OpenRouter->value)
                ->where('model_id', 'like', '%:free')
                ->pluck('model_id')
                ->all();

            $models = array_merge($models, array_values(array_filter($catalogModels, 'is_string')));
        } catch (\Throwable) {
            // Database may not be migrated in lightweight installs.
        }

        return $this->dedupeModels($models);
    }

    public function dedupeModels(array $models): array
    {
        $deduped = [];
        foreach ($models as $model) {
            if (!is_string($model) || trim($model) === '') {
                continue;
            }

            $deduped[] = trim($model);
        }

        return array_values(array_unique($deduped));
    }
}
