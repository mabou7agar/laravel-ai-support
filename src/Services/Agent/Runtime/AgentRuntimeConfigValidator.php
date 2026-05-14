<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

class AgentRuntimeConfigValidator
{
    /**
     * @return array{passed:bool,issues:array<int,array{severity:string,code:string,message:string,subject:string}>}
     */
    public function validate(): array
    {
        $issues = array_merge(
            $this->runtimeIssues(),
            $this->routingStageIssues(),
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
        $default = strtolower(trim((string) config('ai-agent.runtime.default', 'laravel')));
        $allowed = ['laravel', 'langgraph'];

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
     * @return array<int,array{severity:string,code:string,message:string,subject:string}>
     */
    protected function routingStageIssues(): array
    {
        $issues = [];
        $stages = (array) config('ai-agent.routing_pipeline.stages', []);

        if ($stages === []) {
            return [
                $this->issue(
                    'error',
                    'routing_stages_empty',
                    'At least one routing stage must be configured.',
                    'ai-agent.routing_pipeline.stages'
                ),
            ];
        }

        foreach ($stages as $index => $stage) {
            if (!is_string($stage) || trim($stage) === '') {
                $issues[] = $this->issue(
                    'error',
                    'invalid_routing_stage',
                    "Routing stage at index [{$index}] must be a class name.",
                    "ai-agent.routing_pipeline.stages.{$index}"
                );

                continue;
            }

            if (!class_exists($stage)) {
                $issues[] = $this->issue(
                    'error',
                    'missing_routing_stage',
                    "Routing stage [{$stage}] does not exist.",
                    "ai-agent.routing_pipeline.stages.{$index}"
                );

                continue;
            }

            if (!is_subclass_of($stage, \LaravelAIEngine\Contracts\RoutingStageContract::class)) {
                $issues[] = $this->issue(
                    'error',
                    'invalid_routing_stage_contract',
                    "Routing stage [{$stage}] must implement RoutingStageContract.",
                    "ai-agent.routing_pipeline.stages.{$index}"
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
