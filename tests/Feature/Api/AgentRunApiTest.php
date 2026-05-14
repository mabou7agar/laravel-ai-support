<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunApiTest extends TestCase
{
    public function test_agent_run_api_lists_shows_trace_and_capabilities(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'agent-run-api',
            'user_id' => '99',
            'tenant_id' => 'tenant-api',
            'workspace_id' => 'workspace-api',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'routing_trace' => [['stage' => 'explicit_mode']],
            'metadata' => ['trace_id' => 'trace-api'],
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'routing',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'action' => 'search_rag',
            'routing_decision' => ['action' => 'search_rag'],
            'output' => [
                'metadata' => [
                    'citations' => [[
                        'type' => 'vector',
                        'title' => 'Invoice 10',
                        'url' => 'invoice://10',
                    ]],
                ],
            ],
            'metadata' => ['trace_id' => 'trace-api'],
        ]);
        app(AgentRunEventStreamService::class)->emit(
            AgentRunEventStreamService::ROUTING_DECIDED,
            $run,
            $step,
            ['decision' => 'search_rag'],
            ['trace_id' => 'trace-api']
        );

        $this->getJson('/api/v1/ai/agent-runs?tenant_id=tenant-api')
            ->assertOk()
            ->assertJsonPath('data.data.0.uuid', $run->uuid);

        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}")
            ->assertOk()
            ->assertJsonPath('data.run.uuid', $run->uuid)
            ->assertJsonPath('data.events.0.name', 'routing.decided')
            ->assertJsonPath('data.citations.0.url', 'invoice://10');

        $this->getJson("/api/v1/ai/agent-runs/{$run->uuid}/trace")
            ->assertOk()
            ->assertJsonPath('data.trace_id', 'trace-api')
            ->assertJsonPath('data.steps.0.routing_decision.action', 'search_rag')
            ->assertJsonPath('data.citations.0.title', 'Invoice 10')
            ->assertJsonPath('data.events.0.name', 'routing.decided');

        $this->getJson('/api/v1/ai/agent-runs/capabilities')
            ->assertOk()
            ->assertJsonPath('data.available.laravel.tools', true);
    }

    public function test_agent_run_api_resumes_and_cancels_langgraph_runs(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');

        Http::fake([
            'https://langgraph.test/runs/lg-api-run/resume' => Http::response([
                'id' => 'lg-api-run',
                'thread_id' => 'thread-api',
                'status' => 'completed',
                'output' => ['message' => 'Graph resumed.'],
            ]),
            'https://langgraph.test/runs/lg-api-run/cancel' => Http::response([
                'id' => 'lg-api-run',
                'status' => 'cancelled',
            ]),
        ]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'agent-run-api-resume',
            'user_id' => '100',
            'runtime' => 'langgraph',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
            'metadata' => ['langgraph_run_id' => 'lg-api-run'],
            'final_response' => [
                'metadata' => ['langgraph_run_id' => 'lg-api-run'],
                'data' => ['langgraph_run_id' => 'lg-api-run'],
            ],
        ]);

        $this->postJson("/api/v1/ai/agent-runs/{$run->uuid}/resume", [
            'message' => 'approved',
            'payload' => ['approved' => true],
        ])
            ->assertOk()
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.run.status', AIAgentRun::STATUS_COMPLETED)
            ->assertJsonPath('data.run.final_response.message', 'Graph resumed.');

        $cancelRun = app(AgentRunRepository::class)->create([
            'session_id' => 'agent-run-api-cancel',
            'user_id' => '101',
            'runtime' => 'langgraph',
            'status' => AIAgentRun::STATUS_RUNNING,
            'metadata' => ['langgraph_run_id' => 'lg-api-run'],
        ]);

        $this->postJson("/api/v1/ai/agent-runs/{$cancelRun->uuid}/cancel", [
            'reason' => 'User stopped it.',
        ])
            ->assertOk()
            ->assertJsonPath('data.run.status', AIAgentRun::STATUS_CANCELLED)
            ->assertJsonPath('data.remote.status', 'cancelled');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://langgraph.test/runs/lg-api-run/resume'
            && ($request->data()['approved'] ?? null) === true);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://langgraph.test/runs/lg-api-run/cancel');
    }
}
