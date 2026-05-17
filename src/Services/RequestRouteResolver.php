<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Collection;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Models\AIModel;

class RequestRouteResolver
{
    public function __construct(
        protected AIModelRegistry $modelRegistry
    ) {}

    public function resolve(AIRequest $request): AIRequest
    {
        if ($request->wasEngineExplicitlyProvided() && $request->wasModelExplicitlyProvided()) {
            return $request;
        }

        if (!$request->wasModelExplicitlyProvided()) {
            $preferenceSelection = $this->resolvePreferenceRoute($request);
            if ($preferenceSelection !== null) {
                $request = $request
                    ->withEngineAndModel($preferenceSelection['engine'], $preferenceSelection['model'])
                    ->withMetadata([
                        'route_resolution' => [
                            'engine' => $preferenceSelection['engine'],
                            'model' => $preferenceSelection['model'],
                            'reason' => $preferenceSelection['reason'] ?? null,
                            'requested_engine' => $request->getEngine()->value,
                            'requested_model' => $request->getModel()->value,
                        ],
                    ]);
            }
        }

        $selection = $request->wasEngineExplicitlyProvided()
            ? $this->resolveForExplicitEngine($request)
            : $this->resolveBestRoute($request);

        if ($selection === null) {
            return $request;
        }

        if (($selection['engine'] ?? null) === $request->getEngine()->value
            && ($selection['model'] ?? null) === $request->getModel()->value) {
            return $request;
        }

        return $request
            ->withEngineAndModel($selection['engine'], $selection['model'])
            ->withMetadata([
                'route_resolution' => [
                    'engine' => $selection['engine'],
                    'model' => $selection['model'],
                    'reason' => $selection['reason'] ?? null,
                    'requested_engine' => $request->getEngine()->value,
                    'requested_model' => $request->getModel()->value,
                ],
            ]);
    }

    protected function resolvePreferenceRoute(AIRequest $request): ?array
    {
        $preference = $this->resolveRequestPreference($request);
        if ($preference === null || $request->getContentType() !== 'text') {
            return null;
        }

        $recommendedModel = $this->modelRegistry->getRecommendedModel($preference);
        if ($recommendedModel === null) {
            return null;
        }

        $candidate = collect($this->candidateRoutesForModel($recommendedModel->model_id))
            ->first(fn (array $route): bool => $this->isCandidateAvailable(
                (string) $route['engine'],
                (string) $route['model']
            ));

        if ($candidate === null) {
            $engine = $this->providerToEngine($recommendedModel->provider);
            if ($engine === null || !$this->isCandidateAvailable($engine, $recommendedModel->model_id)) {
                return null;
            }

            return [
                'engine' => $engine,
                'model' => $recommendedModel->model_id,
                'reason' => 'preference_'.$preference,
            ];
        }

        return [
            'engine' => (string) $candidate['engine'],
            'model' => (string) $candidate['model'],
            'reason' => 'preference_'.$preference,
        ];
    }

    protected function resolveForExplicitEngine(AIRequest $request): ?array
    {
        $engine = $request->getEngine()->value;
        $model = $request->getModel()->value;

        if ($this->isCandidateAvailable($engine, $model)) {
            return [
                'engine' => $engine,
                'model' => $model,
                'reason' => 'explicit_engine_current_model',
            ];
        }

        $compatible = collect($this->candidateRoutesForModel($model))
            ->first(fn (array $candidate): bool => ($candidate['engine'] ?? null) === $engine);

        if ($compatible !== null && $this->isCandidateAvailable($engine, (string) $compatible['model'])) {
            return [
                'engine' => $engine,
                'model' => (string) $compatible['model'],
                'reason' => 'explicit_engine_compatible_model',
            ];
        }

        $defaultModel = $this->defaultModelForEngine($engine, $request->getContentType());
        if ($defaultModel !== null) {
            return [
                'engine' => $engine,
                'model' => $defaultModel,
                'reason' => 'explicit_engine_default_model',
            ];
        }

        return null;
    }

