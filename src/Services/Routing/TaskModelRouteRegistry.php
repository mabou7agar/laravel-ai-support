<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Routing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LaravelAIEngine\Contracts\TaskModelRouteProvider;
use LaravelAIEngine\DTOs\TaskModelRoute;
use RuntimeException;

final class TaskModelRouteRegistry
{
    /** @var array<string, TaskModelRoute> */
    private array $runtimeRoutes = [];

    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
    ) {
    }

    public function register(TaskModelRoute $route): void
    {
        if ($route->task === '') {
            throw new \InvalidArgumentException('A task model route requires a non-empty task.');
        }

        $this->runtimeRoutes[$route->task] = $route;
    }

    public function route(string $task): ?TaskModelRoute
    {
        return $this->routes()[$task] ?? null;
    }

    /** @return array<string, TaskModelRoute> */
    public function routes(): array
    {
        $routes = [];
        foreach ((array) $this->config->get('ai-agent.assistant.model_routes.routes', []) as $task => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $route = TaskModelRoute::fromArray($definition, is_string($task) ? $task : null);
            if ($route->task !== '' && $route->enabled) {
                $routes[$route->task] = $route;
            }
        }

        foreach ($this->providers() as $provider) {
            foreach ($provider->routes() as $definition) {
                $route = $definition instanceof TaskModelRoute
                    ? $definition
                    : TaskModelRoute::fromArray((array) $definition);
                if ($route->task !== '' && $route->enabled) {
                    $routes[$route->task] = $route;
                }
            }
        }

        return [...$routes, ...$this->runtimeRoutes];
    }

    /** @return list<TaskModelRouteProvider> */
    private function providers(): array
    {
        $providers = [];
        foreach ((array) $this->config->get('ai-agent.assistant.model_routes.providers', []) as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }
            $provider = $this->container->make($class);
            if (!$provider instanceof TaskModelRouteProvider) {
                throw new RuntimeException(sprintf(
                    'Task model route provider [%s] must implement %s.',
                    $class,
                    TaskModelRouteProvider::class,
                ));
            }
            $providers[] = $provider;
        }

        return $providers;
    }
}
