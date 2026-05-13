<?php

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

        foreach ($plan->tasks as $task) {
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
