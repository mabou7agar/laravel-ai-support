<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;

class AgentSkillMatchStage implements RoutingStageContract
{
    public function __construct(
        protected AgentSkillMatcher $matcher,
        protected AgentSkillExecutionPlanner $planner
    ) {}

    public function name(): string
    {
        return 'agent_skill_match';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if (!(bool) config('ai-agent.skills.prefer_deterministic_matches', true)) {
            return null;
        }

        $match = $this->matcher->matchIntent($message, $context);
        if ($match === null) {
            return null;
        }

        $plan = $this->planner->plan($match['skill'], $message, $context, $match);

        return new RoutingDecision(
            action: $this->mapAction((string) ($plan['action'] ?? 'search_rag')),
            source: RoutingDecisionSource::DETERMINISTIC,
            confidence: 'high',
            reason: (string) ($plan['reasoning'] ?? 'Matched an agent skill.'),
            payload: [
                'resource_name' => $plan['resource_name'] ?? null,
                'params' => is_array($plan['params'] ?? null) ? $plan['params'] : [],
                'route_action' => $plan['action'] ?? null,
            ],
            metadata: [
                'stage' => $this->name(),
                'decision_source' => $plan['decision_source'] ?? 'skill_match',
                'matched_skill' => $plan['metadata'] ?? null,
            ]
        );
    }

    protected function mapAction(string $action): string
    {
        return match ($action) {
            'use_tool' => RoutingDecisionAction::USE_TOOL,
            'route_to_node' => RoutingDecisionAction::ROUTE_TO_NODE,
            'run_sub_agent' => RoutingDecisionAction::RUN_SUB_AGENT,
            'conversational' => RoutingDecisionAction::CONVERSATIONAL,
            default => RoutingDecisionAction::SEARCH_RAG,
        };
    }

}
