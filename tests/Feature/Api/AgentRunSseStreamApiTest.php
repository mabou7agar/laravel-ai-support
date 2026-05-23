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
