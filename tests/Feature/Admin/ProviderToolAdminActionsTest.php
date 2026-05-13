<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Admin;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LaravelAIEngine\Jobs\ContinueProviderToolRunJob;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Tests\TestCase;

class ProviderToolAdminActionsTest extends TestCase
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

    public function test_admin_can_approve_pending_provider_tool_and_queue_continuation(): void
    {
        Queue::fake();

        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['code_interpreter'],
            'request_payload' => ['model' => 'gpt-4o'],
            'metadata' => [],
        ]);
        $approval = app(ProviderToolApprovalService::class)->requestApproval($run, ['type' => 'code_interpreter']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/provider-tools/approvals/approve', [
                'approval_key' => $approval->approval_key,
                'continue' => '1',
                'async' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'approval_key' => $approval->approval_key,
            'status' => 'approved',
        ]);
        Queue::assertPushed(ContinueProviderToolRunJob::class);
    }

    public function test_admin_can_reject_pending_provider_tool(): void
    {
        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) Str::uuid(),
            'provider' => 'openai',
            'engine' => 'openai',
            'ai_model' => 'gpt-4o',
            'status' => 'awaiting_approval',
            'tool_names' => ['computer_use'],
            'request_payload' => ['model' => 'gpt-4o'],
            'metadata' => [],
        ]);
        $approval = app(ProviderToolApprovalService::class)->requestApproval($run, ['type' => 'computer_use']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/ai-engine/admin/provider-tools/approvals/reject', [
                'approval_key' => $approval->approval_key,
                'reason' => 'Not allowed in test.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('ai_provider_tool_approvals', [
            'approval_key' => $approval->approval_key,
            'status' => 'rejected',
            'reason' => 'Not allowed in test.',
        ]);
    }
}
