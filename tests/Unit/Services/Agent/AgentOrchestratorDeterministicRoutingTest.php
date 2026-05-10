<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentOrchestratorDeterministicRoutingTest extends UnitTestCase
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

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldReceive('executeSearchRag')
            ->once()
            ->withArgs(function (string $message, UnifiedActionContext $ctx, array $options, $reroute) use ($context) {
                return $message === 'What changed on Friday for Apollo?'
                    && $ctx === $context
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
            ->with($context, Mockery::type(AgentResponse::class))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $orchestrator = new AgentOrchestrator(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            $execution,
            new MessageRoutingClassifier()
        );

        $response = $orchestrator->process('What changed on Friday for Apollo?', 'session-semantic', 5, [
            'rag_collections' => ['App\\Models\\Project', 'App\\Models\\Mail'],
        ]);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Apollo changed on Friday', $response->message);
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

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldReceive('executeConversational')
            ->once()
            ->with('hello', $context, Mockery::type('array'))
            ->andReturn(AgentResponse::conversational(
                message: 'Hello there.',
                context: $context
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $orchestrator = new AgentOrchestrator(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            $execution,
            new MessageRoutingClassifier()
        );

        $response = $orchestrator->process('hello', 'session-chat', 7);

        $this->assertTrue($response->success);
        $this->assertSame('Hello there.', $response->message);
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

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldReceive('executeUseTool')
            ->once()
            ->withArgs(function (string $toolName, string $message, UnifiedActionContext $ctx, array $options, $searchRag) use ($context) {
                return $message === 'list all open tasks'
                    && $ctx === $context
                    && $toolName === 'data_query'
                    && ($options['tool_params']['query'] ?? null) === 'list all open tasks'
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
            ->with($context, Mockery::type(AgentResponse::class))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $orchestrator = new AgentOrchestrator(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            $execution,
            new MessageRoutingClassifier()
        );

        $response = $orchestrator->process('list all open tasks', 'session-structured', 8, [
            'rag_collections' => ['App\\Models\\Task'],
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Open tasks found.', $response->message);
    }
}
