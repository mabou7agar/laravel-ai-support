<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSelectionService;

class SelectionReferenceStage implements RoutingStageContract
{
    public function __construct(
        protected AgentSelectionService $selectionService
    ) {
    }

    public function name(): string
    {
        return 'selection_reference';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if ($this->selectionService->detectsOptionSelection($message, $context)) {
            return new RoutingDecision(
                action: RoutingDecisionAction::HANDLE_SELECTION,
                source: RoutingDecisionSource::SELECTION,
                confidence: 'high',
                reason: 'Message selects a numbered option from the previous assistant response.',
                payload: ['selection_type' => 'option_selection', 'value' => trim($message)],
                metadata: ['stage' => $this->name()]
            );
        }

        if ($this->selectionService->detectsPositionalReference($message, $context)) {
            return new RoutingDecision(
                action: RoutingDecisionAction::HANDLE_SELECTION,
                source: RoutingDecisionSource::SELECTION,
                confidence: 'high',
                reason: 'Message refers to an entity position from previous context.',
                payload: ['selection_type' => 'positional_reference'],
                metadata: ['stage' => $this->name()]
            );
        }

        return null;
    }
}
