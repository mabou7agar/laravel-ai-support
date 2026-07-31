<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Routing;

use Illuminate\Contracts\Config\Repository;
use LaravelAIEngine\DTOs\ModelRouteReadinessReport;
use LaravelAIEngine\DTOs\TaskModelRoute;

final class ModelRouteReadinessService
{
    public function __construct(
        private readonly TaskModelRouteRegistry $routes,
        private readonly Repository $config,
    ) {
    }

    /** @return array<string, ModelRouteReadinessReport> */
    public function inspect(?string $task = null): array
    {
        $reports = [];
        foreach ($this->routes->routes() as $name => $route) {
            if ($task !== null && $task !== $name) {
                continue;
            }
            $reports[$name] = new ModelRouteReadinessReport($route, $this->issues($route));
        }

        return $reports;
    }

    /** @return list<string> */
    private function issues(TaskModelRoute $route): array
    {
        $issues = [];
        if ($route->engine === '') {
            $issues[] = 'primary_engine_missing';
        }
        if ($route->model === '') {
            $issues[] = 'primary_model_missing';
        }
        $issues = [...$issues, ...$this->targetIssues('primary', $route->engine, $route->model)];

        $hasFallbackEngine = $route->fallbackEngine !== null;
        $hasFallbackModel = $route->fallbackModel !== null;
        if ($hasFallbackEngine !== $hasFallbackModel) {
            $issues[] = 'fallback_route_incomplete';
        }
        if ($hasFallbackEngine && $hasFallbackModel) {
            if ($route->fallbackEngine === $route->engine && $route->fallbackModel === $route->model) {
                $issues[] = 'fallback_matches_primary';
            }
            $issues = [
                ...$issues,
                ...$this->targetIssues('fallback', $route->fallbackEngine, $route->fallbackModel),
            ];
        }

        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    private function targetIssues(string $prefix, ?string $engine, ?string $model): array
    {
        if ($engine === null || $engine === '' || $model === null || $model === '') {
            return [];
        }

        $engineConfig = $this->config->get("ai-engine.engines.{$engine}");
        if (!is_array($engineConfig)) {
            return ["{$prefix}_engine_unknown"];
        }

        $models = is_array($engineConfig['models'] ?? null) ? $engineConfig['models'] : [];
        $strict = (bool) $this->config->get(
            'ai-agent.assistant.model_routes.require_registered_models',
            true,
        );
        if ($strict && !array_key_exists($model, $models)) {
            return ["{$prefix}_model_unregistered"];
        }
        if (array_key_exists($model, $models)
            && is_array($models[$model])
            && ($models[$model]['enabled'] ?? true) === false) {
            return ["{$prefix}_model_disabled"];
        }

        return [];
    }
}
