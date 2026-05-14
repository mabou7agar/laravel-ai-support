<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class FallbackConversationalStage implements RoutingStageContract
{
    public function name(): string
    {
        return 'fallback_conversational';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        return new RoutingDecision(
            action: RoutingDecisionAction::CONVERSATIONAL,
            source: RoutingDecisionSource::FALLBACK,
            confidence: 'high',
            reason: 'No earlier routing stage selected a handler.',
            metadata: ['stage' => $this->name()]
        );
    }
}
