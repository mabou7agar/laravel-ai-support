<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Routing;

use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Services\Agent\Routing\Stages\ActiveRunContinuationStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\ExplicitModeStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\FallbackConversationalStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage;
use LaravelAIEngine\Tests\UnitTestCase;

class RoutingPipelineTest extends UnitTestCase
{
    public function test_pipeline_stops_on_first_high_confidence_decision(): void
    {
        $pipeline = new RoutingPipeline([
            new TestRoutingStage('first', new RoutingDecision(
                action: RoutingDecisionAction::SEARCH_RAG,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'high',
                reason: 'matched'
            )),
            new TestRoutingStage('second', new RoutingDecision(
                action: RoutingDecisionAction::CONVERSATIONAL,
                source: RoutingDecisionSource::FALLBACK,
                confidence: 'high',
                reason: 'fallback'
            )),
        ]);

        $trace = $pipeline->decide('tell me about invoices', new UnifiedActionContext('routing-session'));

        $this->assertSame(RoutingDecisionAction::SEARCH_RAG, $trace->selected->action);
        $this->assertCount(1, $trace->decisions);
    }

    public function test_pipeline_records_abstentions_and_selects_later_match(): void
    {
        Event::fake([AgentRunStreamed::class]);
        $pipeline = new RoutingPipeline([
            new TestRoutingStage('first', null),
            new TestRoutingStage('second', new RoutingDecision(
                action: RoutingDecisionAction::CONVERSATIONAL,
                source: RoutingDecisionSource::FALLBACK,
                confidence: 'high',
                reason: 'fallback'
            )),
        ]);

        $trace = $pipeline->decide('hello', new UnifiedActionContext('routing-session'));

        $this->assertSame(RoutingDecisionAction::ABSTAIN, $trace->decisions[0]->action);
        $this->assertSame(RoutingDecisionAction::CONVERSATIONAL, $trace->selected->action);
        $this->assertSame('first', $trace->selected->metadata['skipped_stages'][0]['stage']);
        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $event): bool => $event->event['name'] === 'routing.stage_started');
        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $event): bool => $event->event['name'] === 'routing.stage_abstained');
        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $event): bool => $event->event['name'] === 'routing.decided');
    }

    public function test_pipeline_resolves_stage_order_from_config(): void
    {
        $pipeline = $this->app->make(RoutingPipeline::class);

        $this->assertSame([
            ActiveRunContinuationStage::class,
            ExplicitModeStage::class,
            SelectionReferenceStage::class,
            AgentSkillMatchStage::class,
            MessageClassificationStage::class,
            AIRouterStage::class,
            FallbackConversationalStage::class,
        ], array_map(static fn (RoutingStageContract $stage): string => $stage::class, $pipeline->stages()));
    }

    public function test_selection_reference_wins_before_message_classification_for_numeric_reply(): void
    {
        $context = new UnifiedActionContext('conflict-session');
        $context->addAssistantMessage("1. First invoice\n2. Second invoice");

        $trace = $this->app->make(RoutingPipeline::class)->decide('1', $context);

        $this->assertSame(RoutingDecisionAction::HANDLE_SELECTION, $trace->selected->action);
        $this->assertSame(RoutingDecisionSource::SELECTION, $trace->selected->source);
        $this->assertSame('option_selection', $trace->selected->payload['selection_type']);
        $this->assertFalse(collect($trace->decisions)->contains(
            fn (RoutingDecision $decision): bool => ($decision->metadata['stage'] ?? null) === 'message_classification'
        ));
    }
}

class TestRoutingStage implements RoutingStageContract
{
    public function __construct(
        private readonly string $stageName,
        private readonly ?RoutingDecision $decision
    ) {
    }

    public function name(): string
    {
        return $this->stageName;
    }

    public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
    {
        return $this->decision;
    }
}
