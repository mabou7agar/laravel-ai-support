<?php

namespace LaravelAIEngine\Services\Agent;

class OrchestratorDecisionParser
{
    public function __construct(
        protected array $config = []
    ) {
    }

    public function parse(string $response): array
    {
        $defaultAction = (string) $this->getConfig('default_action', 'conversational');
        $allowedActions = (array) $this->getConfig('allowed_actions', [
            'start_collector',
            'use_tool',
            'route_to_node',
            'resume_session',
            'pause_and_handle',
            'search_rag',
            'conversational',
        ]);

        $decision = [
            'action' => $defaultAction,
            'resource_name' => null,
            'reasoning' => 'Default fallback',
        ];

        if (preg_match('/ACTION:\s*([a-z_]+)/i', $response, $matches)) {
            $candidate = strtolower(trim($matches[1]));
            if (in_array($candidate, $allowedActions, true)) {
                $decision['action'] = $candidate;
            }
        }

        if (preg_match('/RESOURCE:\s*(.+?)(?:\r?\n|$)/i', $response, $matches)) {
            $resourceName = trim($matches[1]);
            if ($resourceName !== '' && strtolower($resourceName) !== 'none' && strtolower($resourceName) !== 'null') {
                $decision['resource_name'] = $resourceName;
            }
        }

        if (preg_match('/REASON:\s*(.+)/i', $response, $matches)) {
            $decision['reasoning'] = trim($matches[1]);
        }

        return $decision;
    }

    protected function getConfig(string $key, $default = null)
    {
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        try {
            return match ($key) {
                'default_action' => config('ai-agent.orchestrator.default_action', $default),
                'allowed_actions' => config('ai-agent.orchestrator.allowed_actions', $default),
                default => $default,
            };
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