    protected function resolveBestRoute(AIRequest $request): ?array
    {
        $requestedModel = $request->getModel()->value;
        $contentType = $request->getContentType();

        $candidates = collect($this->candidateRoutesForModel($requestedModel));
        $best = $candidates->first(fn (array $candidate): bool => $this->isCandidateAvailable(
            (string) $candidate['engine'],
            (string) $candidate['model']
        ));

        if ($best !== null) {
            return [
                'engine' => (string) $best['engine'],
                'model' => (string) $best['model'],
                'reason' => (string) ($best['reason'] ?? 'compatible_model'),
            ];
        }

        if (!$request->wasModelExplicitlyProvided()) {
            return $this->fallbackRouteForContentType($contentType);
        }

        return null;
    }

    /**
     * Build ordered route candidates for a requested model id.
     *
     * Native providers are intentionally preferred over OpenRouter.
     *
     * @return array<int, array{engine:string,model:string,reason:string,score:int}>
     */
    protected function candidateRoutesForModel(string $requestedModel): array
    {
        $normalizedRequested = $this->normalizeModelId($requestedModel);
        $nativeEngine = $this->detectNativeEngine($requestedModel);
        $defaultEngine = (string) config('ai-engine.default', 'openai');
        $priority = $this->providerPriority($nativeEngine);
        $candidates = [];

        foreach ($this->exactAndCompatibleModels($requestedModel, $normalizedRequested) as $model) {
            $engine = $this->providerToEngine($model->provider);
            if ($engine === null) {
                continue;
            }

            $score = 1000 - ($this->providerRank($engine, $priority) * 100);
            if ($model->model_id === $requestedModel) {
                $score += 50;
            }
            if ($engine === $defaultEngine) {
                $score += 5;
            }

            $candidates[] = [
                'engine' => $engine,
                'model' => $model->model_id,
                'reason' => $model->model_id === $requestedModel ? 'registry_exact' : 'registry_compatible',
                'score' => $score,
            ];
        }

        if ($nativeEngine !== null) {
            $candidates[] = [
                'engine' => $nativeEngine,
                'model' => $requestedModel,
                'reason' => 'native_detected',
                'score' => 1000 - ($this->providerRank($nativeEngine, $priority) * 100) + 80,
            ];
        }

        foreach ($this->compatibleConfiguredModels($normalizedRequested, $nativeEngine, $defaultEngine, $priority) as $candidate) {
            $candidates[] = $candidate;
        }

        $deduped = [];
        foreach ($candidates as $candidate) {
            $key = $candidate['engine'].'|'.$candidate['model'];
            if (!isset($deduped[$key]) || $candidate['score'] > $deduped[$key]['score']) {
                $deduped[$key] = $candidate;
            }
        }

        $ordered = array_values($deduped);
        usort($ordered, static function (array $left, array $right): int {
            return ($right['score'] ?? 0) <=> ($left['score'] ?? 0);
        });

        return $ordered;
    }

    protected function exactAndCompatibleModels(string $requestedModel, string $normalizedRequested): Collection
    {
        return AIModel::active()
            ->get()
            ->filter(function (AIModel $model) use ($requestedModel, $normalizedRequested): bool {
                return $model->model_id === $requestedModel
                    || $this->normalizeModelId($model->model_id) === $normalizedRequested;
            })
            ->values();
    }

