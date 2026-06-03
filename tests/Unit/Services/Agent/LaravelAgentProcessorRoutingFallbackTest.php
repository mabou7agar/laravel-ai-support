<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\ContinueNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\RouteToNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\Execution\RoutingActionHandlerRegistry;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class LaravelAgentProcessorRoutingFallbackTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_route_to_node_failure_falls_back_to_local_rag_when_enabled(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', true);
        config()->set('ai-engine.nodes.routing.local_fallback_notice', 'Remote down, local fallback active.');

        $context = new UnifiedActionContext('session-route-fallback', 5);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('session-route-fallback', 5)
            ->andReturn($context);
        $contextManager->shouldReceive('save')->andReturnNull();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->once()
            ->andReturn([
                'action' => 'route_to_node',
                'resource_name' => 'billing',
                'reasoning' => 'billing domain',
                'decision_source' => 'router_ai',
            ]);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldReceive('routeToNode')
            ->once()
            ->withArgs(function (string $resource, string $message, UnifiedActionContext $ctx, array $options): bool {
                return $resource === 'billing'
                    && $message === 'show invoice domain status'
                    && $ctx->sessionId === 'session-route-fallback'
                    && ($options['decision_path'] ?? null) === 'router_ai_route_to_node'
                    && ($options['decision_source'] ?? null) === 'router_ai';
            })
            ->andReturn(AgentResponse::failure(
                message: "I couldn't reach remote node 'billing' (HTTP 500).",
                context: $context
            ));

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->withArgs(function (string $message, UnifiedActionContext $ctx, array $options, $reroute) use ($context) {
                return $message === 'show invoice domain status'
                    && $ctx === $context
                    && ($options['local_only'] ?? false) === true
                    && ($options['decision_path'] ?? null) === 'router_ai_route_to_node'
                    && is_callable($reroute);
            })
            ->andReturn(AgentResponse::conversational(
                message: 'No invoices found locally.',
                context: $context
            ));

        $registry = new RoutingActionHandlerRegistry();
        $dispatcher = null;
        $registry->register(new ContinueNodeActionHandler($node));
        $registry->register(new RouteToNodeActionHandler($node, function () use (&$dispatcher): AgentExecutionDispatcher {
            return $dispatcher;
        }));

        $dispatcher = new AgentExecutionDispatcher(
            Mockery::mock(AgentActionExecutionService::class),
            $conversation,
            $registry,
            Mockery::mock(GoalAgentService::class)
        );

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
            $node,
            executionDispatcher: $dispatcher
        );

        $response = $processor->process('show invoice domain status', 'session-route-fallback', 5);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Remote down, local fallback active.', $response->message);
        $this->assertStringContainsString('No invoices found locally.', $response->message);
    }
}
