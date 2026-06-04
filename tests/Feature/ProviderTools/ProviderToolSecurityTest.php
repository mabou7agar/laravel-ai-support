<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService;
use LaravelAIEngine\Services\ProviderTools\ProviderToolRunService;
use LaravelAIEngine\Tests\TestCase;

class ProviderToolSecurityTest extends TestCase
{
    private function computerUseTool(): array
    {
        return ['type' => 'computer_use', 'display_width' => 1024, 'display_height' => 768];
    }

    private function request(): AIRequest
    {
        return new AIRequest(
            prompt: 'Use a browser',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            metadata: ['user_id' => '42'],
        );
    }

    public function test_denied_provider_tool_is_blocked_even_when_approvals_are_disabled(): void
    {
        // The org execution-policy deny-list must gate provider tools INDEPENDENTLY of the
        // approval toggle — so turning approvals off cannot let a denied tool run.
        config()->set('ai-engine.provider_tools.approvals.enabled', false);
        config()->set('ai-agent.execution_policy.tool_deny', ['computer_use']);

        $tool = $this->computerUseTool();

        $this->expectException(AIEngineException::class);
        $this->expectExceptionMessageMatches('/blocked by execution policy/');

        app(ProviderToolRunService::class)->prepare('openai', $this->request(), [$tool], ['tools' => [$tool]]);
    }

    public function test_rejected_provider_tool_cannot_be_continued(): void
    {
        $tool = $this->computerUseTool();

        // Prepare a run that requires approval.
        $result = app(ProviderToolRunService::class)->prepare('openai', $this->request(), [$tool], ['tools' => [$tool]]);
        $this->assertFalse($result->canExecute());
        $this->assertCount(1, $result->pendingApprovals);

        // Reject the approval.
        app(ProviderToolApprovalService::class)->reject(
            $result->pendingApprovals[0]->approval_key,
            'admin-1',
            'Not permitted.'
        );

        // Continuation must be refused — a rejected tool call must never execute.
        $this->expectException(AIEngineException::class);
        $this->expectExceptionMessageMatches('/not approved for \[computer_use\]/');

        app(ProviderToolContinuationService::class)->continueRun($result->run->id, []);
    }
}
