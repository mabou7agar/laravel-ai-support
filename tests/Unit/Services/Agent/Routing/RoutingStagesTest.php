<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Routing;

use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\Routing\Stages\ActiveRunContinuationStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\ExplicitModeStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\FallbackConversationalStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage;
use LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage;
use LaravelAIEngine\Services\Agent\RoutingContextResolver;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RoutingStagesTest extends UnitTestCase
{
    public function test_active_run_stage_detects_node_sessions(): void
    {
        $stage = new ActiveRunContinuationStage();

        $nodeContext = new UnifiedActionContext('node-session');
        $nodeContext->set('routed_to_node', ['node_slug' => 'crm']);
        $nodeDecision = $stage->decide('continue', $nodeContext);

        $this->assertSame(RoutingDecisionAction::CONTINUE_NODE, $nodeDecision->action);
        $this->assertSame(['node_slug' => 'crm'], $nodeDecision->payload['routed_to_node']);
    }

    public function test_explicit_mode_stage_detects_goal_and_rag_modes(): void
    {
        $stage = new ExplicitModeStage();
        $context = new UnifiedActionContext('explicit-session');

        $goal = $stage->decide('write report', $context, ['agent_goal' => true, 'sub_agents' => ['writer']]);
        $this->assertSame(RoutingDecisionAction::RUN_SUB_AGENT, $goal->action);
        $this->assertSame(['writer'], $goal->payload['sub_agents']);

        $rag = $stage->decide('find docs', $context, ['force_rag' => true]);
        $this->assertSame(RoutingDecisionAction::SEARCH_RAG, $rag->action);

        $default = $stage->decide('create invoice', $context);
        $this->assertNull($default);
    }

    public function test_message_classification_stage_routes_chat_rag_and_ambiguous_messages(): void
    {
        $stage = new MessageClassificationStage(
            new MessageRoutingClassifier(),
            new RoutingContextResolver()
        );
        $context = new UnifiedActionContext('classification-session');

        $chat = $stage->decide('hello', $context);
        $this->assertSame(RoutingDecisionAction::CONVERSATIONAL, $chat->action);

        $rag = $stage->decide('tell me about Acme history', $context, ['rag_collections' => ['App\\Models\\Customer']]);
        $this->assertSame(RoutingDecisionAction::SEARCH_RAG, $rag->action);

        $ambiguous = $stage->decide('create invoice', $context);
        $this->assertNull($ambiguous);
    }

    public function test_selection_reference_stage_detects_option_selection_and_positional_reference(): void
    {
        $stage = new SelectionReferenceStage(new AgentSelectionService(Mockery::mock(AgentResponseFinalizer::class)));

        $optionContext = new UnifiedActionContext('option-session');
        $optionContext->addAssistantMessage("1. First invoice\n2. Second invoice");

        $option = $stage->decide('1', $optionContext);

        $this->assertSame(RoutingDecisionAction::HANDLE_SELECTION, $option->action);
        $this->assertSame(RoutingDecisionSource::SELECTION, $option->source);
        $this->assertSame('option_selection', $option->payload['selection_type']);

        $positionContext = new UnifiedActionContext('position-session');
        $positionContext->conversationHistory[] = [
            'role' => 'assistant',
            'content' => 'Here are invoices',
            'metadata' => ['entity_ids' => [10, 20]],
        ];

        $position = $stage->decide('show the second one', $positionContext);

        $this->assertSame(RoutingDecisionAction::HANDLE_SELECTION, $position->action);
        $this->assertSame('positional_reference', $position->payload['selection_type']);
    }

    public function test_ai_router_stage_maps_router_decisions_to_routing_decisions(): void
    {
        $router = Mockery::mock(IntentRouter::class);
        $router->shouldReceive('route')
            ->once()
            ->with('create invoice', Mockery::type(UnifiedActionContext::class), [])
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'invoice',
                'reason' => 'Tool is best fit.',
                'params' => ['customer' => 'Acme'],
                'decision_source' => 'router_ai',
            ]);

        $decision = (new AIRouterStage($router))
            ->decide('create invoice', new UnifiedActionContext('ai-router-session'));

        $this->assertSame(RoutingDecisionAction::USE_TOOL, $decision->action);
        $this->assertSame(RoutingDecisionSource::AI_ROUTER, $decision->source);
        $this->assertSame('invoice', $decision->payload['resource_name']);
        $this->assertSame(['customer' => 'Acme'], $decision->payload['params']);
    }

    public function test_ai_router_stage_abstains_when_router_throws(): void
    {
        $router = Mockery::mock(IntentRouter::class);
        $router->shouldReceive('route')
            ->once()
            ->andThrow(new \RuntimeException('router exploded'));

        $decision = (new AIRouterStage($router))
            ->decide('create invoice', new UnifiedActionContext('ai-router-crash-session'));

        $this->assertNull($decision);
    }

    public function test_fallback_stage_returns_conversational_decision(): void
    {
        $decision = (new FallbackConversationalStage())->decide('anything', new UnifiedActionContext('fallback-session'));

        $this->assertSame(RoutingDecisionAction::CONVERSATIONAL, $decision->action);
        $this->assertSame(RoutingDecisionSource::FALLBACK, $decision->source);
    }
}
