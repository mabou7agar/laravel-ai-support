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

    public function test_pipeline_synthesizes_fallback_with_skipped_trace_when_all_stages_abstain(): void
    {
        $pipeline = new RoutingPipeline([
            new TestRoutingStage('first', null),
            new TestRoutingStage('second', null),
        ]);

        $trace = $pipeline->decide('hello there', new UnifiedActionContext('all-abstain-session'));

        $this->assertNotNull($trace->selected);
        $this->assertSame(RoutingDecisionAction::CONVERSATIONAL, $trace->selected->action);
        $this->assertSame(RoutingDecisionSource::FALLBACK, $trace->selected->source);

        $skipped = $trace->selected->metadata['skipped_stages'] ?? null;
        $this->assertIsArray($skipped);
        $this->assertCount(2, $skipped);
        $this->assertSame(['first', 'second'], array_column($skipped, 'stage'));
        $this->assertSame(
            [RoutingDecisionAction::ABSTAIN, RoutingDecisionAction::ABSTAIN],
            array_column($skipped, 'action')
        );
    }

    public function test_backward_pass_prefers_high_confidence_over_earlier_low_confidence(): void
    {
        $pipeline = new RoutingPipeline([
            new TestRoutingStage('low', new RoutingDecision(
                action: RoutingDecisionAction::SEARCH_RAG,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'low',
                reason: 'weak guess'
            )),
            new TestRoutingStage('medium', new RoutingDecision(
                action: RoutingDecisionAction::ROUTE_TO_NODE,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'medium',
                reason: 'medium guess'
            )),
            new TestRoutingStage('high', new RoutingDecision(
                action: RoutingDecisionAction::USE_TOOL,
                source: RoutingDecisionSource::AI_ROUTER,
                confidence: 'high',
                reason: 'confident'
            )),
        ]);

        $trace = $pipeline->decide('do the thing', new UnifiedActionContext('backward-high-session'));

        // Forward loop returns the high-confidence decision before reaching the backward pass.
        $this->assertSame(RoutingDecisionAction::USE_TOOL, $trace->selected->action);
        $this->assertCount(3, $trace->decisions);
    }

    public function test_backward_pass_falls_back_to_any_non_abstention_when_no_high_confidence(): void
    {
        $pipeline = new RoutingPipeline([
            new TestRoutingStage('low', new RoutingDecision(
                action: RoutingDecisionAction::SEARCH_RAG,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'low',
                reason: 'weak guess'
            )),
            new TestRoutingStage('medium', new RoutingDecision(
                action: RoutingDecisionAction::ROUTE_TO_NODE,
                source: RoutingDecisionSource::CLASSIFIER,
                confidence: 'medium',
                reason: 'medium guess'
            )),
            new TestRoutingStage('abstainer', null),
        ]);

        $trace = $pipeline->decide('do the thing', new UnifiedActionContext('backward-any-session'));

        // No high-confidence decision exists, so the second backward pass selects the
        // last non-abstention (the medium-confidence stage), not the first.
        $this->assertSame(RoutingDecisionAction::ROUTE_TO_NODE, $trace->selected->action);
        $this->assertSame('medium', $trace->selected->confidence);
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

    public function test_message_classification_abstains_for_action_request_so_ai_router_can_select_tool(): void
    {
        $stage = $this->app->make(MessageClassificationStage::class);

        $decision = $stage->decide(
            'Create an invoice for Ahmed and show a summary before confirmation.',
            new UnifiedActionContext('action-request-session'),
            ['use_rag' => false]
        );

        $this->assertNull($decision);
    }

    public function test_message_classification_abstains_from_rag_when_rag_is_disabled(): void
    {
        $stage = $this->app->make(MessageClassificationStage::class);

        $decision = $stage->decide(
            'What changed on Friday for Apollo?',
            new UnifiedActionContext('rag-disabled-session'),
            ['use_rag' => false]
        );

        $this->assertNull($decision);
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
