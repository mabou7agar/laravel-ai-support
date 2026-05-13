<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIProviderToolApproval;
use LaravelAIEngine\Models\AIProviderToolAuditEvent;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
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
}
