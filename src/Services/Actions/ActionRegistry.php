<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use InvalidArgumentException;

class ActionRegistry
{
    /** @var array<string, callable> */
    protected array $handlers = [];

    /** @var array<string, callable> */
    protected array $executors = [];

    /**
     * Register a legacy action handler
     */
    public function registerHandler(string $actionType, callable $handler): void
    {
        $this->handlers[$actionType] = $handler;
    }

    /**
     * Register a smart executor
     */
    public function registerExecutor(string $executorId, callable $executor): void
    {
        $this->executors[$executorId] = $executor;
    }

    /**
     * Get a handler
     */
    public function getHandler(string $actionType): ?callable
    {
        return $this->handlers[$actionType] ?? null;
    }

    /**
     * Get an executor
     */
    public function getExecutor(string $executorId): ?callable
    {
        return $this->executors[$executorId] ?? null;
    }

    /**
     * Check if handler exists
     */
    public function hasHandler(string $actionType): bool
    {
        return isset($this->handlers[$actionType]);
    }

    /**
     * Check if executor exists
     */
    public function hasExecutor(string $executorId): bool
    {
        return isset($this->executors[$executorId]);
    }
    /**
     * @var array<string, array>
     */
    protected array $actions = [];

    /**
     * Register an action definition
     */
    public function register(array $definition): void
    {
        $this->actions[$definition['id']] = $definition;
    }

    /**
     * Get all registered actions
     */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Get enabled actions
     */
    public function getEnabled(): array
    {
        return array_filter($this->actions, fn($a) => $a['enabled'] ?? true);
    }

    /**
     * Get action by ID
     */
    public function get(string $id): ?array
    {
        return $this->actions[$id] ?? null;
    }

    /**
     * Find actions by trigger
     */
    public function findByTrigger(string $keyword): array
    {
        $matches = [];
        $keyword = strtolower($keyword);

        foreach ($this->actions as $action) {
            foreach ($action['triggers'] ?? [] as $trigger) {
                if (str_contains(strtolower($trigger), $keyword)) {
                    $matches[] = $action;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Find actions by model class
     */
    public function findByModel(string $modelClass): array
    {
        $matches = [];
        foreach ($this->actions as $action) {
            if (($action['model_class'] ?? '') === $modelClass) {
                $matches[] = $action;
            }
        }
        return $matches;
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => count($this->actions),
            'enabled' => count($this->getEnabled()),
            'handlers' => count($this->handlers),
            'executors' => count($this->executors),
        ];
    }

    /**
     * Clear cache (in-memory)
     */
    public function clearCache(): void
    {
        $this->actions = [];
    }
}
