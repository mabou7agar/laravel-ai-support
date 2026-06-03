<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class LaravelAgentProcessorTraceAndIdempotencyTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    private function passthroughFinalizer(UnifiedActionContext $context): AgentResponseFinalizer
    {
        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        return $finalizer;
    }

    private function noopSelection(): AgentSelectionService
    {
        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        return $selection;
    }

    /**
     * M3: a nested dispatch that already stamped routing_decision/routing_trace on the
     * response metadata must not be overwritten by the outer decision.
     */
    public function test_nested_routing_decision_is_preserved_when_outer_dispatch_runs(): void
    {
        $context = new UnifiedActionContext('session-nested', 11);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->with('session-nested', 11)->andReturn($context);

        $nestedDecision = (new RoutingDecision(
            action: RoutingDecisionAction::ROUTE_TO_NODE,
            source: 'runtime',
            confidence: 'high',
            reason: 'Nested reroute resolved to a node.',
            payload: ['resource_name' => 'billing']
        ))->toArray();

        // Simulate the dispatcher returning a response that a deeper process()/dispatch
        // already annotated with its own routing_decision/routing_trace.
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($context, $nestedDecision) {
                $response = AgentResponse::conversational(message: 'Nested result.', context: $context);
                $response->metadata = [
                    'routing_decision' => $nestedDecision,
                    'routing_trace' => [$nestedDecision],
                ];

                return $response;
            });

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            Mockery::mock(IntentRouter::class),
            new AgentPlanner(),
            $this->passthroughFinalizer($context),
            $this->noopSelection(),
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $response = $processor->process('hello there friend', 'session-nested', 11);

        $this->assertSame('Nested result.', $response->message);

        // Outer decision is recorded as the primary routing_decision (heuristic routed
        // this message to search_rag), distinct from the nested route_to_node decision.
        $this->assertSame('search_rag', $response->metadata['routing_decision']['action']);

        // ...and the nested decision is preserved rather than clobbered.
        $this->assertArrayHasKey('nested_routing_decision', $response->metadata);
        $this->assertSame('route_to_node', $response->metadata['nested_routing_decision']['action']);

        // The trace chains the outer decision in front of the nested trace.
        $actions = array_map(static fn (array $d): string => $d['action'], $response->metadata['routing_trace']);
        $this->assertContains('search_rag', $actions);
        $this->assertContains('route_to_node', $actions);
    }

    /**
     * M8: when a CONTINUE_NODE follow-up fails and we degrade to local RAG, the fallback
     * dispatch must carry the failed CONTINUE_NODE decision in its routing trace.
     */
    public function test_continue_node_failure_propagates_failed_decision_into_rag_fallback_trace(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', true);
        config()->set('ai-engine.nodes.routing.local_fallback_notice', 'Local fallback active.');

        $context = new UnifiedActionContext('session-continue-fallback', 9);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldReceive('shouldContinueSession')->once()->andReturnTrue();

        $capturedRagMetadata = null;

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        // First dispatch: CONTINUE_NODE -> remote failure.
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::CONTINUE_NODE)
            ->andReturn(AgentResponse::failure(
                message: "I couldn't reach remote node 'billing' (HTTP 503).",
                context: $context
            ));
        // Second dispatch: SEARCH_RAG fallback -> success.
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::SEARCH_RAG)
            ->andReturn(AgentResponse::conversational(message: 'Local invoices listed.', context: $context));

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            Mockery::mock(IntentRouter::class),
            new AgentPlanner(),
            $this->passthroughFinalizer($context),
            $this->noopSelection(),
            $node,
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $response = $processor->process('what about the latest invoice', 'session-continue-fallback', 9);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Local fallback active.', $response->message);
        $this->assertStringContainsString('Local invoices listed.', $response->message);

        // The fallback RAG response trace must record the failed CONTINUE_NODE attempt.
        $trace = $response->metadata['routing_trace'] ?? [];
        $actions = array_map(static fn (array $d): string => $d['action'], $trace);
        $this->assertContains('continue_node', $actions);
        $this->assertContains('search_rag', $actions);

        $continue = collect($trace)->firstWhere('action', 'continue_node');
        $this->assertTrue($continue['metadata']['failed'] ?? false);
        $this->assertStringContainsString('503', $continue['metadata']['failure_reason'] ?? '');
    }

    /**
     * M11: an intentional retry carrying the same idempotency_key replays the prior
     * response rather than being silently dropped or re-executed.
     */
    public function test_idempotency_key_replays_prior_response_and_does_not_re_dispatch(): void
    {
        $context = new UnifiedActionContext('session-idem', 3);

        $contextManager = Mockery::mock(ContextManager::class);
        // getOrCreate only runs on the first (executed) turn, not the cached replay.
        $contextManager->shouldReceive('getOrCreate')->once()->with('session-idem', 3)->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        // dispatch must run exactly once across both calls — the second is a cache replay.
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(AgentResponse::conversational(message: 'Order placed once.', context: $context));

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            Mockery::mock(IntentRouter::class),
            new AgentPlanner(),
            $this->passthroughFinalizer($context),
            $this->noopSelection(),
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $options = ['idempotency_key' => 'retry-key-abc'];

        $first = $processor->process('place my order', 'session-idem', 3, $options);
        $this->assertSame('Order placed once.', $first->message);

        // Retry with the SAME key and SAME message returns the cached payload.
        $second = $processor->process('place my order', 'session-idem', 3, $options);
        $this->assertSame('Order placed once.', $second->message);
        $this->assertTrue($second->metadata['idempotent_replay'] ?? false);
    }

    /**
     * M11: without an idempotency_key, duplicate message content still falls through to
     * the existing content dedup behaviour (each call routes normally / is not replayed).
     */
    public function test_without_idempotency_key_each_call_routes_normally(): void
    {
        Cache::flush();

        $context = new UnifiedActionContext('session-no-key', 4);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->twice()->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturn(AgentResponse::conversational(message: 'Routed.', context: $context));

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            Mockery::mock(IntentRouter::class),
            new AgentPlanner(),
            $this->passthroughFinalizer($context),
            $this->noopSelection(),
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $first = $processor->process('hi', 'session-no-key', 4);
        $second = $processor->process('hi', 'session-no-key', 4);

        $this->assertSame('Routed.', $first->message);
        $this->assertSame('Routed.', $second->message);
        $this->assertNull($second->metadata['idempotent_replay'] ?? null);
    }
}
