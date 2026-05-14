<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class ExplicitModeStage implements RoutingStageContract
{
    public function name(): string
    {
        return 'explicit_mode';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if (!empty($options['agent_goal']) || !empty($options['goal_agent']) || !empty($options['sub_agents'])) {
            return new RoutingDecision(
                action: RoutingDecisionAction::RUN_SUB_AGENT,
                source: RoutingDecisionSource::EXPLICIT,
                confidence: 'high',
                reason: 'Request explicitly enabled goal/sub-agent execution.',
                payload: [
                    'target' => $options['target'] ?? $message,
                    'sub_agents' => $options['sub_agents'] ?? null,
                ],
                metadata: ['stage' => $this->name()]
            );
        }

        if (!empty($options['force_rag'])) {
            return new RoutingDecision(
                action: RoutingDecisionAction::SEARCH_RAG,
                source: RoutingDecisionSource::EXPLICIT,
                confidence: 'high',
                reason: 'Request explicitly forced RAG.',
                metadata: ['stage' => $this->name()]
            );
        }

        if (!empty($options['start_collector'])) {
            return new RoutingDecision(
                action: RoutingDecisionAction::START_COLLECTOR,
                source: RoutingDecisionSource::EXPLICIT,
                confidence: 'high',
                reason: 'Request explicitly asked to start a collector flow.',
                metadata: ['stage' => $this->name()]
            );
        }

        return null;
    }
}
