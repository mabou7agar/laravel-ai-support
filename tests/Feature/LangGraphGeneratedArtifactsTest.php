<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class LangGraphGeneratedArtifactsTest extends TestCase
{
    public function test_langgraph_generated_files_are_recorded_as_hosted_artifacts(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');
        config()->set('ai-engine.provider_tools.artifacts.persist_remote_files', false);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'langgraph-artifacts',
            'user_id' => '55',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'langgraph',
            'type' => 'langgraph',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        Http::fake([
            'https://langgraph.test/runs' => Http::response([
                'id' => 'lg-artifact-run',
                'thread_id' => 'thread-artifacts',
                'status' => 'completed',
                'output' => [
                    'message' => 'Generated report.',
                    'generated_files' => [
                        [
                            'download_url' => 'https://files.example.com/report.pdf',
                            'name' => 'report.pdf',
                            'mime_type' => 'application/pdf',
                        ],
                    ],
                ],
            ]),
        ]);

        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldNotReceive('process');

        $response = (new LangGraphAgentRuntime(new LaravelAgentRuntime($processor), app(ContextManager::class)))
            ->process('build report', 'langgraph-artifacts', '55', [
                'agent_run_id' => $run->id,
                'agent_run_uuid' => $run->uuid,
                'agent_run_step_id' => $step->id,
                'trace_id' => 'trace-langgraph-artifact',
            ]);

        $this->assertTrue($response->success);
        $this->assertSame(1, $response->metadata['hosted_artifact_count']);
        $this->assertDatabaseHas('ai_provider_tool_runs', [
            'provider' => 'langgraph',
            'provider_request_id' => 'lg-artifact-run',
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $step->id,
        ]);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'owner_type' => 'agent_run',
            'owner_id' => (string) $run->id,
            'source' => 'langgraph',
            'download_url' => 'https://files.example.com/report.pdf',
        ]);
    }

    public function test_langgraph_approval_interrupt_creates_laravel_approval_for_same_run_step(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'langgraph-approval',
            'user_id' => '56',
            'status' => AIAgentRun::STATUS_RUNNING,
            'runtime' => 'langgraph',
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'langgraph',
            'type' => 'langgraph',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        Http::fake([
            'https://langgraph.test/runs' => Http::response([
                'id' => 'lg-approval-run',
                'thread_id' => 'thread-approval',
                'status' => 'interrupted',
                'interrupt' => [
                    'id' => 'interrupt-1',
                    'type' => 'approval',
                    'message' => 'Approve computer use?',
                    'tool_name' => 'computer_use',
                    'risk_level' => 'high',
                    'requires_approval' => true,
                    'resume_payload' => ['approved' => true],
                ],
            ]),
        ]);

        $runtime = new LangGraphAgentRuntime(
            new LaravelAgentRuntime(Mockery::mock(LaravelAgentProcessor::class)),
            app(ContextManager::class)
        );

        $response = $runtime->process('use browser', 'langgraph-approval', '56', [
            'agent_run_id' => $run->id,
            'agent_run_uuid' => $run->uuid,
            'agent_run_step_id' => $step->id,
            'trace_id' => 'trace-langgraph-approval',
        ]);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Approve computer use?', $response->message);
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $response->metadata['agent_run_status']);
        $this->assertNotEmpty($response->data['approval_key']);
        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'approval_key' => $response->data['approval_key'],
            'agent_run_step_id' => $step->id,
            'tool_name' => 'computer_use',
            'risk_level' => 'high',
            'status' => 'pending',
        ]);
    }
}
