<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\ProviderTools;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Repositories\ProviderToolRunRepository;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApiOperationsService;
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

    public function test_api_redacts_secrets_in_responses_but_keeps_the_stored_value(): void
    {
        $run = app(ProviderToolRunRepository::class)->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'provider' => 'openai',
            'status' => 'awaiting_approval',
            'tool_names' => ['mcp_server'],
            'user_id' => '42',
            'request_payload' => [
                'tools' => [[
                    'type' => 'mcp',
                    'server_url' => 'https://mcp.example',
                    'authorization' => 'Bearer sk-super-secret-123',
                ]],
            ],
        ]);

        $response = app(ProviderToolApiOperationsService::class)->showRun($run->uuid);
        $body = json_encode($response->getData(true));

        // The secret never appears in the API response...
        $this->assertStringNotContainsString('sk-super-secret-123', $body);
        // ...but the stored payload is intact, so continuation replay still has it.
        $this->assertSame('Bearer sk-super-secret-123', $run->fresh()->request_payload['tools'][0]['authorization']);
    }
}
