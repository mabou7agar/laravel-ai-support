<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Tests\TestCase;

class AgentRunPersistenceTest extends TestCase
{
    public function test_agent_run_repositories_store_run_steps_and_provider_tool_links(): void
    {
        $runs = app(AgentRunRepository::class);
        $steps = app(AgentRunStepRepository::class);

        $run = $runs->create([
            'session_id' => 'session-agent-run',
            'user_id' => '42',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-b',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'schema_version' => 1,
            'input' => ['message' => 'Find invoice status'],
            'final_response' => ['message' => 'Pending approval'],
            'current_step' => 'route-1',
            'routing_trace' => ['selected' => ['action' => 'search_rag']],
            'failure_reason' => null,
            'metadata' => ['locale' => 'en'],
        ]);

        $routingStep = $steps->create($run, [
            'step_key' => 'route-1',
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'search_rag',
            'source' => 'classifier',
            'input' => ['message' => 'Find invoice status'],
            'routing_decision' => ['action' => 'search_rag'],
            'routing_trace' => ['decisions' => [['action' => 'search_rag']]],
        ]);

        $toolStep = $steps->create($run, [
            'step_key' => 'tool-1',
            'type' => 'provider_tool',
            'status' => AIAgentRun::STATUS_WAITING_APPROVAL,
            'action' => 'code_interpreter',
            'source' => 'openai',
        ]);

        $providerRun = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $toolStep->id,
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['code_interpreter'],
            'request_payload' => ['input' => 'Analyze'],
            'metadata' => [],
        ]);

        $approval = AIProviderToolApproval::create([
            'approval_key' => (string) Str::uuid(),
            'agent_run_step_id' => $toolStep->id,
            'tool_run_id' => $providerRun->id,
            'provider' => 'openai',
            'tool_name' => 'code_interpreter',
            'risk_level' => 'medium',
            'status' => 'pending',
        ]);

        $artifact = AIProviderToolArtifact::create([
            'uuid' => (string) Str::uuid(),
            'agent_run_step_id' => $toolStep->id,
            'tool_run_id' => $providerRun->id,
            'provider' => 'openai',
            'artifact_type' => 'file',
            'name' => 'analysis.csv',
            'metadata' => ['provider_file_id' => 'file_123'],
        ]);

        $steps->update($toolStep, [
            'provider_tool_run_id' => $providerRun->id,
            'approvals' => [$approval->approval_key],
            'artifacts' => [$artifact->uuid],
        ]);

        $this->assertSame('session-agent-run', $run->session_id);
        $this->assertSame('42', $run->user_id);
        $this->assertSame('tenant-a', $run->tenant_id);
        $this->assertSame('workspace-b', $run->workspace_id);
        $this->assertSame('laravel', $run->runtime);
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $run->status);
        $this->assertSame(1, $run->schema_version);
        $this->assertSame(['message' => 'Find invoice status'], $run->input);
        $this->assertSame(['message' => 'Pending approval'], $run->final_response);
        $this->assertSame('route-1', $run->current_step);
        $this->assertSame(['selected' => ['action' => 'search_rag']], $run->routing_trace);
        $this->assertNull($run->failure_reason);

        $this->assertSame(1, $routingStep->sequence);
        $this->assertSame(2, $toolStep->sequence);
        $this->assertSame($run->id, $runs->findActiveBySession('session-agent-run', '42')?->id);
        $this->assertSame($providerRun->id, $toolStep->refresh()->provider_tool_run_id);
        $this->assertSame($toolStep->id, $providerRun->agent_run_step_id);
        $this->assertSame($toolStep->id, $approval->agent_run_step_id);
        $this->assertSame($toolStep->id, $artifact->agent_run_step_id);
        $this->assertCount(2, $run->steps);
        $this->assertCount(1, $toolStep->linkedProviderToolRuns);
        $this->assertCount(1, $toolStep->approvals);
        $this->assertCount(1, $toolStep->artifacts);
    }

    public function test_agent_run_statuses_are_enforced_by_repositories(): void
    {
        $runs = app(AgentRunRepository::class);
        $steps = app(AgentRunStepRepository::class);

        $run = $runs->create([
            'session_id' => 'status-session',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $step = $steps->create($run, ['status' => AIAgentRun::STATUS_PENDING]);

        foreach (AIAgentRun::STATUSES as $status) {
            $run = $runs->transition($run, $status);
            $step = $steps->transition($step, $status);

            $this->assertSame($status, $run->status);
            $this->assertSame($status, $step->status);
        }

        $this->expectException(\InvalidArgumentException::class);
        $runs->transition($run, 'unknown');
    }
}
