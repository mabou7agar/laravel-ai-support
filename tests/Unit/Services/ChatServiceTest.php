<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ChatServiceTest extends UnitTestCase
{
    public function test_process_message_handles_runtime_response_without_context(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::failure('Runtime is blocked by policy.'));

        $service = new ChatService(Mockery::mock(ConversationService::class), $runtime);

        $response = $service->processMessage(
            message: 'hello',
            sessionId: 'chat-null-context',
            useMemory: false,
            userId: 9
        );

        $this->assertFalse($response->success);
        $this->assertSame('Runtime is blocked by policy.', $response->content);
        $this->assertFalse($response->metadata['runtime_active']);
        $this->assertTrue($response->metadata['runtime_completed']);
    }

    public function test_process_message_preserves_runtime_actions_and_input_metadata(): void
    {
        $context = new UnifiedActionContext('chat-inputs', 9);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::needsUserInput(
                message: 'Need approval.',
                actions: [['label' => 'Approve', 'value' => 'approve']],
                context: $context,
                nextStep: 'approve',
                requiredInputs: [['name' => 'approved', 'type' => 'boolean']]
            ));

        $service = new ChatService(Mockery::mock(ConversationService::class), $runtime);

        $response = $service->processMessage(
            message: 'continue',
            sessionId: 'chat-inputs',
            useMemory: false,
            userId: 9
        );

        $this->assertTrue($response->success);
        $this->assertSame([['label' => 'Approve', 'value' => 'approve']], $response->actions);
        $this->assertTrue($response->metadata['needs_user_input']);
        $this->assertSame('approve', $response->metadata['next_step']);
        $this->assertSame([['name' => 'approved', 'type' => 'boolean']], $response->metadata['required_inputs']);
    }
}
