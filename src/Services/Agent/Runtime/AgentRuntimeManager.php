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
        if (!$this->policy()->canUseRuntime($runtime->name(), $options)) {
            return AgentResponse::failure(
                message: $this->policy()->blockedMessage('runtime', $runtime->name())
            );
        }

        $missing = $this->missingRequiredCapabilities($runtime->capabilities(), $options);
        if ($missing !== []) {
            return AgentResponse::failure(
                message: "Agent runtime [{$runtime->name()}] is missing required capabilities: " . implode(', ', $missing) . '.',
                data: [
                    'runtime' => $runtime->name(),
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
