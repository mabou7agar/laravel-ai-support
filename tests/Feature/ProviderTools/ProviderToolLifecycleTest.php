<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolAuditEvent;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Tests\TestCase;

class ProviderToolLifecycleTest extends TestCase
{
    public function test_provider_tool_run_requires_and_records_approval_before_execution(): void
    {
        $request = new AIRequest(
            prompt: 'Use a browser to inspect the page',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            metadata: ['user_id' => '42']
        );

        $tool = [
            'type' => 'computer_use',
            'display_width' => 1024,
            'display_height' => 768,
        ];

        $result = app(ProviderToolRunService::class)->prepare('openai', $request, [$tool], [
            'model' => EntityEnum::GPT_4O,
            'tools' => [$tool],
        ]);

        $this->assertFalse($result->canExecute());
        $this->assertSame('awaiting_approval', $result->run->status);
        $this->assertCount(1, $result->pendingApprovals);
        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'tool_run_id' => $result->run->id,
            'tool_name' => 'computer_use',
            'risk_level' => 'high',
            'status' => 'pending',
            'requested_by' => '42',
        ]);
        $this->assertDatabaseHas('ai_provider_tool_audit_events', [
            'tool_run_id' => $result->run->id,
            'event' => 'provider_tool_run.awaiting_approval',
        ]);

        $approval = app(ProviderToolApprovalService::class)->approve(
            $result->pendingApprovals[0]->approval_key,
            'admin-1',
            'Allowed browser automation for this request.'
        );

        $continued = new AIRequest(
            prompt: 'Use a browser to inspect the page',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            metadata: [
                'provider_tool_run_id' => $result->run->uuid,
                'provider_tool_approval_keys' => [$approval->approval_key],
                'user_id' => '42',
            ]
        );

        $continuedResult = app(ProviderToolRunService::class)->prepare('openai', $continued, [$tool]);

        $this->assertTrue($continuedResult->canExecute());
        $this->assertSame('running', $continuedResult->run->status);
        $this->assertCount(1, $continuedResult->approvedApprovals);
        $this->assertSame(1, AIProviderToolApproval::query()->where('status', 'approved')->count());
        $this->assertSame(1, AIProviderToolAuditEvent::query()->where('event', 'provider_tool_approval.approved')->count());
    }

    public function test_provider_tool_continuations_keep_code_mcp_computer_hosted_tools_on_same_agent_step(): void
    {
        config()->set('ai-engine.provider_tools.artifacts.persist_remote_files', false);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_code', 'output_file_id' => 'file-code'], 200)
                ->push(['id' => 'resp_mcp', 'container_id' => 'container-mcp'], 200)
                ->push(['id' => 'resp_computer', 'output' => [['url' => 'https://files.test/screen.png']]], 200)
                ->push(['id' => 'resp_hosted', 'citations' => [['title' => 'Hosted file', 'url' => 'https://files.test/source']]], 200),
        ]);

        $agentRun = app(AgentRunRepository::class)->create([
            'session_id' => 'provider-continuation-scope',
            'user_id' => '42',
            'tenant_id' => 'tenant-provider',
            'workspace_id' => 'workspace-provider',
            'status' => AIAgentRun::STATUS_WAITING_APPROVAL,
            'metadata' => ['trace_id' => 'trace-provider-continuation'],
        ]);
        $step = app(AgentRunStepRepository::class)->create($agentRun, [
            'step_key' => 'provider-tool',
            'type' => 'provider_tool',
            'status' => AIAgentRun::STATUS_WAITING_APPROVAL,
        ]);

        foreach (['code_interpreter', 'mcp_server', 'computer_use', 'hosted_tool'] as $toolName) {
            $run = app(ProviderToolRunRepository::class)->create([
                'uuid' => (string) Str::uuid(),
                'agent_run_id' => $agentRun->id,
                'agent_run_step_id' => $step->id,
                'provider' => 'openai',
                'engine' => 'openai',
                'ai_model' => 'gpt-4o',
                'status' => 'awaiting_approval',
                'tool_names' => [$toolName],
                'request_payload' => [
                    'model' => 'gpt-4o',
                    'input' => [['role' => 'user', 'content' => "Continue {$toolName}"]],
                    'tools' => [['type' => $toolName]],
                ],
                'metadata' => [
                    'trace_id' => 'trace-provider-continuation',
                    'tenant_id' => 'tenant-provider',
                    'workspace_id' => 'workspace-provider',
                ],
            ]);

            $approval = app(ProviderToolApprovalService::class)->requestApproval($run, ['type' => $toolName]);
            app(ProviderToolApprovalService::class)->approve($approval->approval_key, 'admin-1');

            $continued = app(ProviderToolContinuationService::class)->continueRun($run->uuid, [
                'approved_by' => 'admin-1',
            ]);

            $this->assertSame('completed', $continued->status);
            $this->assertSame($agentRun->id, $continued->agent_run_id);
            $this->assertSame($step->id, $continued->agent_run_step_id);
            $this->assertSame($step->id, $continued->artifacts()->latest('id')->first()?->agent_run_step_id);
            $this->assertSame('trace-provider-continuation', $continued->metadata['trace_id']);
        }

        $this->assertSame(4, \LaravelAIEngine\Models\AIProviderToolArtifact::query()
            ->where('agent_run_step_id', $step->id)
            ->count());
    }

    public function test_duplicate_provider_continuation_after_completion_is_idempotent(): void
    {
        config()->set('ai-engine.provider_tools.artifacts.persist_remote_files', false);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push(['id' => 'resp_once', 'output_file_id' => 'file-once'], 200),
        ]);

        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['code_interpreter'],
            'request_payload' => [
                'model' => 'gpt-4o',
                'input' => [['role' => 'user', 'content' => 'Create a file']],
                'tools' => [['type' => 'code_interpreter']],
            ],
            'metadata' => [],
        ]);

        $approval = app(ProviderToolApprovalService::class)->requestApproval($run, ['type' => 'code_interpreter']);
        app(ProviderToolApprovalService::class)->approve($approval->approval_key, 'admin-1');

        $first = app(ProviderToolContinuationService::class)->continueRun($run->uuid);
        $second = app(ProviderToolContinuationService::class)->continueRun($run->uuid);

        $this->assertSame('completed', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $second->artifacts()->count());
        Http::assertSentCount(1);
    }
}
