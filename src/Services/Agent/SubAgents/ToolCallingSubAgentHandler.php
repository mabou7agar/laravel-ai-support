<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\SubAgentHandler;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class ToolCallingSubAgentHandler implements SubAgentHandler
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly SubAgentRegistry $subAgents
    ) {
    }

    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult {
        $toolSpecs = $this->toolSpecs($task, $options);
        if ($toolSpecs === []) {
            return SubAgentResult::failure(
                $task->id,
                $task->agentId,
                "Sub-agent '{$task->agentId}' has no tools to execute."
            );
        }

        $results = [];

        foreach ($toolSpecs as $toolSpec) {
            $toolName = $this->toolName($toolSpec);
            if ($toolName === '') {
                continue;
            }

            $tool = $this->tools->get($toolName);
            if ($tool === null) {
                return SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    "Tool '{$toolName}' is not registered.",
                    ['tool_results' => $results]
                );
            }

            $parameters = $this->parametersFor($toolSpec, $task, $previousResults);
            $errors = $tool->validate($parameters);
            if ($errors !== []) {
                return SubAgentResult::needsUserInput(
                    $task->id,
                    $task->agentId,
                    implode("\n", $errors),
                    ['tool_results' => $results],
                    ['required_inputs' => $errors, 'tool_name' => $toolName]
                );
            }

            $result = $tool->execute($parameters, $context);
            $results[$toolName] = $result->toArray();

            if ($result->requiresUserInput()) {
                return SubAgentResult::needsUserInput(
                    $task->id,
                    $task->agentId,
                    $result->message ?? 'More information is required.',
                    ['tool_results' => $results],
                    ['tool_name' => $toolName, 'required_inputs' => $result->metadata['required_inputs'] ?? []]
                );
            }

            if (!$result->success) {
                return SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    $result->error ?? $result->message ?? "Tool '{$toolName}' failed.",
                    ['tool_results' => $results],
                    ['tool_name' => $toolName]
                );
            }
        }

        return SubAgentResult::success(
            $task->id,
            $task->agentId,
            'Tool-backed sub-agent completed.',
            ['tool_results' => $results],
            ['tool_count' => count($results)]
        );
    }

    /**
     * @return array<int, string|array<string, mixed>>
     */
    private function toolSpecs(SubAgentTask $task, array $options): array
    {
        $definition = $this->subAgents->get($task->agentId) ?? [];
        $tools = $task->input['tools']
            ?? $task->metadata['tools']
            ?? $options['tools']
            ?? $definition['tools']
            ?? [];

        return is_array($tools) ? array_values($tools) : [];
    }

    private function toolName(string|array $toolSpec): string
    {
        if (is_string($toolSpec)) {
            return trim($toolSpec);
        }

        return trim((string) ($toolSpec['name'] ?? $toolSpec['tool'] ?? ''));
    }

    /**
     * @param string|array<string, mixed> $toolSpec
     * @param array<string, SubAgentResult> $previousResults
     * @return array<string, mixed>
     */
    private function parametersFor(string|array $toolSpec, SubAgentTask $task, array $previousResults): array
    {
        if (is_array($toolSpec)) {
            $parameters = $toolSpec['parameters'] ?? $toolSpec['params'] ?? null;
            if (is_array($parameters)) {
                return $parameters;
            }
        }

        $input = $task->input;
        unset($input['tools']);

        if ($input === []) {
            $input = ['input' => $task->objective];
        }

        if ($task->dependsOn !== []) {
            $input['previous_results'] = array_intersect_key(
                array_map(static fn (SubAgentResult $result): array => $result->toArray(), $previousResults),
                array_flip($task->dependsOn)
            );
        }

        return $input;
    }
}
