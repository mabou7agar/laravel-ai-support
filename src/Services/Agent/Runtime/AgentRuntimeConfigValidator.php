<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Contracts\AgentRuntimeContract;

class AgentRuntimeConfigValidator
{
    /**
     * @return array{passed:bool,issues:array<int,array{severity:string,code:string,message:string,subject:string}>}
     */
    public function validate(): array
    {
        $issues = array_merge(
            $this->runtimeDriverIssues(),
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
