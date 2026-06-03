<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
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

    /**
     * M8: when a CONTINUE_NODE follow-up fails and we degrade to local RAG, the fallback
     * dispatch must carry the failed CONTINUE_NODE decision in its routing trace. This is
     * the federation continuation path — the only path that still runs the dispatcher
     * before AiNative.
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
            $this->passthroughFinalizer($context),
            $node,
            $dispatcher,
            Mockery::mock(AiNativeRuntime::class)
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

        // AiNative owns the turn; it must run exactly once across both calls — the second
        // is a cache replay that never reaches the runtime.
        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::conversational(message: 'Order placed once.', context: $context));

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer($context),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native
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

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->twice()
            ->andReturn(AgentResponse::conversational(message: 'Routed.', context: $context));

        $processor = new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer($context),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native
        );

        $first = $processor->process('hi', 'session-no-key', 4);
        $second = $processor->process('hi', 'session-no-key', 4);

        $this->assertSame('Routed.', $first->message);
        $this->assertSame('Routed.', $second->message);
        $this->assertNull($second->metadata['idempotent_replay'] ?? null);
    }
}
