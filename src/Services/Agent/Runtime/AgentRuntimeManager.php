<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;

class AgentRuntimeManager implements AgentRuntimeContract
{
    public function __construct(
        protected LaravelAgentRuntime $laravelRuntime,
        protected LangGraphAgentRuntime $langGraphRuntime,
        protected ?AgentExecutionPolicyService $policy = null,
        protected ?AIScopeOptionsService $scopeOptions = null
    ) {
        $this->scopeOptions ??= app()->bound(AIScopeOptionsService::class)
            ? app(AIScopeOptionsService::class)
            : null;
    }

    public function name(): string
    {
        return $this->runtime()->name();
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return $this->runtime()->capabilities();
    }

    public function availableCapabilities(): array
    {
        return [
            'laravel' => $this->laravelRuntime->capabilities()->toArray(),
            'langgraph' => $this->langGraphRuntime->capabilities()->toArray(),
        ];
    }

    public function process(
        string $message,
        string $sessionId,
        mixed $userId,
        array $options = []
    ): AgentResponse {
        $options = $this->scopeOptions?->merge($userId, $options) ?? $options;
        $runtime = $this->runtime($options);
        $runtimeName = $runtime->name();

        if (!$this->policy()->canUseRuntime($runtimeName, $options)) {
            return AgentResponse::failure(
                message: $this->policy()->blockedMessage('runtime', $runtimeName)
            );
        }

        $missing = $this->missingRequiredCapabilities($runtime->capabilities(), $options);
        if ($this->langGraphFallbackWillBeUsed($runtimeName, $options)) {
            $fallbackRuntimeName = $this->laravelRuntime->name();
            if (!$this->policy()->canUseRuntime($fallbackRuntimeName, $options)) {
                return AgentResponse::failure(
                    message: $this->policy()->blockedMessage('runtime', $fallbackRuntimeName)
                );
            }

            $fallbackMissing = $this->missingRequiredCapabilities($this->laravelRuntime->capabilities(), $options);
            if ($fallbackMissing !== []) {
                return AgentResponse::failure(
                    message: "Fallback runtime [{$fallbackRuntimeName}] for requested runtime [{$runtimeName}] is missing required capabilities: " . implode(', ', $fallbackMissing) . '.',
                    data: [
                        'runtime' => $fallbackRuntimeName,
                        'requested_runtime' => $runtimeName,
                        'missing_capabilities' => $fallbackMissing,
                        'capabilities' => $this->laravelRuntime->capabilities()->toArray(),
                    ]
                );
            }
        } elseif ($missing !== []) {
            return AgentResponse::failure(
                message: "Agent runtime [{$runtimeName}] is missing required capabilities: " . implode(', ', $missing) . '.',
                data: [
                    'runtime' => $runtimeName,
                    'missing_capabilities' => $missing,
                    'capabilities' => $runtime->capabilities()->toArray(),
                ]
            );
        }

        return $runtime->process($message, $sessionId, $userId, $options);
    }

    protected function missingRequiredCapabilities(AgentRuntimeCapabilities $capabilities, array $options): array
    {
        $required = array_values(array_unique(array_filter(array_merge(
            (array) ($options['requires_capabilities'] ?? []),
            $this->inferredRequiredCapabilities($options)
        ))));

        $available = $capabilities->toArray();

        return array_values(array_filter($required, static fn (string $capability): bool => ($available[$capability] ?? false) !== true));
    }

    protected function inferredRequiredCapabilities(array $options): array
    {
        return array_filter([
            (($options['stream'] ?? $options['streaming'] ?? false) === true) ? 'streaming' : null,
            !empty($options['tools'] ?? []) ? 'tools' : null,
            !empty($options['sub_agents'] ?? []) ? 'sub_agents' : null,
            (($options['require_human_approvals'] ?? false) === true) ? 'human_approvals' : null,
            (($options['require_remote_callbacks'] ?? false) === true) ? 'remote_callbacks' : null,
            (($options['require_artifacts'] ?? false) === true) ? 'artifacts' : null,
        ]);
    }

    protected function runtime(array $options = []): AgentRuntimeContract
    {
        $runtime = strtolower(trim((string) ($options['agent_runtime'] ?? config('ai-agent.runtime.default', 'laravel'))));

        return match ($runtime) {
            'langgraph' => $this->langGraphRuntime,
            default => $this->laravelRuntime,
        };
    }

    protected function langGraphFallbackWillBeUsed(string $runtimeName, array $options): bool
    {
        return $runtimeName === 'langgraph'
            && $this->langGraphFallbackEnabled($options)
            && !$this->langGraphAvailable();
    }

    protected function langGraphFallbackEnabled(array $options): bool
    {
        return array_key_exists('fallback_to_laravel', $options)
            ? (bool) $options['fallback_to_laravel']
            : (bool) config('ai-agent.runtime.langgraph.fallback_to_laravel', true);
    }

    protected function langGraphAvailable(): bool
    {
        return (bool) config('ai-agent.runtime.langgraph.enabled', false)
            && trim((string) config('ai-agent.runtime.langgraph.base_url', '')) !== '';
    }

    protected function policy(): AgentExecutionPolicyService
    {
        if ($this->policy instanceof AgentExecutionPolicyService) {
            return $this->policy;
        }

        return $this->policy = app()->bound(AgentExecutionPolicyService::class)
            ? app(AgentExecutionPolicyService::class)
            : new AgentExecutionPolicyService();
    }
}
