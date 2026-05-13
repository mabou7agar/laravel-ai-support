<?php

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\DTOs\AgentGoalPlan;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class SubAgentPlanner
{
    public function __construct(
        protected SubAgentRegistry $registry
    ) {
    }

    public function plan(string $target, UnifiedActionContext $context, array $options = []): AgentGoalPlan
    {
        $target = trim($target);
        $configuredTasks = $options['sub_agents'] ?? $options['goal_agent']['sub_agents'] ?? null;

        if (is_array($configuredTasks) && $configuredTasks !== []) {
            return new AgentGoalPlan(
                target: $target,
                tasks: $this->tasksFromOptions($configuredTasks, $target),
                metadata: ['source' => 'request']
            );
        }

        $matchedAgents = $this->matchAgents($target, (int) ($options['max_sub_agents'] ?? config('ai-agent.goal_agent.max_sub_agents', 5)));

        return new AgentGoalPlan(
            target: $target,
            tasks: array_map(
                fn (string $agentId, int $index): SubAgentTask => $this->taskForAgent($agentId, $target, $index),
                $matchedAgents,
                array_keys($matchedAgents)
            ),
            metadata: ['source' => 'capability_match']
        );
    }

    protected function tasksFromOptions(array $tasks, string $target): array
    {
        return collect($tasks)
            ->map(function (array|string $task, int|string $key) use ($target): array|string {
                if (is_string($task)) {
                    $task = [
                        'agent_id' => $task,
                        'objective' => $target,
                    ];
                }

                if (is_array($task) && !isset($task['agent_id'], $task['agent'], $task['role']) && is_string($key)) {
                    $task['agent_id'] = $key;
                }

                return $task;
            })
            ->values()
            ->map(function (array|string $task, int $index) use ($target): SubAgentTask {
                if (empty($task['objective']) && empty($task['target']) && empty($task['prompt'])) {
                    $task['objective'] = $target;
                }

                return SubAgentTask::fromArray($task, $index);
            })
            ->sortBy('order')
            ->values()
            ->all();
    }

    protected function matchAgents(string $target, int $max): array
    {
        $targetLower = strtolower($target);
        $scores = [];

        foreach ($this->registry->all() as $agentId => $definition) {
            if (!is_array($definition) || (($definition['enabled'] ?? true) === false)) {
                continue;
            }

            $score = 0;
            foreach ((array) ($definition['capabilities'] ?? []) as $capability) {
                $capability = strtolower((string) $capability);
                if ($capability !== '' && str_contains($targetLower, $capability)) {
                    $score += 2;
                }
            }

            foreach ((array) ($definition['keywords'] ?? []) as $keyword) {
                $keyword = strtolower((string) $keyword);
                if ($keyword !== '' && str_contains($targetLower, $keyword)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $scores[$agentId] = $score;
            }
        }

        arsort($scores);
        $matched = array_slice(array_keys($scores), 0, max(1, $max));

        if ($matched === [] && $this->registry->has('general')) {
            $matched[] = 'general';
        }

        return $matched;
    }

    protected function taskForAgent(string $agentId, string $target, int $index): SubAgentTask
    {
        $definition = $this->registry->get($agentId) ?? [];
        $name = (string) ($definition['name'] ?? $agentId);
        $description = trim((string) ($definition['description'] ?? ''));
        $objective = $description !== ''
            ? "{$description}\nTarget: {$target}"
            : $target;

        return new SubAgentTask(
            id: 'task_' . ($index + 1),
            agentId: $agentId,
            name: $name,
            objective: $objective,
            order: $index
        );
    }
}
