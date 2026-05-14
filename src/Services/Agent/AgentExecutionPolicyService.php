<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

class AgentExecutionPolicyService
{
    public function canUseRuntime(string $runtime, array $context = []): bool
    {
        return $this->isAllowed('runtime', $runtime);
    }

    public function canUseTool(string $tool, array $context = []): bool
    {
        return $this->isAllowed('tool', $tool);
    }

    public function canUseSubAgent(string $subAgent, array $context = []): bool
    {
        return $this->isAllowed('sub_agent', $subAgent);
    }

    public function canUseRagCollection(string $collection, array $context = []): bool
    {
        return $this->isAllowed('rag_collection', $collection);
    }

    public function canRouteToNode(string $node, array $context = []): bool
    {
        return $this->isAllowed('node', $node);
    }

    public function blockedMessage(string $type, string $name): string
    {
        return sprintf('Agent %s [%s] is blocked by execution policy.', str_replace('_', ' ', $type), $name);
    }

    public function sanitizePayloadForRuntime(string $runtime, array $payload): array
    {
        if ($runtime !== 'langgraph') {
            return $payload;
        }

        return $this->redactSensitive($payload);
    }

    public function redactSensitive(array $payload): array
    {
        $keys = array_map('strtolower', (array) config('ai-agent.execution_policy.sensitive_keys', [
            'password',
            'token',
            'secret',
            'api_key',
            'authorization',
        ]));

        $redacted = [];
        foreach ($payload as $key => $value) {
            $keyString = strtolower((string) $key);
            $redacted[$key] = in_array($keyString, $keys, true)
                ? '[redacted]'
                : (is_array($value) ? $this->redactSensitive($value) : $value);
        }

        return $redacted;
    }

    private function isAllowed(string $type, string $name): bool
    {
        $name = trim($name);
        if ($name === '') {
            return true;
        }

        $deny = $this->policyList("{$type}_deny");
        if ($this->matches($name, $deny)) {
            return false;
        }

        $allow = $this->policyList("{$type}_allow");

        return $allow === [] || $this->matches($name, $allow);
    }

    /**
     * @return array<int, string>
     */
    private function policyList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            (array) config("ai-agent.execution_policy.{$key}", [])
        )));
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matches(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || $pattern === $name || fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }
}
