<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ThrowingRoutingPipeline extends RoutingPipeline
{
    public function decide(string $message, UnifiedActionContext $context, array $options = []): \LaravelAIEngine\DTOs\RoutingTrace
    {
        throw new \RuntimeException('Routing pipeline unavailable.');
    }
}

class LaravelAgentProcessorDeterministicRoutingTest extends UnitTestCase
{
    public function test_semantic_question_bypasses_intent_router_and_uses_search_rag(): void
    {
        $context = new UnifiedActionContext('session-semantic', 5);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('session-semantic', 5)
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (RoutingDecision $decision, string $message, UnifiedActionContext $ctx, array $options, $reroute) use ($context) {
                return $message === 'What changed on Friday for Apollo?'
                    && $ctx === $context
                    && $decision->action === RoutingDecisionAction::SEARCH_RAG
                    && ($options['preclassified_route_mode'] ?? null) === 'semantic_retrieval'
                    && is_callable($reroute);
            })
            ->andReturn(AgentResponse::conversational(
                message: 'Apollo changed on Friday because the launch slipped.',
                context: $context
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $response = $processor->process('What changed on Friday for Apollo?', 'session-semantic', 5, [
            'rag_collections' => ['App\\Models\\Project', 'App\\Models\\Mail'],
        ]);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Apollo changed on Friday', $response->message);
        $this->assertSame('search_rag', $response->metadata['route_explanation']['action']);
        $this->assertSame('heuristic_semantic_retrieval', $response->metadata['route_explanation']['decision_path']);
    }

    public function test_greeting_bypasses_intent_router_and_stays_conversational(): void
    {
        $context = new UnifiedActionContext('session-chat', 7);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('session-chat', 7)
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (RoutingDecision $decision, string $message, UnifiedActionContext $ctx): bool {
                return $message === 'hello'
                    && $ctx->sessionId === 'session-chat'
                    && $decision->action === RoutingDecisionAction::CONVERSATIONAL;
            })
            ->andReturn(AgentResponse::conversational(
                message: 'Hello there.',
                context: $context
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $response = $processor->process('hello', 'session-chat', 7);

        $this->assertTrue($response->success);
        $this->assertSame('Hello there.', $response->message);
        $this->assertSame('conversational', $response->metadata['route_explanation']['action']);
        $this->assertSame('heuristic_conversational', $response->metadata['route_explanation']['decision_path']);
    }

    public function test_structured_query_uses_intent_router_for_tool_selection(): void
    {
        $context = new UnifiedActionContext('session-structured', 8);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('session-structured', 8)
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->once()
            ->with('list all open tasks', $context, Mockery::on(function (array $options): bool {
                return ($options['rag_collections'] ?? null) === ['App\\Models\\Task'];
            }))
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'data_query',
                'params' => ['query' => 'list all open tasks'],
                'reasoning' => 'structured list query',
                'decision_source' => 'router_ai',
            ]);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (RoutingDecision $decision, string $message, UnifiedActionContext $ctx, array $options, $searchRag) use ($context) {
                return $message === 'list all open tasks'
                    && $ctx === $context
                    && $decision->action === RoutingDecisionAction::USE_TOOL
                    && ($decision->payload['resource_name'] ?? null) === 'data_query'
                    && ($decision->payload['params']['query'] ?? null) === 'list all open tasks'
                    && ($options['decision_path'] ?? null) === 'router_ai_use_tool'
                    && is_callable($searchRag);
            })
            ->andReturn(AgentResponse::conversational(
                message: 'Open tasks found.',
                context: $context
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $response = $processor->process('list all open tasks', 'session-structured', 8, [
            'rag_collections' => ['App\\Models\\Task'],
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Open tasks found.', $response->message);
        $this->assertSame('use_tool', $response->metadata['route_explanation']['action']);
        $this->assertSame('router_ai_use_tool', $response->metadata['route_explanation']['decision_path']);
    }

    public function test_routing_pipeline_failure_falls_back_to_heuristic_action_routing_not_rag(): void
    {
        $context = new UnifiedActionContext('session-pipeline-failure', 'lab-user');

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('session-pipeline-failure', 'lab-user')
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->once()
            ->with('Create an invoice for Ahmed.', $context, Mockery::type('array'))
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'run_skill',
                'params' => [
                    'skill_id' => 'create_invoice',
                    'message' => 'Create an invoice for Ahmed.',
                    'reset' => true,
                ],
                'reasoning' => 'invoice creation skill',
                'decision_source' => 'router_ai',
            ]);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (RoutingDecision $decision, string $message, UnifiedActionContext $ctx, array $options, $searchRag) use ($context): bool {
                return $message === 'Create an invoice for Ahmed.'
                    && $ctx === $context
                    && $decision->action === RoutingDecisionAction::USE_TOOL
                    && ($decision->payload['resource_name'] ?? null) === 'run_skill'
                    && ($decision->payload['params']['skill_id'] ?? null) === 'create_invoice'
                    && ($options['decision_path'] ?? null) === 'router_ai_use_tool'
                    && is_callable($searchRag);
            })
            ->andReturn(AgentResponse::needsUserInput(
                message: 'What email should I use for Ahmed?',
                data: ['skill_id' => 'create_invoice'],
                context: $context
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher,
            new ThrowingRoutingPipeline()
        );

        $response = $processor->process('Create an invoice for Ahmed.', 'session-pipeline-failure', 'lab-user', [
            'use_actions' => true,
            'use_rag' => false,
        ]);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What email should I use for Ahmed?', $response->message);
        $this->assertSame('use_tool', $response->metadata['route_explanation']['action']);
    }

}
