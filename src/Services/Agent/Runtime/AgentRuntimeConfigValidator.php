<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiSdk;

class AgentRuntimeConfigValidator
{
    /**
     * @return array{passed:bool,issues:array<int,array{severity:string,code:string,message:string,subject:string}>}
     */
    public function validate(): array
    {
        $issues = array_merge(
            $this->runtimeDriverIssues(),
            $this->laravelAiIssues(),
            $this->runtimeIssues(),
            $this->toolIssues()
        );

        return [
            'passed' => collect($issues)->every(
                static fn (array $issue): bool => ($issue['severity'] ?? 'error') !== 'error'
            ),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<int,array{severity:string,code:string,message:string,subject:string}>
     */
    protected function runtimeIssues(): array
    {
        $issues = [];
        $default = AgentRuntimeDriverMap::defaultName();
        $allowed = AgentRuntimeDriverMap::names();

        if (!in_array($default, $allowed, true)) {
            $issues[] = $this->issue(
                'error',
                'invalid_runtime',
                'Configured default agent runtime must be one of: ' . implode(', ', $allowed) . '.',
                'ai-agent.runtime.default'
            );
        }

        $langGraphEnabled = (bool) config('ai-agent.runtime.langgraph.enabled', false);
        $langGraphBaseUrl = trim((string) config('ai-agent.runtime.langgraph.base_url', ''));
        $fallback = (bool) config('ai-agent.runtime.langgraph.fallback_to_laravel', true);

        if ($langGraphEnabled && $langGraphBaseUrl === '') {
            $issues[] = $this->issue(
                $default === 'langgraph' && !$fallback ? 'error' : 'warning',
                'langgraph_base_url_missing',
                'LangGraph runtime is enabled but no base URL is configured.',
                'ai-agent.runtime.langgraph.base_url'
            );
        }

        if ((int) config('ai-agent.runtime.langgraph.timeout', 120) <= 0) {
            $issues[] = $this->issue(
                'error',
                'invalid_langgraph_timeout',
                'LangGraph timeout must be greater than zero.',
                'ai-agent.runtime.langgraph.timeout'
            );
        }

        return $issues;
    }

    /**
     * Every `ai-agent.runtime.drivers` entry must name a loadable class that
     * implements AgentRuntimeContract; the manager resolves these lazily, so a
     * bad entry would otherwise only surface on the first request that selects it.
     *
     * @return array<int,array{severity:string,code:string,message:string,subject:string}>
     */
    protected function runtimeDriverIssues(): array
    {
        $configured = config('ai-agent.runtime.drivers', []);
        if ($configured !== null && !is_array($configured)) {
            return [$this->issue(
                'error',
                'invalid_runtime_drivers',
                'Agent runtime drivers must be an array of runtime name => class name.',
                'ai-agent.runtime.drivers'
            )];
        }

        $issues = [];

        foreach (AgentRuntimeDriverMap::configuredEntries() as $name => $class) {
            $label = (string) $name;
            $subject = "ai-agent.runtime.drivers.{$label}";

            if (AgentRuntimeDriverMap::normalizeName($name) === '') {
                $issues[] = $this->issue(
                    'error',
                    'invalid_runtime_driver_name',
                    'Agent runtime drivers must be keyed by a non-empty runtime name.',
                    $subject
                );

                continue;
            }

            if (!is_string($class) || trim($class) === '') {
                $issues[] = $this->issue(
                    'error',
                    'invalid_runtime_driver_class',
                    "Agent runtime driver [{$label}] must be configured with a class name.",
                    $subject
                );

                continue;
            }

            $class = ltrim(trim($class), '\\');

            if (!class_exists($class)) {
                $issues[] = $this->issue(
                    'error',
                    'missing_runtime_driver_class',
                    "Agent runtime driver [{$label}] references missing class [{$class}].",
                    $subject
                );

                continue;
            }

            if (!is_subclass_of($class, AgentRuntimeContract::class)) {
                $issues[] = $this->issue(
                    'error',
                    'runtime_driver_contract_mismatch',
                    "Agent runtime driver [{$label}] class [{$class}] must implement " . AgentRuntimeContract::class . '.',
                    $subject
                );
            }
        }

        return $issues;
    }

    /**
     * The optional laravel/ai runtime: switched on without the SDK installed,
     * or pointed at an agent or tool class that is not a laravel/ai type.
     *
     * @return array<int,array{severity:string,code:string,message:string,subject:string}>
     */
    protected function laravelAiIssues(): array
    {
        if (!LaravelAiSdk::enabled()) {
            return [];
        }

        if (!LaravelAiSdk::installed()) {
            return [$this->issue(
                AgentRuntimeDriverMap::defaultName() === LaravelAiSdk::RUNTIME ? 'error' : 'warning',
                'laravel_ai_sdk_missing',
                'Agent runtime [laravel_ai] is enabled but laravel/ai is not installed. Run `composer require laravel/ai` (requires PHP 8.3+ and Laravel 12+) or set AI_AGENT_LARAVEL_AI_ENABLED=false.',
                'ai-agent.runtime.laravel_ai.enabled'
            )];
        }

        $issues = [];
        $agent = config('ai-agent.runtime.laravel_ai.agent');

        if (is_string($agent) && trim($agent) !== '') {
            $agent = ltrim(trim($agent), '\\');

            if (!class_exists($agent) || !is_subclass_of($agent, LaravelAiSdk::AGENT_CONTRACT)) {
                $issues[] = $this->issue(
                    'error',
                    'invalid_laravel_ai_agent',
                    "laravel/ai agent [{$agent}] must be an existing class implementing " . LaravelAiSdk::AGENT_CONTRACT . '.',
                    'ai-agent.runtime.laravel_ai.agent'
                );
            }
        }

        foreach ((array) config('ai-agent.runtime.laravel_ai.tools', []) as $index => $tool) {
            $tool = is_string($tool) ? ltrim(trim($tool), '\\') : '';

            if ($tool === '' || !class_exists($tool) || !is_subclass_of($tool, 'Laravel\\Ai\\Contracts\\Tool')) {
                $issues[] = $this->issue(
                    'error',
                    'invalid_laravel_ai_tool',
                    'laravel/ai tool [' . ($tool === '' ? (string) $index : $tool) . '] must be an existing class implementing Laravel\\Ai\\Contracts\\Tool.',
                    "ai-agent.runtime.laravel_ai.tools.{$index}"
                );
            }
        }

        $packageTools = config('ai-agent.runtime.laravel_ai.package_tools', false);
        if (is_string($packageTools) || is_array($packageTools)) {
            $registered = array_keys(app(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)->all());
            $names = is_string($packageTools) ? explode(',', $packageTools) : $packageTools;

            foreach ($names as $name) {
                $name = is_string($name) ? trim($name) : '';
                if ($name !== '' && !in_array($name, $registered, true)) {
                    $issues[] = $this->issue(
                        'warning',
                        'unknown_laravel_ai_package_tool',
                        "laravel_ai package tool [{$name}] is not a registered agent tool and will not be exposed.",
                        'ai-agent.runtime.laravel_ai.package_tools'
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @return array<int,array{severity:string,code:string,message:string,subject:string}>
     */
    protected function toolIssues(): array
    {
        $issues = [];

        foreach ((array) config('ai-agent.tools', []) as $name => $class) {
            if (!is_string($class) || trim($class) === '') {
                $issues[] = $this->issue(
                    'error',
                    'invalid_tool_class',
                    "Tool [{$name}] must be configured with a class name.",
                    "ai-agent.tools.{$name}"
                );

                continue;
            }

            if (!class_exists($class)) {
                $issues[] = $this->issue(
                    'error',
                    'missing_tool_class',
                    "Tool [{$name}] references missing class [{$class}].",
                    "ai-agent.tools.{$name}"
                );
            }
        }

        return $issues;
    }

    /**
     * @return array{severity:string,code:string,message:string,subject:string}
     */
    protected function issue(string $severity, string $code, string $message, string $subject): array
    {
        return compact('severity', 'code', 'message', 'subject');
    }
}
