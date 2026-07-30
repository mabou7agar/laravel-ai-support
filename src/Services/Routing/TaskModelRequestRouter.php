<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Routing;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\TaskModelRoute;

final class TaskModelRequestRouter
{
    public function __construct(private readonly TaskModelRouteRegistry $routes)
    {
    }

    public function apply(AIRequest $request, ?string $task = null, bool $overrideExplicit = false): AIRequest
    {
        $task = $this->task($request, $task);
        $route = $task !== null ? $this->routes->route($task) : null;
        if (!$route instanceof TaskModelRoute) {
            return $request;
        }
        if (!$overrideExplicit && $request->wasEngineExplicitlyProvided() && $request->wasModelExplicitlyProvided()) {
            return $request;
        }

        return $this->applyTarget($request, $route, false);
    }

    public function fallback(AIRequest $request, ?string $task = null): AIRequest
    {
        $task = $this->task($request, $task);
        $route = $task !== null ? $this->routes->route($task) : null;
        if (!$route instanceof TaskModelRoute
            || $route->fallbackEngine === null
            || $route->fallbackModel === null) {
            return $request;
        }

        return $this->applyTarget($request, $route, true);
    }

    private function applyTarget(AIRequest $request, TaskModelRoute $route, bool $fallback): AIRequest
    {
        $engine = $fallback ? $route->fallbackEngine : $route->engine;
        $model = $fallback ? $route->fallbackModel : $route->model;
        if ($engine === null || $model === null || $engine === '' || $model === '') {
            return $request;
        }

        return $request
            ->withEngineAndModel($engine, $model)
            ->withParameters(array_merge($route->parameters, $request->getParameters()))
            ->withMetadata([
                'task_model_route' => [
                    ...$route->toArray(),
                    'selected' => $fallback ? 'fallback' : 'primary',
                ],
            ]);
    }

    private function task(AIRequest $request, ?string $task): ?string
    {
        $task = trim((string) ($task
            ?? $request->getMetadata()['task']
            ?? $request->getMetadata()['ai_task']
            ?? ''));

        return $task !== '' ? $task : null;
    }
}
