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
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentOrchestratorRoutingFallbackTest extends UnitTestCase
{
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
            ]);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldReceive('routeToNode')
            ->once()
            ->with('billing', 'list invoices', $context, [])
            ->andReturn(AgentResponse::failure(
                message: "I couldn't reach remote node 'billing' (HTTP 500).",
                context: $context
            ));
        $execution->shouldReceive('executeSearchRag')
            ->once()
            ->withArgs(function (string $message, UnifiedActionContext $ctx, array $options, $reroute) use ($context) {
                return $message === 'list invoices'
                    && $ctx === $context
                    && ($options['local_only'] ?? false) === true
                    && is_callable($reroute);
            })
            ->andReturn(AgentResponse::conversational(
                message: 'No invoices found locally.',
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
            $execution
        );

        $response = $orchestrator->process('list invoices', 'session-route-fallback', 5);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Remote down, local fallback active.', $response->message);
        $this->assertStringContainsString('No invoices found locally.', $response->message);
    }
}

