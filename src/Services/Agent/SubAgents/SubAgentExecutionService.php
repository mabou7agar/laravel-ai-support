<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use LaravelAIEngine\DTOs\AgentGoalPlan;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class SubAgentExecutionService
{
    public function __construct(
        protected SubAgentRegistry $registry
    ) {
    }

    /**
     * @return array<string, SubAgentResult>
     */
    public function execute(AgentGoalPlan $plan, UnifiedActionContext $context, array $options = []): array
    {
        $results = [];
        $stopOnFailure = array_key_exists('stop_on_failure', $options)
            ? (bool) $options['stop_on_failure']
            : (bool) config('ai-agent.goal_agent.stop_on_failure', true);

        $invalidDependencies = $this->validateDependencies($plan->tasks);

        foreach ($plan->tasks as $task) {
            if (isset($invalidDependencies[$task->id])) {
                $failure = $invalidDependencies[$task->id];
                $results[$task->id] = SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    $failure['error'],
                    metadata: $failure['metadata']
                );
                if ($stopOnFailure && $task->critical) {
                    break;
                }
                continue;
            }

            $blockedBy = $this->firstFailedDependency($task, $results);
            if ($blockedBy !== null) {
                $results[$task->id] = SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    "Skipped because dependency {$blockedBy} failed.",
                    metadata: ['blocked_by' => $blockedBy]
                );
                if ($stopOnFailure && $task->critical) {
                    break;
                }
                continue;
            }

            $handler = $this->registry->resolveHandler($task->agentId);
            if (!$handler) {
                $results[$task->id] = SubAgentResult::failure(
                    $task->id,
                    $task->agentId,
                    "No handler registered for sub-agent '{$task->agentId}'."
                );
                if ($stopOnFailure && $task->critical) {
                    break;
                }
                continue;
            }

            $results[$task->id] = $handler->handle($task, $context, $results, $options);

            if ($results[$task->id]->needsUserInput) {
                break;
            }

            if (!$results[$task->id]->success && $stopOnFailure && $task->critical) {
                break;
            }
        }

        return $results;
    }

    /**
     * Detect dependency problems before any task runs, so broken plans fail
     * cleanly instead of executing out of order or with stale dependency data.
     *
     * Catches:
     *  - depends_on referencing a task id that is not in the plan (missing_dependency)
     *  - forward references: a dependency that is scheduled after the dependent task (forward_dependency)
     *  - cycles, including self-references, via Kahn's algorithm (circular_dependency)
     *
     * @param array<int, SubAgentTask> $tasks
     * @return array<string, array{error: string, metadata: array<string, mixed>}>
     */
    protected function validateDependencies(array $tasks): array
    {
        $invalid = [];

        // Map task id -> execution position to detect forward references.
        $positions = [];
        $position = 0;
        foreach ($tasks as $task) {
            $positions[$task->id] = $position++;
        }

        // Build the dependency graph for the cycle check using only known nodes.
        $adjacency = [];
        $inDegree = [];
        foreach ($tasks as $task) {
            $adjacency[$task->id] ??= [];
            $inDegree[$task->id] ??= 0;
        }

        foreach ($tasks as $task) {
            foreach ($task->dependsOn as $dependencyId) {
                $dependencyId = (string) $dependencyId;

                if (!array_key_exists($dependencyId, $positions)) {
                    $invalid[$task->id] = [
                        'error' => "Dependency '{$dependencyId}' is not defined in the plan.",
                        'metadata' => [
                            'missing_dependency' => $dependencyId,
                        ],
                    ];
                    continue;
                }

                if ($positions[$dependencyId] >= $positions[$task->id]) {
                    $invalid[$task->id] = [
                        'error' => "Dependency '{$dependencyId}' is scheduled after task '{$task->id}'.",
                        'metadata' => [
                            'forward_dependency' => $dependencyId,
                        ],
                    ];
                }

                // Edge: dependency -> task.
                $adjacency[$dependencyId][] = $task->id;
                $inDegree[$task->id]++;
            }
        }

        // Kahn's algorithm: any node never reaching zero in-degree is in a cycle.
        $queue = [];
        $remainingInDegree = $inDegree;
        foreach ($remainingInDegree as $taskId => $degree) {
            if ($degree === 0) {
                $queue[] = $taskId;
            }
        }

        $resolved = 0;
        while ($queue !== []) {
            $current = array_shift($queue);
            $resolved++;

            foreach ($adjacency[$current] as $dependent) {
                if (--$remainingInDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        if ($resolved < count($positions)) {
            foreach ($remainingInDegree as $taskId => $degree) {
                // A cycle is the more precise diagnosis, so it overrides a
                // forward-reference finding; a missing dependency stays because
                // that node never made it into the graph correctly.
                if ($degree > 0
                    && (!isset($invalid[$taskId]) || !array_key_exists('missing_dependency', $invalid[$taskId]['metadata']))
                ) {
                    $invalid[$taskId] = [
                        'error' => "Task '{$taskId}' is part of a circular dependency.",
                        'metadata' => [
                            'circular_dependency' => true,
                        ],
                    ];
                }
            }
        }

        return $invalid;
    }

    protected function firstFailedDependency(SubAgentTask $task, array $results): ?string
    {
        foreach ($task->dependsOn as $dependencyId) {
            if (isset($results[$dependencyId]) && !$results[$dependencyId]->success) {
                return (string) $dependencyId;
            }
        }

        return null;
    }
}
