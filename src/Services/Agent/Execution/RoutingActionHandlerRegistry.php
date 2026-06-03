<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Execution;

use LaravelAIEngine\Contracts\RoutingActionHandlerContract;

class RoutingActionHandlerRegistry
{
    /**
     * @var array<string, RoutingActionHandlerContract>
     */
    protected array $handlers = [];

    public function register(RoutingActionHandlerContract $handler): self
    {
        $this->handlers[$handler->action()] = $handler;

        return $this;
    }

    public function has(string $action): bool
    {
        return isset($this->handlers[$action]);
    }

    public function get(string $action): ?RoutingActionHandlerContract
    {
        return $this->handlers[$action] ?? null;
    }

    /**
     * @return array<string, RoutingActionHandlerContract>
     */
    public function all(): array
    {
        return $this->handlers;
    }
}