    /**
     * @return array<int, array{engine:string,model:string,reason:string,score:int}>
     */
    protected function compatibleConfiguredModels(
        string $normalizedRequested,
        ?string $nativeEngine,
        string $defaultEngine,
        array $priority
    ): array {
        $candidates = [];
        $engines = (array) config('ai-engine.engines', []);

        foreach ($engines as $engine => $engineConfig) {
            $models = (array) ($engineConfig['models'] ?? []);
            foreach ($models as $modelId => $modelConfig) {
                if ($this->normalizeModelId((string) $modelId) !== $normalizedRequested) {
                    continue;
                }

                $score = 900 - ($this->providerRank((string) $engine, $priority) * 100);
                if ($engine === $defaultEngine) {
                    $score += 5;
                }

                $candidates[] = [
                    'engine' => (string) $engine,
                    'model' => (string) $modelId,
                    'reason' => 'config_compatible',
                    'score' => $score,
                ];
            }
        }

        return $candidates;
    }

    protected function defaultModelForEngine(string $engine, string $contentType): ?string
    {
        $engineConfig = (array) config("ai-engine.engines.{$engine}", []);
        $configuredDefault = $engineConfig['default_model'] ?? null;
        if (is_string($configuredDefault) && $this->isCandidateAvailable($engine, $configuredDefault)) {
            return $configuredDefault;
        }

        $configuredModels = (array) ($engineConfig['models'] ?? []);
        foreach ($configuredModels as $modelId => $config) {
            if (!$this->isModelEnabled($config) || !$this->matchesContentType((string) $modelId, $contentType)) {
                continue;
            }

            return (string) $modelId;
        }

        $provider = $this->engineToProvider($engine);
        if ($provider !== null) {
            $registryModel = AIModel::active()
                ->where('provider', $provider)
                ->get()
                ->first(fn (AIModel $model): bool => $this->matchesContentType($model->model_id, $contentType));

            if ($registryModel !== null && $this->isEngineConfigured($engine)) {
                return $registryModel->model_id;
            }
        }

        try {
            $engineEnum = EngineEnum::from($engine);
            foreach ($engineEnum->getDefaultModels() as $defaultModel) {
                if ($this->isCandidateAvailable($engine, $defaultModel->value)
                    && $this->matchesContentType($defaultModel->value, $contentType)) {
                    return $defaultModel->value;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    protected function fallbackRouteForContentType(string $contentType): ?array
    {
        $defaultEngine = (string) config('ai-engine.default', EngineEnum::OpenAI->value);
        $priorities = array_values(array_unique(array_merge(
            [$defaultEngine],
            $this->providerPriority(null)
        )));

        foreach ($priorities as $engine) {
            $model = $this->defaultModelForEngine($engine, $contentType);
            if ($model !== null) {
                return [
                    'engine' => $engine,
                    'model' => $model,
                    'reason' => 'fallback_available',
                ];
            }
        }

        return null;
    }

    protected function isCandidateAvailable(string $engine, string $model): bool
    {
        if (!$this->isEngineConfigured($engine)) {
            return false;
        }

        if ($engine === EngineEnum::OpenRouter->value) {
            return str_contains($model, '/')
                || array_key_exists($model, (array) config('ai-engine.engines.openrouter.models', []))
                || AIModel::active()->where('provider', 'openrouter')->where('model_id', $model)->exists();
        }

        $provider = $this->engineToProvider($engine);
        if ($provider !== null && AIModel::active()->where('provider', $provider)->where('model_id', $model)->exists()) {
            return true;
        }

        $configuredModels = (array) config("ai-engine.engines.{$engine}.models", []);
        if (array_key_exists($model, $configuredModels)) {
            return $this->isModelEnabled($configuredModels[$model]);
        }

        try {
            return (new \LaravelAIEngine\Enums\EntityEnum($model))->engine()->value === $engine;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isEngineConfigured(string $engine): bool
    {
        $config = (array) config("ai-engine.engines.{$engine}", []);

        if ($engine === EngineEnum::Ollama->value) {
            return !empty($config['base_url']);
        }

        if ($engine === EngineEnum::OpenRouter->value) {
            return trim((string) ($config['api_key'] ?? '')) !== '';
        }

        return trim((string) ($config['api_key'] ?? '')) !== '';
    }

    protected function isModelEnabled(mixed $config): bool
    {
        if (!is_array($config)) {
            return true;
        }

        return (bool) ($config['enabled'] ?? true);
    }

    protected function resolveRequestPreference(AIRequest $request): ?string
    {
        $parameters = $request->getParameters();
        $metadata = $request->getMetadata();

        $raw = $metadata['routing_preference']
            ?? $parameters['routing_preference']
            ?? $metadata['task_type']
            ?? $parameters['task_type']
            ?? null;

        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'cost' => 'cheap',
            'speed' => 'fast',
            default => strtolower(trim($raw)),
        };
    }

    /**
     * @return array<int, string>
     */
    protected function providerPriority(?string $nativeEngine): array
    {
        $configured = config('ai-engine.request_routing.provider_priority', ['native', 'openrouter', 'anthropic', 'gemini']);
        $configured = is_array($configured) ? $configured : ['native', 'openrouter', 'anthropic', 'gemini'];

        $resolved = [];
        foreach ($configured as $entry) {
            $entry = strtolower(trim((string) $entry));
            if ($entry === '') {
                continue;
            }

            if ($entry === 'native') {
                if ($nativeEngine !== null && $nativeEngine !== '') {
                    $resolved[] = $nativeEngine;
                }

                continue;
            }

            $resolved[] = $entry;
        }

        $fallback = [
            EngineEnum::OpenAI->value,
            EngineEnum::Anthropic->value,
            EngineEnum::Gemini->value,
            EngineEnum::DeepSeek->value,
            EngineEnum::OpenRouter->value,
            EngineEnum::Ollama->value,
            EngineEnum::FalAI->value,
            EngineEnum::StableDiffusion->value,
            EngineEnum::ElevenLabs->value,
        ];

        return array_values(array_unique(array_merge($resolved, $fallback)));
    }

    protected function providerRank(string $engine, array $priority): int
    {
        $index = array_search($engine, $priority, true);

        return $index === false ? count($priority) + 10 : (int) $index;
    }

    protected function normalizeModelId(string $modelId): string
    {
        $normalized = strtolower(trim($modelId));
        if (str_contains($normalized, '/')) {
            $parts = explode('/', $normalized, 2);
            $normalized = $parts[1] ?? $normalized;
        }

        $normalized = str_replace(['.', '_'], '-', $normalized);
        $normalized = preg_replace('/:(free|beta|preview)$/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/-+/', '-', $normalized) ?? $normalized;

        return trim($normalized, '-');
    }

    protected function detectNativeEngine(string $modelId): ?string
    {
        try {
            $engine = (new \LaravelAIEngine\Enums\EntityEnum($modelId))->engine()->value;
            return $engine === EngineEnum::OpenRouter->value ? null : $engine;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function matchesContentType(string $modelId, string $contentType): bool
    {
        try {
            return (new \LaravelAIEngine\Enums\EntityEnum($modelId))->contentType() === $contentType;
        } catch (\Throwable) {
            return $contentType === 'text';
        }
    }

    protected function providerToEngine(string $provider): ?string
    {
        return match ($provider) {
            'google' => EngineEnum::Gemini->value,
            'stability', 'stability_ai' => EngineEnum::StableDiffusion->value,
            'elevenlabs' => EngineEnum::ElevenLabs->value,
            'fal', 'falai', 'fal_ai' => EngineEnum::FalAI->value,
            'openai', 'anthropic', 'deepseek', 'perplexity', 'midjourney', 'openrouter', 'ollama', 'serper', 'unsplash' => $provider,
            default => null,
        };
    }

    protected function engineToProvider(string $engine): ?string
    {
        return match ($engine) {
            EngineEnum::Gemini->value         => 'google',
            EngineEnum::StableDiffusion->value => 'stability',
            EngineEnum::ElevenLabs->value     => 'elevenlabs',
            EngineEnum::FalAI->value          => 'fal_ai',
            default => $engine,
        };
    }
}
