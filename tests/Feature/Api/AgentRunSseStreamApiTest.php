<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunSseStreamApiTest extends TestCase
{
    public function test_agent_run_stream_endpoint_outputs_sse_events(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);
        config()->set('ai-agent.event_stream.sse.allow_anonymous_runs', true);
        config()->set('ai-agent.event_stream.sse.max_seconds', 1);
        config()->set('ai-agent.event_stream.sse.poll_milliseconds', 100);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-run',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::RUN_COMPLETED,
            $run,
            null,
            ['message' => 'Done']
        );

        $response = $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream?timeout=1&poll=100");

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();
        $this->assertStringContainsString("event: run.completed\n", $content);
        $this->assertStringContainsString('"message":"Done"', $content);
    }

    public function test_agent_run_stream_emits_terminal_event_persisted_in_transition_window(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);
        config()->set('ai-agent.event_stream.sse.allow_anonymous_runs', true);
        config()->set('ai-agent.event_stream.sse.max_seconds', 1);
        config()->set('ai-agent.event_stream.sse.poll_milliseconds', 100);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-transition-window',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        // Emit a non-terminal event that is visible immediately, and prepare a
        // terminal event that only becomes visible on a re-fetch.
        app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::ROUTING_DECIDED,
            $run,
            null,
            ['decision' => 'search_rag']
        );

        // Repository that simulates the transition window: the in-loop fetch sees
        // the run without the terminal event, while the re-fetch sees it with the
        // run.completed event freshly persisted.
        $repository = new class(app(AgentRunEventStreamService::class), $run->id) extends AgentRunRepository {
            private int $loopFinds = 0;

            public function __construct(
                private readonly AgentRunEventStreamService $events,
                private readonly int $watchedId
            ) {}

            public function find(int|string|null $id): ?AIAgentRun
            {
                $found = parent::find($id);

                // Only the in-loop fetches use the integer DB id; findOrFail()
                // resolves by UUID. Count integer-id lookups so the first is the
                // in-loop emit and the second is the post-terminal re-fetch.
                if ($found instanceof AIAgentRun && $found->id === $this->watchedId && $id === $this->watchedId) {
                    $this->loopFinds++;
                    // Persist the terminal event only on the re-fetch so it is
                    // ONLY visible to the final fetch added by the fix; without
                    // that re-fetch the run.completed event would be missed.
                    if ($this->loopFinds === 2) {
                        $this->events->emit(
                            AgentRunEventStreamService::RUN_COMPLETED,
                            $found,
                            null,
                            ['message' => 'Done']
                        );
                        $found = parent::find($id);
                    }
                }

                return $found;
            }
        };

        $this->app->instance(AgentRunRepository::class, $repository);

        $response = $this->get("/api/v1/ai/agent-runs/{$run->uuid}/stream?timeout=1&poll=100");

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString("event: routing.decided\n", $content);
        $this->assertStringContainsString("event: run.completed\n", $content);
        $this->assertStringContainsString('"message":"Done"', $content);
    }

    public function test_agent_run_stream_endpoint_can_be_disabled(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', false);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-disabled',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}/stream")
            ->assertNotFound();
    }

    public function test_agent_run_stream_endpoint_requires_matching_authenticated_owner_for_owned_runs(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);

        $owner = $this->createTestUser();
        $other = $this->createTestUser();
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-owned',
            'user_id' => (string) $owner->getAuthIdentifier(),
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        $this->actingAs($other)
            ->getJson("/api/v1/ai/agent-runs/{$run->uuid}/stream")
            ->assertForbidden();

        $this->actingAs($owner)
            ->get("/api/v1/ai/agent-runs/{$run->uuid}/stream?timeout=1&poll=100")
            ->assertOk();
    }

    public function test_agent_run_stream_endpoint_denies_unowned_runs_by_default(): void
    {
        config()->set('ai-agent.event_stream.sse.enabled', true);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'sse-unowned',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}/stream")
            ->assertForbidden();
    }
}
