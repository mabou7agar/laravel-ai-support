<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Routing\Stages;

use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\IntentRouter;
use Throwable;

class AIRouterStage implements RoutingStageContract
{
    public function __construct(
        protected IntentRouter $intentRouter
    ) {
    }

    public function name(): string
    {
        return 'ai_router';
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        try {
            $decision = $this->intentRouter->route($message, $context, $options);
        } catch (Throwable $e) {
            \Log::channel('ai-engine')->error('AI router stage failed; abstaining so later stages run.', [
                'stage' => $this->name(),
                'session_id' => $context->sessionId,
                'agent_run_id' => $options['agent_run_id'] ?? null,
                'trace_id' => $options['trace_id'] ?? null,
                'exception' => $e->getMessage(),
                'exception_class' => $e::class,
            ]);

            return null;
        }

        return new RoutingDecision(
            action: $this->mapAction((string) ($decision['action'] ?? 'conversational')),
            source: RoutingDecisionSource::AI_ROUTER,
            confidence: 'high',
            reason: (string) ($decision['reason'] ?? 'AI router selected route.'),
            payload: [
                'resource_name' => $decision['resource_name'] ?? null,
                'params' => is_array($decision['params'] ?? null) ? $decision['params'] : [],
                'route_action' => $decision['action'] ?? null,
            ],
            metadata: [
                'stage' => $this->name(),
                'decision_source' => $decision['decision_source'] ?? 'router_ai',
                'matched_skill' => $decision['metadata'] ?? null,
            ]
        );
    }

    protected function mapAction(string $action): string
    {
        return match ($action) {
            'use_tool' => RoutingDecisionAction::USE_TOOL,
            'route_to_node' => RoutingDecisionAction::ROUTE_TO_NODE,
            'search_rag' => RoutingDecisionAction::SEARCH_RAG,
            'run_sub_agent' => RoutingDecisionAction::RUN_SUB_AGENT,
            'fail' => RoutingDecisionAction::FAIL,
            default => RoutingDecisionAction::CONVERSATIONAL,
        };
    }
}
