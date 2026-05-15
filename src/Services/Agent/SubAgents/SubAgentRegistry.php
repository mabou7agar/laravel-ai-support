<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Contracts\SubAgentHandler;

class SubAgentRegistry
{
    protected array $agents;

    public function __construct(
        protected Container $container,
        ?array $agents = null
    ) {
        $this->agents = $agents ?? (array) config('ai-agent.sub_agents', []);
    }

    public function all(): array
    {
        return $this->agents;
    }

    public function get(string $agentId): ?array
    {
        $definition = $this->agents[$agentId] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function has(string $agentId): bool
    {
        return $this->get($agentId) !== null;
    }

    public function resolveHandler(string $agentId): ?SubAgentHandler
    {
        $definition = $this->get($agentId);
        if (!$definition) {
            return null;
        }

        $handler = $definition['handler'] ?? null;
        if (($handler === null && !empty($definition['tools'])) || in_array($handler, ['tool', 'tools'], true)) {
            return $this->container->make(ToolCallingSubAgentHandler::class);
        }

        if ($handler === null) {
            return null;
        }

        if ($handler instanceof SubAgentHandler) {
            return $handler;
        }

        if (is_string($handler) && class_exists($handler)) {
            $resolved = $this->container->make($handler);

            return $resolved instanceof SubAgentHandler ? $resolved : null;
        }

        if (is_callable($handler)) {
            return new CallableSubAgentHandler($handler);
        }

        return null;
    }
}
