<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentRunEventStreamServiceTest extends TestCase
{
    public function test_agent_run_event_stream_has_stable_event_contract(): void
    {
        $events = app(AgentRunEventStreamService::class)->names();

        $this->assertContains('run.started', $events);
        $this->assertContains('routing.stage_started', $events);
        $this->assertContains('routing.stage_abstained', $events);
        $this->assertContains('routing.decided', $events);
        $this->assertContains('rag.started', $events);
        $this->assertContains('rag.sources_found', $events);
        $this->assertContains('rag.completed', $events);
        $this->assertContains('tool.started', $events);
        $this->assertContains('tool.progress', $events);
        $this->assertContains('tool.completed', $events);
        $this->assertContains('tool.failed', $events);
        $this->assertContains('sub_agent.started', $events);
        $this->assertContains('sub_agent.completed', $events);
        $this->assertContains('approval.required', $events);
        $this->assertContains('approval.resolved', $events);
        $this->assertContains('artifact.created', $events);
        $this->assertContains('final_response.token_streamed', $events);
        $this->assertContains('final_response.stream_completed', $events);
        $this->assertContains('run.completed', $events);
        $this->assertContains('run.failed', $events);
        $this->assertContains('run.cancelled', $events);
        $this->assertContains('run.expired', $events);
    }

    public function test_event_stream_emits_laravel_event_and_persists_fallback_metadata(): void
    {
        Event::fake([AgentRunStreamed::class]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'stream-session',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['trace_id' => 'trace-1'],
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'routing',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $captured = [];

        $event = app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::ROUTING_DECIDED,
            $run,
            $step,
            ['decision' => 'search_rag'],
            ['runtime' => 'laravel'],
            function (array $event) use (&$captured): void {
                $captured[] = $event;
            }
        );

        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $dispatched): bool => $dispatched->event['id'] === $event['id']);
        $this->assertSame($event['id'], $captured[0]['id']);
        $this->assertSame('trace-1', $event['trace_id']);

        $fallback = app(AgentRunEventStreamService::class)->fallbackEvents($run->id);
        $this->assertCount(1, $fallback);
        $this->assertSame('routing.decided', $fallback[0]['name']);
    }

    public function test_event_stream_rejects_unknown_event_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown agent stream event [bad.event].');

        app(AgentRunEventStreamService::class)->emit('bad.event');
    }

    public function test_final_response_streaming_emits_token_events_and_completion(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'final-stream-session',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['trace_id' => 'trace-final'],
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'final_response',
            'type' => 'final_response',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')
            ->once()
            ->withArgs(fn (AIRequest $request): bool => $request->isStream())
            ->andReturn((function (): \Generator {
                yield 'Hel';
                yield ['content' => 'lo'];
            })());

        $service = new AgentFinalResponseStreamingService($ai, app(AgentRunEventStreamService::class));
        $captured = [];

        $tokens = iterator_to_array($service->stream(
            new AIRequest(
                prompt: 'Say hello',
                engine: EngineEnum::OPENAI,
                model: EntityEnum::GPT_4O_MINI
            ),
            $run,
            $step,
            ['runtime' => 'laravel'],
            function (array $event) use (&$captured): void {
                $captured[] = $event;
            }
        ));

        $this->assertSame(['Hel', 'lo'], $tokens);
        $this->assertSame([
            'final_response.token_streamed',
            'final_response.token_streamed',
            'final_response.stream_completed',
        ], array_column($captured, 'name'));
        $this->assertSame('Hello', $captured[2]['payload']['content']);
        $this->assertSame(2, $captured[2]['payload']['token_count']);

        $fallback = app(AgentRunEventStreamService::class)->fallbackEvents($run->id);
        $this->assertSame([
            'final_response.token_streamed',
            'final_response.token_streamed',
            'final_response.stream_completed',
        ], array_column($fallback, 'name'));
    }
}
