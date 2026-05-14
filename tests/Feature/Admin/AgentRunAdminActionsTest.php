<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Admin;

use Illuminate\Support\Facades\Queue;
use LaravelAIEngine\Jobs\ContinueAgentRunJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Tests\TestCase;

class AgentRunAdminActionsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('app.cipher', 'AES-256-CBC');
        $app['config']->set('ai-engine.admin_ui.enabled', true);
        $app['config']->set('ai-engine.admin_ui.route_prefix', 'ai-engine/admin');
        $app['config']->set('ai-engine.admin_ui.middleware', ['web']);
        $app['config']->set('ai-engine.admin_ui.access.allow_localhost', false);
        $app['config']->set('ai-engine.admin_ui.access.allowed_ips', ['203.0.113.10']);
    }

    public function test_admin_can_inspect_agent_run_timeline_trace_citations_and_artifacts(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'admin-agent-run',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'runtime' => 'laravel',
            'routing_trace' => [
                ['action' => 'search_rag', 'source' => 'classifier'],
            ],
            'final_response' => [
                'message' => 'Done.',
                'metadata' => [
                    'citations' => [['title' => 'Invoice 10']],
                ],
            ],
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'sequence' => 1,
            'type' => 'rag',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'action' => 'search_rag',
        ]);
        AIProviderToolArtifact::create([
            'uuid' => 'artifact-admin-1',
            'agent_run_step_id' => $step->id,
            'provider' => 'openai',
            'source' => 'file_search',
            'artifact_type' => 'citation',
            'name' => 'Invoice citation',
            'metadata' => [],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/ai-engine/admin/agent-runs')
            ->assertOk()
            ->assertSee('admin-agent-run')
            ->assertSee('Agent Runs');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get("/ai-engine/admin/agent-runs/{$run->uuid}")
            ->assertOk()
            ->assertSee('Step Timeline')
            ->assertSee('Budget And Retention')
            ->assertSee('Routing Trace')
            ->assertSee('Invoice 10')
            ->assertSee('Invoice citation')
            ->assertSee('Resume')
            ->assertSee('Cancel');
    }

    public function test_admin_can_queue_resume_and_retry_agent_runs(): void
    {
        Queue::fake();

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'admin-agent-resume',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
            'runtime' => 'laravel',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post("/ai-engine/admin/agent-runs/{$run->uuid}/resume", [
                'message' => 'continue',
                'queue' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post("/ai-engine/admin/agent-runs/{$run->uuid}/retry", [
                'reason' => 'retry from admin test',
                'queue' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        Queue::assertPushed(ContinueAgentRunJob::class, 2);
    }

    public function test_admin_can_cancel_agent_run(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'admin-agent-cancel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'runtime' => 'laravel',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post("/ai-engine/admin/agent-runs/{$run->uuid}/cancel", [
                'reason' => 'cancel from admin test',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(AIAgentRun::STATUS_CANCELLED, $run->refresh()->status);
    }
}
