<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry;

class DeterministicCommandStage implements RoutingStageContract
{
    public function __construct(
        protected DeterministicAgentHandlerRegistry $handlers
    ) {
    }

    public function name(): string
    {
        return 'deterministic_command';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        if (!empty($options['skip_deterministic_handlers'])) {
            return null;
        }

        $candidates = $this->handlers->candidates();
        if ($candidates === []) {
            return null;
        }

        return new RoutingDecision(
            action: RoutingDecisionAction::RUN_DETERMINISTIC,
            source: RoutingDecisionSource::DETERMINISTIC,
            confidence: 'high',
            reason: 'Deterministic handlers are registered; execution dispatcher will evaluate them before AI routing.',
            payload: ['handler_candidates' => $candidates],
            metadata: ['stage' => $this->name()]
        );
    }
}
