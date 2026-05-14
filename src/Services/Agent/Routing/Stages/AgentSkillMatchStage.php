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
    ) {
    }

    public function name(): string
    {
        return 'agent_skill_match';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if (!(bool) config('ai-agent.skills.prefer_deterministic_matches', true)) {
            return null;
        }

        $active = $this->activeSkillFlowDecision($message, $context);
        if ($active instanceof RoutingDecision) {
            return $active;
        }

        if (is_array($context->metadata['last_action_flow'] ?? null)) {
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
            'start_collector' => RoutingDecisionAction::START_COLLECTOR,
            'use_tool' => RoutingDecisionAction::USE_TOOL,
            'route_to_node' => RoutingDecisionAction::ROUTE_TO_NODE,
            'resume_session' => RoutingDecisionAction::CONTINUE_RUN,
            'pause_and_handle' => RoutingDecisionAction::PAUSE_AND_HANDLE,
            'run_sub_agent' => RoutingDecisionAction::RUN_SUB_AGENT,
            'conversational' => RoutingDecisionAction::CONVERSATIONAL,
            default => RoutingDecisionAction::SEARCH_RAG,
        };
    }

    protected function activeSkillFlowDecision(string $message, UnifiedActionContext $context): ?RoutingDecision
    {
        $flow = $context->metadata['last_skill_flow'] ?? null;
        if (!is_array($flow) || empty($flow['skill_id'])) {
            return null;
        }

        if (($flow['status'] ?? null) === 'completed') {
            unset($context->metadata['last_skill_flow']);

            return null;
        }

        return new RoutingDecision(
            action: RoutingDecisionAction::USE_TOOL,
            source: RoutingDecisionSource::DETERMINISTIC,
            confidence: 'high',
            reason: 'Continue the active skill tool flow.',
            payload: [
                'resource_name' => 'run_skill',
                'params' => [
                    'skill_id' => (string) $flow['skill_id'],
                    'message' => $message,
                    'reset' => false,
                ],
                'route_action' => 'use_tool',
            ],
            metadata: [
                'stage' => $this->name(),
                'decision_source' => 'active_skill_flow',
                'matched_skill' => [
                    'skill_id' => (string) $flow['skill_id'],
                    'skill_name' => $flow['skill_name'] ?? null,
                ],
            ]
        );
    }
}
