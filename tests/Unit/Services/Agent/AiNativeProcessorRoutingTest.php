<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeProcessorRoutingTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_processor_uses_ai_native_runtime_when_enabled(): void
    {
        config()->set('ai-agent.ai_native.enabled', true);

        $context = new UnifiedActionContext('ai-native-processor', 42);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('ai-native-processor', 42)
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with('create invoice', $context, Mockery::type('array'))
            ->andReturn(AgentResponse::conversational('AI native handled it.', $context));

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
            Mockery::mock(AgentSelectionService::class),
            Mockery::mock(NodeSessionManager::class),
            executionDispatcher: $dispatcher,
            aiNativeRuntime: $native
        );

        $response = $processor->process('create invoice', 'ai-native-processor', 42);

        $this->assertTrue($response->success);
        $this->assertSame('AI native handled it.', $response->message);
    }

    public function test_processor_routes_unrelated_chat_to_ai_native_without_skill_state(): void
    {
        config()->set('ai-agent.ai_native.enabled', true);

        $context = new UnifiedActionContext('ai-native-processor-small-talk', 42);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('ai-native-processor-small-talk', 42)
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

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
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            Mockery::mock(AgentSelectionService::class),
            Mockery::mock(NodeSessionManager::class),
            executionDispatcher: $dispatcher,
            aiNativeRuntime: $native
        );

        $response = $processor->process('hello', 'ai-native-processor-small-talk', 42);

        $this->assertTrue($response->success);
        $this->assertSame('Hello.', $response->message);
        $this->assertArrayNotHasKey('task_frame', $response->metadata['ai_native']);
        $this->assertSame([], $response->metadata['ai_native']['tool_results']);
    }

    public function test_processor_routes_force_rag_through_ai_native(): void
    {
        // force_rag no longer bypasses AiNative. When AiNative is enabled it owns the
        // turn and reaches RAG from inside the runtime; the flag is passed through as a
        // hint. Only a routed_to_node continuation may still skip AiNative.
        config()->set('ai-agent.ai_native.enabled', true);

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

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            Mockery::mock(AgentSelectionService::class),
            Mockery::mock(NodeSessionManager::class),
            executionDispatcher: $dispatcher,
            aiNativeRuntime: $native
        );

        $response = $processor->process('hello', 'force-rag-processor', 42, ['force_rag' => true]);

        $this->assertTrue($response->success);
        $this->assertSame('AI native handled it.', $response->message);
    }
}
