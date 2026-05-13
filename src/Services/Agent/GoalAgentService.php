<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;

class GoalAgentService
{
    public function __construct(
        protected SubAgentPlanner $planner,
        protected SubAgentExecutionService $executor
    ) {
    }

    public function execute(string $target, UnifiedActionContext $context, array $options = []): AgentResponse
    {
        $target = trim($target);
        if ($target === '') {
            return AgentResponse::failure(
                message: 'Agent target is required.',
                context: $context
            );
        }

        $plan = $this->planner->plan($target, $context, $options);
        if ($plan->isEmpty()) {
            return AgentResponse::failure(
                message: 'No sub-agents matched this target. Register sub-agents in ai-agent.sub_agents or pass sub_agents in the request.',
                data: ['target' => $target],
                context: $context
            );
        }

        $results = $this->executor->execute($plan, $context, $options);
        $payload = [
            'target' => $target,
            'plan' => $plan->toArray(),
            'results' => array_map(static fn (SubAgentResult $result): array => $result->toArray(), $results),
        ];

        $context->metadata['goal_agent'] = $payload;

        $waiting = collect($results)->first(fn (SubAgentResult $result): bool => $result->needsUserInput);
        if ($waiting instanceof SubAgentResult) {
            $response = AgentResponse::needsUserInput(
                message: $waiting->message ?? 'More information is required to continue the goal.',
                data: $payload,
                context: $context,
                requiredInputs: $waiting->metadata['required_inputs'] ?? null
            );
            $response->strategy = 'goal_agent';
            $response->metadata = ['agent_strategy' => 'goal_agent', 'goal_agent' => $payload];

            return $response;
        }

        $failed = collect($results)->filter(fn (SubAgentResult $result): bool => !$result->success);
        if ($failed->isNotEmpty()) {
            $response = AgentResponse::failure(
                message: $this->summaryMessage($target, $results, false),
                data: $payload,
                context: $context
            );
            $response->strategy = 'goal_agent';
            $response->metadata = ['agent_strategy' => 'goal_agent', 'goal_agent' => $payload];

            return $response;
        }

        $response = AgentResponse::success(
            message: $this->summaryMessage($target, $results, true),
            data: $payload,
            context: $context
        );
        $response->strategy = 'goal_agent';
        $response->metadata = ['agent_strategy' => 'goal_agent', 'goal_agent' => $payload];

        return $response;
    }

    protected function summaryMessage(string $target, array $results, bool $success): string
    {
        $status = $success ? 'Target completed.' : 'Target was not completed.';
        $lines = [$status, "Target: {$target}"];

        foreach ($results as $result) {
            $label = $result->success ? 'done' : 'failed';
            $detail = $result->message ?? $result->error ?? 'No details returned.';
            $lines[] = "- {$result->agentId}: {$label} - {$detail}";
        }

        return implode("\n", $lines);
    }
}
