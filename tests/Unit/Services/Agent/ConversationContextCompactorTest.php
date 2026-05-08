<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Tests\UnitTestCase;

class ConversationContextCompactorTest extends UnitTestCase
{
    public function test_it_compacts_old_messages_into_summary_and_keeps_recent_context(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 6);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 4);
        config()->set('ai-agent.context_compaction.max_message_chars', 500);
        config()->set('ai-agent.context_compaction.max_summary_chars', 2000);

        $context = new UnifiedActionContext('compact-session', 7);

        for ($i = 1; $i <= 8; $i++) {
            $context->conversationHistory[] = [
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => "message {$i} about customer invoice workflow",
            ];
        }

        app(ConversationContextCompactor::class)->compact($context);

        $this->assertCount(4, $context->conversationHistory);
        $this->assertSame('message 5 about customer invoice workflow', $context->conversationHistory[0]['content']);
        $this->assertStringContainsString('message 1 about customer invoice workflow', $context->metadata['conversation_summary']);
        $this->assertStringContainsString('message 4 about customer invoice workflow', $context->metadata['conversation_summary']);
        $this->assertSame(4, $context->metadata['conversation_compacted_messages']);
    }

    public function test_it_preserves_existing_summary_across_multiple_compactions(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 4);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);

        $compactor = app(ConversationContextCompactor::class);
        $context = new UnifiedActionContext('compact-session', 7, metadata: [
            'conversation_summary' => '- user: earlier customer was Mohamed',
            'conversation_compacted_messages' => 2,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $context->conversationHistory[] = [
                'role' => 'user',
                'content' => "new detail {$i}",
            ];
        }

        $compactor->compact($context);

        $this->assertCount(2, $context->conversationHistory);
        $this->assertStringContainsString('earlier customer was Mohamed', $context->metadata['conversation_summary']);
        $this->assertStringContainsString('new detail 1', $context->metadata['conversation_summary']);
        $this->assertSame(5, $context->metadata['conversation_compacted_messages']);
    }
}
