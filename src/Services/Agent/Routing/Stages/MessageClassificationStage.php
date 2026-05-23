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

        if ($classification['route'] === 'ask_ai') {
            return null;
        }

        if ($classification['route'] === 'search_rag' && !$this->ragEnabled($options)) {
            return null;
        }

        $action = match ($classification['route']) {
            'conversational' => RoutingDecisionAction::CONVERSATIONAL,
            'search_rag' => RoutingDecisionAction::SEARCH_RAG,
            default => null,
        };

        if ($action === null) {
            return null;
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

    protected function ragEnabled(array $options): bool
    {
        if (!empty($options['force_rag'])) {
            return true;
        }

        return !array_key_exists('use_rag', $options) || (bool) $options['use_rag'];
    }
}
