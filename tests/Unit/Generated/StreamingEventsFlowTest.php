<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Models\AIProviderToolRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Streaming + run events surface.
 *
 * Covers AgentRunEventStreamService (emit / fallbackEvents / appendEvent /
 * makeEvent trace precedence / truncation / sink-swallow / null short-circuit),
 * AgentFinalResponseStreamingService (normalizeChunk branch matrix + persistence),
 * AgentRunSseStreamService (resume replay + terminal re-fetch), the
 * AgentRunStepRepository unique-violation retry loop, and the
 * ProviderToolRunRepository / AgentRunRepository find / findOrFail / paginate
 * matrices that back the event timeline.
 *
 * Self-contained: extends the package Feature TestCase (sqlite :memory: + DB),
 * mocks AIEngineService rather than making any network call.
 */
class StreamingEventsFlowTest extends TestCase
{
    private function stream(): AgentRunEventStreamService
    {
        return app(AgentRunEventStreamService::class);
    }

    private function makeRun(array $attributes = []): AIAgentRun
    {
        return app(AgentRunRepository::class)->create(array_merge([
            'session_id' => 'streaming-events-'.uniqid('', true),
            'status' => AIAgentRun::STATUS_RUNNING,
        ], $attributes));
    }

    private function makeStep(AIAgentRun $run, string $type): AIAgentRunStep
    {
        return app(AgentRunStepRepository::class)->create($run, [
            'step_key' => $type,
            'type' => $type,
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
    }

    /**
     * Emit using a fixed, advancing test-clock so emitted_at ISO strings are
     * strictly monotonic and the chronological usort in fallbackEvents is
     * deterministic.
     */
    private function emitAt(
        Carbon $clock,
        string $name,
        AIAgentRun|int|string|null $run = null,
        AIAgentRunStep|int|string|null $step = null,
        array $payload = [],
        array $metadata = []
    ): array {
        $clock->addSecond();
        Carbon::setTestNow($clock->copy());

        return $this->stream()->emit($name, $run, $step, $payload, $metadata);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Scenario: cross-model fallbackEvents merge + chronological interleave (P5)
    // ---------------------------------------------------------------------
    public function test_fallback_events_dedup_and_chronological_across_run_and_steps(): void
    {
        $clock = Carbon::parse('2026-06-03T00:00:00Z');

        $run = $this->makeRun(['metadata' => ['trace_id' => 'trace-cross']]);

        // Each event written to BOTH run+step is persisted twice (once per model)
        // but must surface exactly once, de-duped on event id.
        $started = $this->emitAt($clock, AgentRunEventStreamService::RUN_STARTED, $run);

        $step1 = $this->makeStep($run, 'routing');
        $routing = $this->emitAt($clock, AgentRunEventStreamService::ROUTING_DECIDED, $run, $step1, ['decision' => 'rag']);

        $step2 = $this->makeStep($run, 'rag');
        $rag = $this->emitAt($clock, AgentRunEventStreamService::RAG_SOURCES_FOUND, $run, $step2, ['count' => 3]);
        $tool = $this->emitAt($clock, AgentRunEventStreamService::TOOL_STARTED, $run, $step2, ['tool' => 'search']);

        $step3 = $this->makeStep($run, 'final');
        $final = $this->emitAt($clock, AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED, $run, $step3, ['content' => 'done']);
        $completed = $this->emitAt($clock, AgentRunEventStreamService::RUN_COMPLETED, $run);

        $events = $this->stream()->fallbackEvents($run);

        // De-dup: 6 distinct emit() calls -> exactly 6 surviving events,
        // NOT run-rows + step-rows.
        $this->assertCount(6, $events);

        // Each id appears exactly once.
        $ids = array_column($events, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));

        // Chronological by emitted_at across run + multiple step models.
        $this->assertSame([
            AgentRunEventStreamService::RUN_STARTED,
            AgentRunEventStreamService::ROUTING_DECIDED,
            AgentRunEventStreamService::RAG_SOURCES_FOUND,
            AgentRunEventStreamService::TOOL_STARTED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
            AgentRunEventStreamService::RUN_COMPLETED,
        ], array_column($events, 'name'));

        // The interleaved-step events resolved to the expected ids.
        $this->assertSame(
            [$started['id'], $routing['id'], $rag['id'], $tool['id'], $final['id'], $completed['id']],
            $ids
        );
    }

    // ---------------------------------------------------------------------
    // Scenario: end-to-end producer ordering through real persistence (P5)
    // ---------------------------------------------------------------------
    public function test_end_to_end_producer_ordering_with_streamed_final_response(): void
    {
        $clock = Carbon::parse('2026-06-03T01:00:00Z');

        $run = $this->makeRun(['metadata' => ['trace_id' => 'trace-e2e']]);

        $this->emitAt($clock, AgentRunEventStreamService::RUN_STARTED, $run);

        $routingStep = $this->makeStep($run, 'routing');
        $this->emitAt($clock, AgentRunEventStreamService::ROUTING_STAGE_STARTED, $run, $routingStep);
        $this->emitAt($clock, AgentRunEventStreamService::ROUTING_DECIDED, $run, $routingStep);

        $ragStep = $this->makeStep($run, 'rag');
        $this->emitAt($clock, AgentRunEventStreamService::RAG_STARTED, $run, $ragStep);
        $this->emitAt($clock, AgentRunEventStreamService::RAG_SOURCES_FOUND, $run, $ragStep);
        $this->emitAt($clock, AgentRunEventStreamService::RAG_COMPLETED, $run, $ragStep);

        $toolStep = $this->makeStep($run, 'tool');
        $this->emitAt($clock, AgentRunEventStreamService::TOOL_STARTED, $run, $toolStep);
        $this->emitAt($clock, AgentRunEventStreamService::TOOL_COMPLETED, $run, $toolStep);

        // Final-response token streaming through the real streaming service.
        $finalStep = $this->makeStep($run, 'final_response');
        $clock->addSecond();
        Carbon::setTestNow($clock->copy());

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturn((function (): \Generator {
            yield 'Hel';
            yield 'lo';
            yield '!';
        })());

        $service = new AgentFinalResponseStreamingService($ai, $this->stream());
        $tokens = iterator_to_array($service->stream(
            new AIRequest(prompt: 'hi', engine: EngineEnum::OPENAI, model: EntityEnum::GPT_4O_MINI),
            $run,
            $finalStep
        ));
        $this->assertSame(['Hel', 'lo', '!'], $tokens);

        $clock->addSecond();
        Carbon::setTestNow($clock->copy());
        $this->emitAt($clock, AgentRunEventStreamService::RUN_COMPLETED, $run);

        $events = $this->stream()->fallbackEvents($run);
        $names = array_column($events, 'name');

        // The documented producer order, monotonic.
        $this->assertSame([
            AgentRunEventStreamService::RUN_STARTED,
            AgentRunEventStreamService::ROUTING_STAGE_STARTED,
            AgentRunEventStreamService::ROUTING_DECIDED,
            AgentRunEventStreamService::RAG_STARTED,
            AgentRunEventStreamService::RAG_SOURCES_FOUND,
            AgentRunEventStreamService::RAG_COMPLETED,
            AgentRunEventStreamService::TOOL_STARTED,
            AgentRunEventStreamService::TOOL_COMPLETED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
            AgentRunEventStreamService::RUN_COMPLETED,
        ], $names);

        // token_streamed indexes are 1..N strictly before stream_completed.
        $tokenIndexes = [];
        foreach ($events as $event) {
            if ($event['name'] === AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED) {
                $tokenIndexes[] = $event['payload']['index'];
            }
        }
        $this->assertSame([1, 2, 3], $tokenIndexes);

        // No duplicates, terminal last.
        $this->assertSame(AgentRunEventStreamService::RUN_COMPLETED, $names[array_key_last($names)]);
        $ids = array_column($events, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    // ---------------------------------------------------------------------
    // Scenario: normalizeChunk full branch matrix through stream() (P5)
    // ---------------------------------------------------------------------
    public function test_normalize_chunk_branch_matrix(): void
    {
        $run = $this->makeRun();
        $step = $this->makeStep($run, 'final_response');

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'STR';
            }
        };

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturn((function () use ($stringable): \Generator {
            yield 'plain';                                       // string
            yield ['content' => 'A'];                            // array content
            yield ['text' => 'B'];                               // array text fallback
            yield ['delta' => 'C'];                              // array delta fallback
            yield AIResponse::success(content: 'D', engine: 'openai', model: 'gpt-4o-mini'); // AIResponse
            yield $stringable;                                   // Stringable
            yield 42;                                            // int scalar
            yield true;                                          // bool scalar
            yield null;                                          // null -> skipped
            yield '';                                            // empty -> skipped
            yield new \stdClass();                               // unrecognized -> skipped
        })());

        $service = new AgentFinalResponseStreamingService($ai, $this->stream());
        $tokens = iterator_to_array($service->stream(
            new AIRequest(prompt: 'x', engine: EngineEnum::OPENAI, model: EntityEnum::GPT_4O_MINI),
            $run,
            $step
        ));

        // null, '', stdClass produce NO token (mid-stream skip).
        $this->assertSame(['plain', 'A', 'B', 'C', 'D', 'STR', '42', '1'], $tokens);

        $events = $this->stream()->fallbackEvents($run);
        $tokenEvents = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED
        ));
        $completed = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED
        ));

        // One token_streamed per emitted token, index strictly 1..8.
        $this->assertCount(8, $tokenEvents);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column(array_column($tokenEvents, 'payload'), 'index'));

        // stream_completed content == concatenation; token_count == number of yields.
        $this->assertCount(1, $completed);
        $this->assertSame('plainABCDSTR421', $completed[0]['payload']['content']);
        $this->assertSame(8, $completed[0]['payload']['token_count']);
    }

    // ---------------------------------------------------------------------
    // Scenario: null run AND null step direct-use path, no persistence (P5)
    // ---------------------------------------------------------------------
    public function test_stream_without_run_or_step_skips_persistence_but_broadcasts(): void
    {
        Event::fake([AgentRunStreamed::class]);

        $runsBefore = AIAgentRun::count();
        $stepsBefore = AIAgentRunStep::count();

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturn((function (): \Generator {
            yield 'one';
            yield 'two';
        })());

        $service = new AgentFinalResponseStreamingService($ai, $this->stream());
        $tokens = iterator_to_array($service->stream(
            new AIRequest(prompt: 'x', engine: EngineEnum::OPENAI, model: EntityEnum::GPT_4O_MINI),
            null,
            null
        ));

        $this->assertSame(['one', 'two'], $tokens);

        // No DB writes: counts unchanged.
        $this->assertSame($runsBefore, AIAgentRun::count());
        $this->assertSame($stepsBefore, AIAgentRunStep::count());

        // Events still broadcast: 2 token_streamed + 1 stream_completed.
        Event::assertDispatchedTimes(AgentRunStreamed::class, 3);

        // makeEvent stripped null run_id/step_id via array_filter.
        Event::assertDispatched(AgentRunStreamed::class, function (AgentRunStreamed $e): bool {
            return !array_key_exists('run_id', $e->event)
                && !array_key_exists('step_id', $e->event);
        });
    }

    // ---------------------------------------------------------------------
    // Scenario: sink failure mid-stream must not abort tokens or completed (P5)
    // ---------------------------------------------------------------------
    public function test_sink_failure_mid_stream_does_not_abort_and_logs_warning(): void
    {
        $sinkWarnings = [];
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$sinkWarnings): void {
            if ($message === 'Agent run event sink failed') {
                $sinkWarnings[] = $context;
            }
        });
        $logChannel->shouldReceive('info', 'debug', 'error', 'notice', 'log')->andReturnNull();
        Log::shouldReceive('channel')->andReturn($logChannel);
        Log::shouldReceive('warning', 'info', 'debug', 'error', 'notice', 'log')->andReturnNull();

        $run = $this->makeRun();
        $step = $this->makeStep($run, 'final_response');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('stream')->once()->andReturn((function (): \Generator {
            yield 'a';
            yield 'b';
            yield 'c';
        })());

        // Throw on the 2nd sink invocation only.
        $calls = 0;
        $sink = function (array $event) use (&$calls): void {
            $calls++;
            if ($calls === 2) {
                throw new \RuntimeException('client disconnected');
            }
        };

        $service = new AgentFinalResponseStreamingService($ai, $this->stream());
        $tokens = iterator_to_array($service->stream(
            new AIRequest(prompt: 'x', engine: EngineEnum::OPENAI, model: EntityEnum::GPT_4O_MINI),
            $run,
            $step,
            [],
            $sink
        ));

        // All 3 tokens yielded despite the mid-stream sink throw.
        $this->assertSame(['a', 'b', 'c'], $tokens);

        // Exactly one sink-failed warning carrying event_id/event_name.
        $this->assertCount(1, $sinkWarnings);
        $this->assertArrayHasKey('event_id', $sinkWarnings[0]);
        $this->assertSame(AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED, $sinkWarnings[0]['event_name']);

        // All 3 token events + completed persisted.
        $events = $this->stream()->fallbackEvents($run);
        $this->assertSame([
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
        ], array_column($events, 'name'));
        $completed = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED
        ));
        $this->assertSame(3, $completed[0]['payload']['token_count']);
        $this->assertSame('abc', $completed[0]['payload']['content']);
    }

    // ---------------------------------------------------------------------
    // Scenario: trace_id resolution precedence (P4)
    // ---------------------------------------------------------------------
    public function test_trace_id_resolution_precedence(): void
    {
        $run = $this->makeRun(['metadata' => ['trace_id' => 'run-trace']]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'routing',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['trace_id' => 'step-trace'],
        ]);

        // No trace_id in metadata arg -> step metadata wins over run metadata.
        $reasoning = $this->stream()->emit(
            AgentRunEventStreamService::AGENT_REASONING,
            $run,
            $step,
            ['thought' => 'thinking']
        );
        $this->assertSame('step-trace', $reasoning['trace_id']);

        // metadata-arg trace_id wins over both step and run.
        $plan = $this->stream()->emit(
            AgentRunEventStreamService::PLAN_UPDATED,
            $run,
            $step,
            ['plan' => 'x'],
            ['trace_id' => 'arg-trace']
        );
        $this->assertSame('arg-trace', $plan['trace_id']);

        // Run-only fallback (no step).
        $run2 = $this->makeRun(['metadata' => ['trace_id' => 'run2-trace']]);
        $started = $this->stream()->emit(AgentRunEventStreamService::RUN_STARTED, $run2);
        $this->assertSame('run2-trace', $started['trace_id']);

        // trace_id persisted on each event.
        $persisted = $this->stream()->fallbackEvents($run);
        foreach ($persisted as $event) {
            $this->assertArrayHasKey('trace_id', $event);
        }
    }

    // ---------------------------------------------------------------------
    // Scenario: per-model truncation independence; oldest dropped on a STEP (P4)
    // ---------------------------------------------------------------------
    public function test_per_model_truncation_independence_on_run_and_step(): void
    {
        config()->set('ai-agent.event_stream.persisted_events_limit', 3);

        $truncations = [];
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$truncations): void {
            if ($message === 'Agent run event stream truncated persisted events') {
                $truncations[] = $context;
            }
        });
        $logChannel->shouldReceive('info', 'debug', 'error', 'notice', 'log')->andReturnNull();
        Log::shouldReceive('channel')->andReturn($logChannel);
        Log::shouldReceive('warning', 'info', 'debug', 'error', 'notice', 'log')->andReturnNull();

        $run = $this->makeRun();
        $step1 = $this->makeStep($run, 'routing');

        // 5 distinct events on the STEP only.
        $stepNames = [
            AgentRunEventStreamService::ROUTING_STAGE_STARTED,
            AgentRunEventStreamService::ROUTING_DECIDED,
            AgentRunEventStreamService::RAG_STARTED,
            AgentRunEventStreamService::RAG_SOURCES_FOUND,
            AgentRunEventStreamService::RAG_COMPLETED,
        ];
        foreach ($stepNames as $name) {
            $this->stream()->emit($name, null, $step1, ['i' => $name]);
        }

        // 5 distinct events on the RUN only.
        $runNames = [
            AgentRunEventStreamService::RUN_STARTED,
            AgentRunEventStreamService::TOOL_STARTED,
            AgentRunEventStreamService::TOOL_PROGRESS,
            AgentRunEventStreamService::TOOL_COMPLETED,
            AgentRunEventStreamService::RUN_COMPLETED,
        ];
        foreach ($runNames as $name) {
            $this->stream()->emit($name, $run, null, ['i' => $name]);
        }

        $step1->refresh();
        $run->refresh();

        $stepEvents = $step1->metadata['events'] ?? [];
        $runEvents = $run->metadata['events'] ?? [];

        // Each model holds exactly the LAST 3 (FIFO drop of oldest two).
        $this->assertCount(3, $stepEvents);
        $this->assertSame(array_slice($stepNames, -3), array_column($stepEvents, 'name'));

        $this->assertCount(3, $runEvents);
        $this->assertSame(array_slice($runNames, -3), array_column($runEvents, 'name'));

        // A truncation warning fires per overflow append on the correct model class.
        $stepTruncations = array_values(array_filter(
            $truncations,
            static fn (array $c): bool => ($c['model'] ?? null) === AIAgentRunStep::class
        ));
        $runTruncations = array_values(array_filter(
            $truncations,
            static fn (array $c): bool => ($c['model'] ?? null) === AIAgentRun::class
        ));
        $this->assertNotEmpty($stepTruncations);
        $this->assertNotEmpty($runTruncations);
        $this->assertSame(1, $stepTruncations[0]['dropped']); // count(4) - limit(3)

        // fallbackEvents merges two independently-truncated windows (<= 6).
        $events = $this->stream()->fallbackEvents($run);
        $this->assertCount(6, $events);
    }

    // ---------------------------------------------------------------------
    // Scenario: createWithNextSequence retry-on-unique-violation (P5)
    // ---------------------------------------------------------------------
    public function test_create_with_next_sequence_retries_on_transient_unique_violation(): void
    {
        $run = $this->makeRun();

        // Spy subclass: the first nextSequence() throws a transient unique
        // violation (concurrent writer winning the race), the retry succeeds.
        $repository = new class extends AgentRunStepRepository {
            public int $sequenceCalls = 0;

            public function nextSequence(AIAgentRun|int $run): int
            {
                $this->sequenceCalls++;
                if ($this->sequenceCalls === 1) {
                    // Force a transient unique-constraint violation on the first
                    // attempt, exactly like a concurrent insert colliding on
                    // unique(run_id, sequence).
                    throw new QueryException(
                        'testing',
                        'insert into ai_agent_run_steps',
                        [],
                        new \PDOException('UNIQUE constraint failed: ai_agent_run_steps.run_id, ai_agent_run_steps.sequence')
                    );
                }

                return parent::nextSequence($run);
            }
        };

        // The QueryException constructed above has no errorInfo, but its message
        // contains "unique" -> isUniqueConstraintViolation() is true (message branch).
        $step = $repository->create($run, [
            'step_key' => 'routing',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $this->assertInstanceOf(AIAgentRunStep::class, $step);
        $this->assertSame(2, $repository->sequenceCalls, 'Expected exactly one retry after the transient unique violation.');
        $this->assertNotEmpty($step->uuid);
        $this->assertSame(1, (int) $step->sequence);
    }

    public function test_create_with_next_sequence_rethrows_non_unique_query_exception_immediately(): void
    {
        $run = $this->makeRun();

        $repository = new class extends AgentRunStepRepository {
            public int $sequenceCalls = 0;

            public function nextSequence(AIAgentRun|int $run): int
            {
                $this->sequenceCalls++;

                // A non-unique failure (e.g. HY000) must short-circuit re-throw.
                throw new QueryException(
                    'testing',
                    'insert into ai_agent_run_steps',
                    [],
                    new class('general error') extends \PDOException {
                        public function __construct(string $message)
                        {
                            parent::__construct($message);
                            $this->errorInfo = ['HY000', 1, 'general error'];
                        }
                    }
                );
            }
        };

        try {
            $repository->create($run, [
                'step_key' => 'routing',
                'type' => 'routing',
                'status' => AIAgentRun::STATUS_RUNNING,
            ]);
            $this->fail('Expected a QueryException to propagate.');
        } catch (QueryException $e) {
            // Re-thrown after a single attempt: NO retry loop.
            $this->assertSame(1, $repository->sequenceCalls);
            $this->assertStringContainsString('general error', $e->getMessage());
        }
    }

    public function test_create_with_next_sequence_rethrows_after_exhausting_retries(): void
    {
        $run = $this->makeRun();

        $repository = new class extends AgentRunStepRepository {
            public int $sequenceCalls = 0;

            public function nextSequence(AIAgentRun|int $run): int
            {
                $this->sequenceCalls++;

                // Persistent unique violation across every attempt.
                throw new QueryException(
                    'testing',
                    'insert into ai_agent_run_steps',
                    [],
                    new \PDOException('UNIQUE constraint failed')
                );
            }
        };

        try {
            $repository->create($run, [
                'step_key' => 'routing',
                'type' => 'routing',
                'status' => AIAgentRun::STATUS_RUNNING,
            ]);
            $this->fail('Expected the QueryException to be re-thrown after exhaustion.');
        } catch (QueryException $e) {
            // Max 5 attempts then re-throw (not swallowed, not infinite).
            $this->assertSame(5, $repository->sequenceCalls);
            $this->assertStringContainsString('UNIQUE', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Scenario: ProviderToolRunRepository CRUD + paginate filter matrix (P4)
    // ---------------------------------------------------------------------
    public function test_provider_tool_run_repository_crud_and_paginate_matrix(): void
    {
        $repo = app(ProviderToolRunRepository::class);

        // Distinct, advancing created_at so latest() ordering is deterministic.
        Carbon::setTestNow(Carbon::parse('2026-06-03T04:00:01Z'));
        $a = $repo->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'completed', 'provider' => 'openai', 'user_id' => 'u1']);
        Carbon::setTestNow(Carbon::parse('2026-06-03T04:00:02Z'));
        $b = $repo->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'failed', 'provider' => 'anthropic', 'user_id' => 'u2']);
        Carbon::setTestNow(Carbon::parse('2026-06-03T04:00:03Z'));
        $c = $repo->create(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'completed', 'provider' => 'gemini', 'user_id' => 'u1']);
        Carbon::setTestNow();

        // find by integer id and by uuid.
        $this->assertTrue($repo->find($a->id)->is($a));
        $this->assertTrue($repo->find($a->uuid)->is($a));
        // null/empty guard.
        $this->assertNull($repo->find(null));
        $this->assertNull($repo->find(''));

        // findOrFail exact message.
        try {
            $repo->findOrFail(999999);
            $this->fail('Expected InvalidArgumentException for missing provider tool run.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Provider tool run [999999] was not found.', $e->getMessage());
        }

        // update + refresh.
        $updated = $repo->update($a, ['status' => 'cancelled']);
        $this->assertSame('cancelled', $updated->status);
        $this->assertSame('cancelled', $a->fresh()->status);

        // paginate status filter (a is now cancelled, c remains completed).
        $byStatus = $repo->paginate(['status' => 'completed']);
        $this->assertSame([$c->id], $byStatus->pluck('id')->all());

        // paginate provider filter.
        $byProvider = $repo->paginate(['provider' => 'anthropic']);
        $this->assertSame([$b->id], $byProvider->pluck('id')->all());

        // paginate user_id filter (a + c), latest-first.
        $byUser = $repo->paginate(['user_id' => 'u1']);
        $this->assertEqualsCanonicalizing([$a->id, $c->id], $byUser->pluck('id')->all());

        // perPage clamps: 500 -> 100, 0 -> 1.
        $this->assertSame(100, $repo->paginate([], 500)->perPage());
        $this->assertSame(1, $repo->paginate([], 0)->perPage());

        // latest() ordering: newest first.
        $all = $repo->paginate([]);
        $this->assertSame($c->id, $all->first()->id);
    }

    // ---------------------------------------------------------------------
    // Scenario: AgentRunRepository paginate matrix + find/findOrFail/transition (P3)
    // ---------------------------------------------------------------------
    public function test_agent_run_repository_paginate_matrix_and_transition(): void
    {
        $repo = app(AgentRunRepository::class);

        $r1 = $repo->create([
            'session_id' => 's1', 'user_id' => 'u1', 'tenant_id' => 't1',
            'workspace_id' => 'w1', 'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $r2 = $repo->create([
            'session_id' => 's2', 'user_id' => 'u2', 'tenant_id' => 't2',
            'workspace_id' => 'w2', 'status' => AIAgentRun::STATUS_COMPLETED,
        ]);
        $r3 = $repo->create([
            'session_id' => 's1', 'user_id' => 'u1', 'tenant_id' => 't1',
            'workspace_id' => 'w1', 'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        // Single filters.
        $this->assertEqualsCanonicalizing([$r1->id, $r3->id], $repo->paginate(['status' => AIAgentRun::STATUS_RUNNING])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$r1->id, $r3->id], $repo->paginate(['session_id' => 's1'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$r1->id, $r3->id], $repo->paginate(['user_id' => 'u1'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$r2->id], $repo->paginate(['tenant_id' => 't2'])->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$r2->id], $repo->paginate(['workspace_id' => 'w2'])->pluck('id')->all());

        // Combined filter ANDs together.
        $this->assertEqualsCanonicalizing(
            [$r1->id, $r3->id],
            $repo->paginate(['status' => AIAgentRun::STATUS_RUNNING, 'workspace_id' => 'w1'])->pluck('id')->all()
        );

        // perPage clamps.
        $this->assertSame(100, $repo->paginate([], 500)->perPage());
        $this->assertSame(1, $repo->paginate([], 0)->perPage());

        // find by uuid vs integer id.
        $this->assertTrue($repo->find($r1->uuid)->is($r1));
        $this->assertTrue($repo->find($r1->id)->is($r1));

        // findOrFail exact message.
        try {
            $repo->findOrFail(987654);
            $this->fail('Expected InvalidArgumentException for missing agent run.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Agent run [987654] was not found.', $e->getMessage());
        }

        // transition with a valid status persists; resolve the run by uuid first.
        $resolved = $repo->find($r1->uuid);
        $transitioned = $repo->transition($resolved, AIAgentRun::STATUS_COMPLETED);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $transitioned->status);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $r1->fresh()->status);

        // Unsupported status throws before any write.
        try {
            $repo->transition($r3, 'not-a-real-status');
            $this->fail('Expected InvalidArgumentException for unsupported status.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported agent run status', $e->getMessage());
        }
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $r3->fresh()->status);
    }

    // ---------------------------------------------------------------------
    // Scenario: SSE happy resume — replay only events AFTER last_event_id (P5)
    // ---------------------------------------------------------------------
    public function test_sse_resume_replays_only_events_after_cursor(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);
        config()->set('ai-agent.event_stream.sse.allow_anonymous_runs', true);
        config()->set('ai-agent.event_stream.sse.max_seconds', 1);
        config()->set('ai-agent.event_stream.sse.poll_milliseconds', 100);

        $clock = Carbon::parse('2026-06-03T02:00:00Z');
        // Completed (terminal) so the SSE loop drains immediately.
        $run = $this->makeRun(['status' => AIAgentRun::STATUS_COMPLETED]);

        $e1 = $this->emitAt($clock, AgentRunEventStreamService::RUN_STARTED, $run);
        $e2 = $this->emitAt($clock, AgentRunEventStreamService::ROUTING_DECIDED, $run, null, ['decision' => 'rag']);
        $e3 = $this->emitAt($clock, AgentRunEventStreamService::RAG_SOURCES_FOUND, $run, null, ['count' => 2]);
        $e4 = $this->emitAt($clock, AgentRunEventStreamService::TOOL_STARTED, $run, null, ['tool' => 't']);
        $e5 = $this->emitAt($clock, AgentRunEventStreamService::RUN_COMPLETED, $run, null, ['message' => 'Done']);
        Carbon::setTestNow();

        // No resume-gap warning should be logged when the cursor is located.
        $resumeGapLogged = false;
        $logChannel = Mockery::mock();
        $logChannel->shouldReceive('warning')->andReturnUsing(function (string $message) use (&$resumeGapLogged): void {
            if ($message === 'Agent run SSE resume could not locate last_event_id') {
                $resumeGapLogged = true;
            }
        });
        $logChannel->shouldReceive('info', 'debug', 'error', 'notice', 'log')->andReturnNull();
        Log::shouldReceive('channel')->andReturn($logChannel);
        Log::shouldReceive('warning', 'info', 'debug', 'error', 'notice', 'log')->andReturnNull();

        $response = $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream?last_event_id={$e2['id']}&timeout=1&poll=100");
        $response->assertOk();
        $content = $response->streamedContent();

        // Frames for E3,E4,E5 only.
        $this->assertStringContainsString("event: rag.sources_found\n", $content);
        $this->assertStringContainsString("event: tool.started\n", $content);
        $this->assertStringContainsString("event: run.completed\n", $content);
        // E1,E2 skipped.
        $this->assertStringNotContainsString("id: {$e1['id']}\n", $content);
        $this->assertStringNotContainsString("id: {$e2['id']}\n", $content);
        // Each frame carries an activity label.
        $this->assertStringContainsString('"activity":', $content);
        // No resume-gap warning since cursor was found.
        $this->assertFalse($resumeGapLogged);
    }

    // ---------------------------------------------------------------------
    // Scenario: runtime-control cancel emits RUN_CANCELLED + SSE terminal (P4)
    // ---------------------------------------------------------------------
    public function test_runtime_cancel_emits_run_cancelled_into_timeline(): void
    {
        $clock = Carbon::parse('2026-06-03T03:00:00Z');
        $run = $this->makeRun([
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['trace_id' => 'trace-cancel'],
        ]);

        $this->emitAt($clock, AgentRunEventStreamService::RUN_STARTED, $run);
        $this->emitAt($clock, AgentRunEventStreamService::ROUTING_DECIDED, $run, null, ['decision' => 'rag']);

        $clock->addSecond();
        Carbon::setTestNow($clock->copy());

        $result = app(AgentRunRuntimeControlService::class)->cancel($run, ['reason' => 'user']);
        Carbon::setTestNow();

        // cancel() returns the run in CANCELLED state with a cancellation step.
        $this->assertSame(AIAgentRun::STATUS_CANCELLED, $result['run']['status']);
        $stepTypes = array_column($result['run']['steps'] ?? [], 'type');
        $this->assertContains('agent_cancellation', $stepTypes);

        // fallbackEvents includes RUN_CANCELLED LAST with trace from run metadata.
        $events = $this->stream()->fallbackEvents($run);
        $names = array_column($events, 'name');
        $this->assertSame(AgentRunEventStreamService::RUN_CANCELLED, $names[array_key_last($names)]);

        $cancelled = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === AgentRunEventStreamService::RUN_CANCELLED
        ));
        $this->assertCount(1, $cancelled, 'RUN_CANCELLED must not be doubled despite run+step double-write.');
        $this->assertSame('trace-cancel', $cancelled[0]['trace_id']);
    }
}
