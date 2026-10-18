<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;

class AgentRuntimeManager implements AgentRuntimeContract
{
    /**
     * Runtimes resolved so far, keyed by runtime name. Entries from the
     * `ai-agent.runtime.drivers` map are built on first use, never eagerly.
     *
     * @var array<string, AgentRuntimeContract>
     */
    protected array $resolved = [];

    public function __construct(
        protected LaravelAgentRuntime $laravelRuntime,
        protected LangGraphAgentRuntime $langGraphRuntime,
        protected ?AgentExecutionPolicyService $policy = null,
        protected ?AIScopeOptionsService $scopeOptions = null,
        protected ?Container $container = null
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

    /**
     * Capabilities of every registered runtime (built-ins plus the
     * `ai-agent.runtime.drivers` map), keyed by runtime name. A driver that
     * cannot be built is reported instead of aborting the whole report.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableCapabilities(): array
    {
        $capabilities = [];

        foreach ($this->registeredRuntimes() as $name => $class) {
            try {
                $capabilities[$name] = $this->resolveRuntime($name)->capabilities()->toArray();
            } catch (\Throwable $e) {
                $capabilities[$name] = [
                    'available' => false,
                    'class' => $class,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $capabilities;
    }

    /**
     * Registered runtime drivers, keyed by runtime name.
     *
     * @return array<string, string>
     */
    public function registeredRuntimes(): array
    {
        return AgentRuntimeDriverMap::fromConfig();
    }

    public function hasRuntime(string $name): bool
    {
        return array_key_exists(AgentRuntimeDriverMap::normalizeName($name), $this->registeredRuntimes());
    }

    /**
     * Resolve a registered runtime by name. Built-in names return the injected
     * instances; other names are built from the container on first use and
     * cached for the lifetime of the manager.
     *
     * @throws \InvalidArgumentException when the name is not registered or the
     *                                   configured class does not implement AgentRuntimeContract
     */
    public function resolveRuntime(string $name): AgentRuntimeContract
    {
        $name = AgentRuntimeDriverMap::normalizeName($name);

        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $class = $this->registeredRuntimes()[$name] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException(
                "Agent runtime [{$name}] is not registered. Add it to ai-agent.runtime.drivers."
            );
        }

        $runtime = $this->builtInInstance($class) ?? $this->container()->make($class);

        if (!$runtime instanceof AgentRuntimeContract) {
            throw new \InvalidArgumentException(
                "Agent runtime [{$name}] resolved to [" . get_debug_type($runtime) . '] which does not implement ' . AgentRuntimeContract::class . '.'
            );
        }

        return $this->resolved[$name] = $runtime;
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
            $fallbackRuntime = $this->fallbackRuntime();
            $fallbackRuntimeName = $fallbackRuntime->name();
            if (!$this->policy()->canUseRuntime($fallbackRuntimeName, $options)) {
                return AgentResponse::failure(
                    message: $this->policy()->blockedMessage('runtime', $fallbackRuntimeName)
                );
            }

            $fallbackMissing = $this->missingRequiredCapabilities($fallbackRuntime->capabilities(), $options);
            if ($fallbackMissing !== []) {
                return AgentResponse::failure(
                    message: "Fallback runtime [{$fallbackRuntimeName}] for requested runtime [{$runtimeName}] is missing required capabilities: " . implode(', ', $fallbackMissing) . '.',
                    data: [
                        'runtime' => $fallbackRuntimeName,
                        'requested_runtime' => $runtimeName,
                        'missing_capabilities' => $fallbackMissing,
                        'capabilities' => $fallbackRuntime->capabilities()->toArray(),
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

    /**
     * Select the runtime for a request: the `agent_runtime` option, else the
     * configured default. A name that is not registered falls back to the
     * built-in Laravel runtime, matching the pre-driver-map behaviour.
     */
    protected function runtime(array $options = []): AgentRuntimeContract
    {
        $name = AgentRuntimeDriverMap::normalizeName(
            $options['agent_runtime'] ?? AgentRuntimeDriverMap::defaultName()
        );

        if ($name === '' || !$this->hasRuntime($name)) {
            $name = AgentRuntimeDriverMap::DEFAULT_RUNTIME;
        }

        return $this->resolveRuntime($name);
    }

    /**
     * The runtime an unavailable LangGraph request falls back to.
     */
    protected function fallbackRuntime(): AgentRuntimeContract
    {
        return $this->resolveRuntime(AgentRuntimeDriverMap::DEFAULT_RUNTIME);
    }

    /**
     * Return the constructor-injected instance when the configured class is
     * (or extends) one of the built-in runtimes, so the map never rebuilds
     * what the service registrar already wired.
     */
    protected function builtInInstance(string $class): ?AgentRuntimeContract
    {
        foreach ([$this->laravelRuntime, $this->langGraphRuntime] as $instance) {
            if ($instance instanceof $class) {
                return $instance;
            }
        }

        return null;
    }

    protected function container(): Container
    {
        return $this->container ??= app();
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
