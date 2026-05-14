<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Actions\ActionReplyGeneratorService;
use LaravelAIEngine\Services\Agent\Tools\GenerateActionReplyTool;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class GenerateActionReplyToolTest extends TestCase
{
    public function test_tool_generates_reply_from_action_result(): void
    {
        $replies = Mockery::mock(ActionReplyGeneratorService::class);
        $replies->shouldReceive('generate')
            ->once()
            ->with(['message' => 'Needs email.'])
            ->andReturn([
                'text' => 'Please send the customer email.',
                'metadata' => [
                    'action_reply_provider' => 'ai',
                    'action_reply_generated' => true,
                ],
            ]);

        $result = (new GenerateActionReplyTool($replies))->execute(
            ['action_result' => ['message' => 'Needs email.']],
            new UnifiedActionContext('reply-tool-test')
        );

        $this->assertTrue($result->success);
        $this->assertSame('generate_action_reply', $result->metadata['agent_strategy']);
        $this->assertSame('Please send the customer email.', $result->data['text']);
        $this->assertSame('ai', $result->metadata['provider']);
        $this->assertTrue($result->metadata['generated']);
    }

    public function test_tool_rejects_non_object_action_result(): void
    {
        $result = (new GenerateActionReplyTool(Mockery::mock(ActionReplyGeneratorService::class)))->execute(
            ['action_result' => 'not-an-object'],
            new UnifiedActionContext('reply-tool-test')
        );

        $this->assertFalse($result->success);
        $this->assertSame('action_result must be an object.', $result->error);
        $this->assertSame('generate_action_reply', $result->metadata['agent_strategy']);
    }
}
