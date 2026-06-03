<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\SendMessageDTO;
use LaravelAIEngine\Services\Agent\AgentChatExecutionModeResolver;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentChatExecutionModeResolverTest extends UnitTestCase
{
    public function test_skill_match_failure_logs_and_falls_back_to_sync(): void
    {
        config()->set('ai-agent.chat.async_enabled', true);
        config()->set('ai-agent.chat.async_default', false);

        $matcher = Mockery::mock(AgentSkillMatcher::class);
        $matcher->shouldReceive('match')
            ->once()
            ->andThrow(new \RuntimeException('matcher exploded'));

        $logChannel = Mockery::mock();
        $logChannel->shouldReceive('debug')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'Agent skill match check failed')
                    && ($context['error'] ?? null) === 'matcher exploded';
            });
        Log::shouldReceive('channel')->with('ai-engine')->andReturn($logChannel);

        $resolver = new AgentChatExecutionModeResolver($matcher);

        $dto = new SendMessageDTO(
            message: 'do something with a skill',
            sessionId: 'sess-1',
            actions: true,
            executionMode: 'auto'
        );

        $decision = $resolver->resolve($dto, useRag: false, ragCollections: []);

        $this->assertSame('sync', $decision->mode);
        $this->assertSame('simple_chat', $decision->reason);
    }
}
