<?php

namespace LaravelAIEngine\Services\Agent;

class AgentPlanner
{
    protected const SUPPORTED_ACTIONS = [
        'start_collector',
        'use_tool',
        'resume_session',
        'pause_and_handle',
        'route_to_node',
        'search_rag',
        'conversational',
    ];

    public function plan(array $decision): array
    {
        $action = strtolower(trim((string) ($decision['action'] ?? '')));
        if (!in_array($action, self::SUPPORTED_ACTIONS, true)) {
            $action = 'search_rag';
        }

        $resourceName = $decision['resource_name'] ?? null;
        if (!is_string($resourceName) || trim($resourceName) === '' || trim($resourceName) === 'none') {
            $resourceName = null;
        }

        return [
            'action' => $action,
            'resource_name' => $resourceName,
            'params' => is_array($decision['params'] ?? null) ? $decision['params'] : [],
            'reasoning' => $decision['reasoning'] ?? 'Default fallback',
        ];
    }

    /**
     * @param  array<string, callable(array): mixed>  $handlers
     */
    public function dispatch(array $decision, array $handlers, string $fallbackAction = 'search_rag')
    {
        $plan = $this->plan($decision);
        $action = $plan['action'];

        $handler = $handlers[$action] ?? $handlers[$fallbackAction] ?? null;
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException("No callable handler registered for action [{$action}]");
        }

        return $handler($plan);
    }
}
