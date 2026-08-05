<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeProcessorRoutingTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_processor_uses_the_ai_native_runtime(): void
    {
        $context = new UnifiedActionContext('ai-native-processor', 42);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('ai-native-processor', 42)
            ->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('create invoice', $context, Mockery::on(
                static fn (array $options): bool =>
                    ($options['turn_decision']['route'] ?? null) === 'passthrough'
                    && ($options['turn_decision']['retrieval_mode'] ?? null) === 'host_managed'
                    && ($options['retrieval_decision']['status'] ?? null) === 'host_managed'
                    && ($options['retrieval_decision']['mode'] ?? null) === 'host_managed',
            ))
            ->andReturn(AgentResponse::conversational('AI native handled it.', $context));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            Mockery::mock(NodeSessionManager::class),
            $dispatcher,
            $native
        );

        $response = $processor->process('create invoice', 'ai-native-processor', 42);

        $this->assertTrue($response->success);
        $this->assertSame('AI native handled it.', $response->message);
    }

    public function test_precomputed_turn_and_retrieval_decisions_are_preserved(): void
    {
        $context = new UnifiedActionContext('precomputed-turn', 42);
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('build a page', $context, Mockery::on(
                static fn (array $options): bool =>
                    ($options['turn_decision']['route'] ?? null) === 'page_build'
                    && ($options['retrieval_decision']['status'] ?? null) === 'required'
                    && ($options['retrieval_decision']['mode'] ?? null) === 'domain_catalog',
            ))
            ->andReturn(AgentResponse::conversational('Prepared.', $context));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native,
        );

        $response = $processor->process('build a page', 'precomputed-turn', 42, [
            'turn_decision' => [
                'route' => 'page_build',
                'confidence' => 0.99,
                'retrieval_mode' => 'domain_catalog',
                'reason' => 'Catalog composition requested.',
            ],
            'retrieval_decision' => [
                'status' => 'required',
                'mode' => 'domain_catalog',
                'required' => true,
                'reason' => 'Catalog composition requested.',
            ],
        ]);

        $this->assertSame('Prepared.', $response->message);
    }

    public function test_processor_routes_unrelated_chat_to_ai_native_without_skill_state(): void
    {
        $context = new UnifiedActionContext('ai-native-processor-small-talk', 42);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('ai-native-processor-small-talk', 42)
            ->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('hello', $context, Mockery::on(fn (array $options): bool => !isset($options['skill_id'])))
            ->andReturnUsing(function (string $message, UnifiedActionContext $ctx): AgentResponse {
                $response = AgentResponse::conversational('Hello.', $ctx);
                $response->metadata = ['ai_native' => ['tool_results' => []]];

                return $response;
            });

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            Mockery::mock(NodeSessionManager::class),
            $dispatcher,
            $native
        );

        $response = $processor->process('hello', 'ai-native-processor-small-talk', 42);

        $this->assertTrue($response->success);
        $this->assertSame('Hello.', $response->message);
        $this->assertArrayNotHasKey('task_frame', $response->metadata['ai_native']);
        $this->assertSame([], $response->metadata['ai_native']['tool_results']);
    }

    public function test_processor_routes_force_rag_through_ai_native(): void
    {
        // force_rag no longer bypasses AiNative. AiNative owns the turn and reaches
        // RAG from inside the runtime; the flag is passed through as a
        // hint. Only a routed_to_node continuation may still skip AiNative.
        $context = new UnifiedActionContext('force-rag-processor', 42);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('force-rag-processor', 42)
            ->andReturn($context);

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('hello', $context, Mockery::on(fn (array $options): bool => ($options['force_rag'] ?? false) === true))
            ->andReturn(AgentResponse::conversational('AI native handled it.', $context));

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            Mockery::mock(NodeSessionManager::class),
            $dispatcher,
            $native
        );

        $response = $processor->process('hello', 'force-rag-processor', 42, ['force_rag' => true]);

        $this->assertTrue($response->success);
        $this->assertSame('AI native handled it.', $response->message);
    }
}
