<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolAuditEvent;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunApprovalService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\ProviderTools\HostedArtifactService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentRunApprovalLifecycleTest extends TestCase
{
    public function test_agent_step_approval_reuses_provider_tool_approval_and_audit_lifecycle(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'approval-session',
            'user_id' => 'user-1',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'type' => 'tool',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'computer_use',
        ]);

        $approval = app(AgentRunApprovalService::class)->requestStepApproval($step, [
            'action' => 'computer_use',
            'risk_level' => 'high',
            'reason' => 'Requires desktop access.',
        ], 'user-1');

        $step = $step->refresh();
        $providerRun = $approval->run;

        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $step->status);
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->refresh()->status);
        $this->assertSame($run->id, $providerRun->agent_run_id);
        $this->assertSame($step->id, $providerRun->agent_run_step_id);
        $this->assertSame($step->id, $approval->agent_run_step_id);
        $this->assertSame(['computer_use'], $providerRun->tool_names);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'provider_tool_approval.requested',
        ]);

        $resumedStep = app(AgentRunApprovalService::class)->approveStep($approval->approval_key, 'admin-1');

        $this->assertSame($step->id, $resumedStep->id);
        $this->assertSame(AIAgentRun::STATUS_PENDING, $resumedStep->status);
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $run->refresh()->status);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_step_approval.approved',
        ]);
    }

    public function test_provider_tool_lifecycle_propagates_agent_run_step_to_approval_audit_artifact_and_continuation(): void
    {
        config()->set('ai-engine.provider_tools.artifacts.persist_remote_files', false);
        config()->set('ai-engine.engines.openai.api_key', 'test-openai-key');

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'provider-tool-session',
            'user_id' => 'user-2',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'type' => 'provider_tool',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'code_interpreter',
        ]);

        $request = new AIRequest(
            prompt: 'Analyze this CSV',
            engine: 'openai',
            model: 'gpt-4o',
            metadata: [
                'request_id' => 'req-agent-step',
                'user_id' => 'user-2',
                'agent_run_id' => $run->id,
                'agent_run_step_id' => $step->id,
            ]
        );

        $result = app(ProviderToolRunService::class)->prepare('openai', $request, [
            ['type' => 'code_interpreter'],
        ], ['input' => 'Analyze this CSV']);

        $providerRun = $result->run;
        $approval = $result->pendingApprovals[0];
        $this->assertSame('approval.required', app(AgentRunEventStreamService::class)->fallbackEvents($run)[0]['name']);

        $this->assertSame($run->id, $providerRun->agent_run_id);
        $this->assertSame($step->id, $providerRun->agent_run_step_id);
        $this->assertSame($step->id, $approval->agent_run_step_id);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'provider_tool_run.awaiting_approval',
        ]);

        $artifact = app(HostedArtifactService::class)->record($providerRun, [
            'artifact_type' => 'provider_file',
            'provider_file_id' => 'file_agent_step',
            'metadata' => ['source' => 'code_interpreter'],
        ]);
        $this->assertSame($step->id, $artifact->agent_run_step_id);

        Http::fake([
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_agent_step',
                'output_file_id' => 'file_continued',
            ], 200),
        ]);

        app(\LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService::class)
            ->approve($approval->approval_key, 'admin-2');
        $this->assertTrue(collect(app(AgentRunEventStreamService::class)->fallbackEvents($run))
            ->contains(fn (array $event): bool => ($event['name'] ?? null) === 'approval.resolved'));

        $continued = app(ProviderToolContinuationService::class)->continueRun($providerRun->id, [
            'source' => 'test',
        ]);

        $this->assertSame('completed', $continued->status);
        $this->assertSame($step->id, $continued->agent_run_step_id);
        $this->assertDatabaseHas('ai_provider_tool_artifacts', [
            'agent_run_step_id' => $step->id,
            'tool_run_id' => $providerRun->id,
            'provider_file_id' => 'file_continued',
        ]);
        $this->assertTrue(AIProviderToolAuditEvent::query()
            ->where('agent_run_step_id', $step->id)
            ->whereIn('event', ['provider_tool_approval.approved', 'provider_tool_run.completed'])
            ->count() >= 2);
    }

    public function test_rejected_agent_step_approval_fails_the_step_and_run(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'rejected-approval-session',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'type' => 'sub_agent',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'delegate',
        ]);

        $approval = app(AgentRunApprovalService::class)->requestStepApproval($step, [
            'action' => 'delegate',
            'risk_level' => 'medium',
        ]);

        $failedStep = app(AgentRunApprovalService::class)->rejectStep($approval->approval_key, 'admin-3', 'Not allowed.');

        $this->assertSame(AIAgentRun::STATUS_FAILED, $failedStep->status);
        $this->assertSame('Not allowed.', $failedStep->error);
        $this->assertSame(AIAgentRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_step_approval.rejected',
        ]);
    }

    public function test_dispatcher_audits_tool_and_sub_agent_execution_when_agent_step_is_available(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'dispatcher-audit-session',
            'user_id' => 'user-3',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'type' => 'tool',
            'status' => AIAgentRun::STATUS_RUNNING,
            'action' => 'echo',
        ]);

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldReceive('executeUseTool')
            ->once()
            ->andReturn(AgentResponse::success('Tool done'));

        $goalAgent = Mockery::mock(GoalAgentService::class);
        $goalAgent->shouldReceive('execute')
            ->once()
            ->andReturn(AgentResponse::success('Sub-agent done'));

        $dispatcher = new AgentExecutionDispatcher(
            $execution,
            $goalAgent,
            app(ProviderToolAuditService::class)
        );
        $context = new UnifiedActionContext('dispatcher-audit-session', 'user-3');
        $options = [
            'agent_run_id' => $run->id,
            'agent_run_step_id' => $step->id,
        ];

        $dispatcher->dispatch(new RoutingDecision(
            action: RoutingDecisionAction::USE_TOOL,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: 'Use a tool.',
            payload: ['tool_name' => 'echo', 'params' => ['value' => 'ok']]
        ), 'run echo', $context, $options);

        $dispatcher->dispatch(new RoutingDecision(
            action: RoutingDecisionAction::RUN_SUB_AGENT,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: 'Delegate.',
            payload: ['target' => 'Summarize', 'sub_agents' => ['general']]
        ), 'delegate', $context, $options);

        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_tool.started',
            'tool_name' => 'echo',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_tool.completed',
            'tool_name' => 'echo',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_sub_agent.started',
            'tool_name' => 'run_sub_agent',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'agent_run_step_id' => $step->id,
            'event' => 'agent_sub_agent.completed',
            'tool_name' => 'run_sub_agent',
        ]);
        $events = collect(app(AgentRunEventStreamService::class)->fallbackEvents($run))
            ->pluck('name')
            ->all();
        $this->assertContains('tool.started', $events);
        $this->assertContains('tool.completed', $events);
        $this->assertContains('sub_agent.started', $events);
        $this->assertContains('sub_agent.completed', $events);
    }

    public function test_provider_tool_audit_redacts_sensitive_payload_and_metadata(): void
    {
        app(ProviderToolAuditService::class)->record(
            'agent.secret_test',
            payload: ['password' => 'plain', 'nested' => ['token' => 'secret']],
            metadata: ['api_key' => 'secret-key', 'trace_id' => 'trace-secret']
        );

        $event = AIProviderToolAuditEvent::query()
            ->where('event', 'agent.secret_test')
            ->firstOrFail();

        $this->assertSame('[redacted]', $event->payload['password']);
        $this->assertSame('[redacted]', $event->payload['nested']['token']);
        $this->assertSame('[redacted]', $event->metadata['api_key']);
        $this->assertSame('trace-secret', $event->trace_id);
    }

    public function test_provider_tool_approval_expiry_blocks_resolution_and_writes_audit(): void
    {
        config()->set('ai-engine.provider_tools.approvals.expires_after_minutes', 5);
        Carbon::setTestNow('2026-05-13 10:00:00');

        $providerRun = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['computer_use'],
            'metadata' => [],
        ]);

        $approval = app(\LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService::class)
            ->requestApproval($providerRun, ['type' => 'computer_use']);

        $this->assertSame('2026-05-13 10:05:00', $approval->expires_at->toDateTimeString());

        Carbon::setTestNow('2026-05-13 10:06:00');
        $this->expectException(\RuntimeException::class);

        try {
            app(\LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService::class)
                ->approve($approval->approval_key, 'admin-expired');
        } finally {
            $this->assertDatabaseHas('ai_provider_tool_approvals', [
                'approval_key' => $approval->approval_key,
                'status' => 'expired',
            ]);
            $this->assertDatabaseHas('ai_provider_tool_audit_events', [
                'approval_id' => $approval->id,
                'event' => 'provider_tool_approval.expired',
            ]);
            Carbon::setTestNow();
        }
    }

    public function test_provider_tool_approval_resume_payload_can_be_edited_before_resume(): void
    {
        $providerRun = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['code_interpreter'],
            'metadata' => [],
        ]);

        $service = app(\LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService::class);
        $approval = $service->requestApproval($providerRun, ['type' => 'code_interpreter']);

        $updated = $service->updateResumePayload($approval->approval_key, [
            'input' => 'continue with sanitized payload',
        ], 'admin-payload');

        $this->assertSame('continue with sanitized payload', $updated->metadata['resume_payload']['input']);
        $this->assertSame('admin-payload', $updated->metadata['resume_payload_updated_by']);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'approval_id' => $approval->id,
            'event' => 'provider_tool_approval.resume_payload_updated',
        ]);
    }
}
