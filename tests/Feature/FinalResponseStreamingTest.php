<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * FINAL-RESPONSE TOKEN STREAMING — the conversational reply "types out live".
 *
 * When an agent run produces a CONVERSATIONAL final reply AND final-response
 * streaming is enabled, {@see AgentConversationService::executeConversational}
 * routes the reply through {@see AgentFinalResponseStreamingService::stream},
 * mirroring each provider chunk into the run's event stream as
 * final_response.token_streamed events, then a final
 * final_response.stream_completed carrying the full content. An SSE/broadcast
 * consumer replays those events to type the answer out token-by-token.
 *
 * The streamed tokens are reassembled into the same fully-formed AgentResponse
 * the synchronous path would have returned, so the run's final_response is
 * IDENTICAL to today — just streamed along the way.
 *
 * Default (streaming disabled) MUST be unchanged: a single synchronous
 * generate(), and NO token events.
 *
 * APPROACH: PRIMARY (real streaming). The mocked AIEngineService::stream()
 * yields ['Hello', ', ', 'world', '!']; the real AgentFinalResponseStreamingService
 * and real AgentRunEventStreamService persist the events on the run/step.
 */
class FinalResponseStreamingTest extends TestCase
{
    private const SESSION = 'final-stream-session';

    protected function setUp(): void
    {
        parent::setUp();

        // Keep memory machinery quiet so the conversational path is a clean
        // single-generation step with no spurious AI calls.
        config()->set('ai-agent.conversation_memory.enabled', false);
        config()->set('ai-engine.inject_user_context', false);
    }

    public function test_conversational_reply_streams_token_by_token_when_streaming_enabled(): void
    {
        config()->set('ai-agent.final_response_streaming.enabled', true);

        // Provider chunks the "live typing" is reconstructed from.
        $chunks = ['Hello', ', ', 'world', '!'];

        $ai = Mockery::mock(AIEngineService::class);
        // Streaming path: stream() yields the chunks; generate() must NOT be called.
        $ai->shouldReceive('stream')
            ->once()
            ->with(Mockery::type(AIRequest::class))
            ->andReturnUsing(function () use ($chunks): \Generator {
                foreach ($chunks as $chunk) {
                    yield $chunk;
                }
            });
        $ai->shouldNotReceive('generate');
        $this->app->instance(AIEngineService::class, $ai);
        // Rebind the streaming service onto the mocked engine.
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService::class);
        $this->app->forgetInstance(AgentConversationService::class);

        [$run, $step] = $this->makeRunAndStep();

        $context = new UnifiedActionContext(sessionId: self::SESSION, userId: '7');
        $options = [
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $step->id,
            'trace_id' => 'trace-stream-1',
        ];

        $response = $this->app->make(AgentConversationService::class)
            ->executeConversational('Say hi', $context, $options);

        // The run's final_response content equals the reassembled stream.
        $this->assertTrue($response->success);
        $this->assertSame('Hello, world!', $response->message);

        // ---- The token events a frontend types out, in emission order. ----
        $run->refresh();
        $events = collect($run->metadata['events'] ?? []);

        $tokenEvents = $events
            ->where('name', AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED)
            ->values();

        // One token event per non-empty provider chunk, in order.
        $this->assertCount(count($chunks), $tokenEvents);
        $this->assertSame(
            $chunks,
            $tokenEvents->pluck('payload.token')->all(),
            'Each provider chunk is mirrored as one ordered token_streamed event.'
        );

        // The token indexes increase monotonically from 1.
        $this->assertSame([1, 2, 3, 4], $tokenEvents->pluck('payload.index')->all());

        // The streamed tokens reconstruct the final text.
        $this->assertSame('Hello, world!', $tokenEvents->pluck('payload.token')->implode(''));

        // A single stream_completed event closes the stream with the full content.
        $completed = $events
            ->where('name', AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED)
            ->values();
        $this->assertCount(1, $completed);
        $this->assertSame('Hello, world!', $completed->first()['payload']['content'] ?? null);
        $this->assertSame(count($chunks), $completed->first()['payload']['token_count'] ?? null);

        // token_streamed all precede the single stream_completed.
        $names = $events->pluck('name')->all();
        $relevant = array_values(array_filter($names, static fn (string $n): bool => in_array($n, [
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
        ], true)));
        $this->assertSame([
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
        ], $relevant);
    }

    public function test_conversational_reply_does_not_stream_tokens_when_streaming_disabled(): void
    {
        // Default-off: the feature flag stays disabled.
        config()->set('ai-agent.final_response_streaming.enabled', false);

        $ai = Mockery::mock(AIEngineService::class);
        // Non-streaming path: a single synchronous generate(); stream() must NOT fire.
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::type(AIRequest::class))
            ->andReturn(AIResponse::success(
                content: 'Hello, world!',
                engine: 'openai',
                model: 'gpt-4o-mini',
            ));
        $ai->shouldNotReceive('stream');
        $this->app->instance(AIEngineService::class, $ai);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService::class);
        $this->app->forgetInstance(AgentConversationService::class);

        [$run, $step] = $this->makeRunAndStep();

        $context = new UnifiedActionContext(sessionId: self::SESSION, userId: '7');
        // Run context IS present, but the flag is off -> no streaming.
        $options = [
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $step->id,
            'trace_id' => 'trace-nostream-1',
        ];

        $response = $this->app->make(AgentConversationService::class)
            ->executeConversational('Say hi', $context, $options);

        // Behaviour unchanged: same final message.
        $this->assertTrue($response->success);
        $this->assertSame('Hello, world!', $response->message);

        // NO token-streaming events were emitted.
        $run->refresh();
        $names = collect($run->metadata['events'] ?? [])->pluck('name')->all();
        $this->assertNotContains(AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED, $names);
        $this->assertNotContains(AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED, $names);
    }

    /**
     * @return array{0: AIAgentRun, 1: \LaravelAIEngine\Models\AIAgentRunStep}
     */
    private function makeRunAndStep(): array
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => self::SESSION,
            'user_id' => '7',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'run',
            'type' => 'agent_run',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'process',
        ]);

        return [$run, $step];
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
