<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\RoutingContextResolver;

class MessageClassificationStage implements RoutingStageContract
{
    public function __construct(
        protected MessageRoutingClassifier $classifier,
        protected RoutingContextResolver $routingContextResolver
    ) {
    }

    public function name(): string
    {
        return 'message_classification';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        $classification = $this->classifier->classify(
            $message,
            $this->routingContextResolver->signalsFromContext($context, $options)
        );

        $action = match ($classification['route']) {
            'conversational' => RoutingDecisionAction::CONVERSATIONAL,
            'search_rag' => RoutingDecisionAction::SEARCH_RAG,
            default => null,
        };

        if ($action === null) {
            return new RoutingDecision(
                action: RoutingDecisionAction::USE_TOOL,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'medium',
                reason: $classification['reason'],
                payload: ['route' => $classification['route'], 'mode' => $classification['mode']],
                metadata: ['stage' => $this->name(), 'classification' => $classification]
            );
        }

        return new RoutingDecision(
            action: $action,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: $classification['reason'],
            payload: ['route' => $classification['route'], 'mode' => $classification['mode']],
            metadata: ['stage' => $this->name(), 'classification' => $classification]
        );
    }
}
