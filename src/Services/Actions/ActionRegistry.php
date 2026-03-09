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
    public function register(array|string $definition, ?array $legacyDefinition = null): void
    {
        // Backward compatibility: register('action_id', [...])
        if (is_string($definition)) {
            $payload = $legacyDefinition ?? [];
            $payload['id'] = $payload['id'] ?? $definition;
            $this->actions[$payload['id']] = $payload;

            return;
        }

        if (!isset($definition['id']) || !is_string($definition['id']) || trim($definition['id']) === '') {
            throw new InvalidArgumentException('Action definition must include a non-empty string id.');
        }

        $this->actions[$definition['id']] = $definition;
    }

    /**
     * Register multiple action definitions.
     *
     * @param array<string, array> $definitions
     */
    public function registerBatch(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            if (is_array($definition) && !isset($definition['id']) && is_string($id)) {
                $definition['id'] = $id;
            }

            $this->register($definition);
        }
    }

    /**
     * Get all registered actions
     */
    public function all(): array
    {
        return $this->actions;
    }

    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
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
                    $matches[$action['id']] = $action;
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

    public function getByType(string $type): array
    {
        return array_filter($this->actions, fn (array $action) => ($action['type'] ?? null) === $type);
    }

    /**
     * Discover model actions from common app models.
     * Keeps behavior lightweight for package/testing contexts.
     */
    public function discoverFromModels(): void
    {
        $candidates = [
            \App\Models\Product::class,
            \App\Models\Order::class,
        ];

        foreach ($candidates as $modelClass) {
            if (!class_exists($modelClass)) {
                continue;
            }

            $model = class_basename($modelClass);
            $id = 'create_' . strtolower($model);

            if (isset($this->actions[$id])) {
                continue;
            }

            $this->actions[$id] = [
                'id' => $id,
                'type' => 'model_action',
                'label' => "Create {$model}",
                'description' => "Create a new {$model}",
                'executor' => 'model.dynamic',
                'model_class' => $modelClass,
                'required_params' => ['name'],
                'optional_params' => ['description'],
                'triggers' => [strtolower($model), "create {$model}"],
                'enabled' => true,
            ];
        }

        if (empty($this->actions)) {
            $this->actions['create_item'] = [
                'id' => 'create_item',
                'type' => 'model_action',
                'label' => 'Create Item',
                'description' => 'Create a new item',
                'executor' => 'model.dynamic',
                'required_params' => ['name'],
                'optional_params' => [],
                'triggers' => ['create item'],
                'enabled' => true,
            ];
        }
    }

    public function unregister(string $id): void
    {
        unset($this->actions[$id]);
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $byType = [];
        foreach ($this->actions as $action) {
            $type = $action['type'] ?? 'unknown';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }

        return [
            'total' => count($this->actions),
            'enabled' => count($this->getEnabled()),
            'handlers' => count($this->handlers),
            'executors' => count($this->executors),
            'by_type' => $byType,
        ];
    }

    /**
     * Clear cache (in-memory)
     */
    public function clearCache(): void
    {
        $this->actions = [];
    }

    /**
     * Backward-compatible alias.
     */
    public function clear(): void
    {
        $this->clearCache();
    }
}
