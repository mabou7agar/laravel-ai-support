<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class ActiveRunContinuationStage implements RoutingStageContract
{
    public function name(): string
    {
        return 'active_run_continuation';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if ($context->has('autonomous_collector')) {
            return new RoutingDecision(
                action: RoutingDecisionAction::CONTINUE_COLLECTOR,
                source: RoutingDecisionSource::SESSION,
                confidence: 'high',
                reason: 'Active autonomous collector session exists.',
                metadata: ['stage' => $this->name()]
            );
        }

        if ($context->has('routed_to_node')) {
            return new RoutingDecision(
                action: RoutingDecisionAction::CONTINUE_NODE,
                source: RoutingDecisionSource::SESSION,
                confidence: 'high',
                reason: 'Active routed node session exists.',
                payload: ['routed_to_node' => $context->get('routed_to_node')],
                metadata: ['stage' => $this->name()]
            );
        }

        return null;
    }
}
