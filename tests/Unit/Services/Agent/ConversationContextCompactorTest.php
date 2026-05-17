<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

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
                'content' => "message {$i} about customer invoice flow",
            ];
        }

        app(ConversationContextCompactor::class)->compact($context);

        $this->assertCount(4, $context->conversationHistory);
        $this->assertSame('message 5 about customer invoice flow', $context->conversationHistory[0]['content']);
        $this->assertStringContainsString('message 1 about customer invoice flow', $context->metadata['conversation_summary']);
        $this->assertStringContainsString('message 4 about customer invoice flow', $context->metadata['conversation_summary']);
        $this->assertSame(4, $context->metadata['conversation_compacted_messages']);
        $this->assertLessThanOrEqual(2000 + 4 * 500, $context->metadata['conversation_context_metrics']['prompt_size_chars']);
        $this->assertSame(4, $context->metadata['conversation_context_metrics']['compacted_messages']);
    }

    public function test_it_preserves_existing_summary_across_multiple_compactions(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 4);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);

        $compactor = app(ConversationContextCompactor::class);
        $context = new UnifiedActionContext('compact-session', 7, metadata: [
            'conversation_summary' => '- user: earlier customer was Sample Customer',
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
        $this->assertStringContainsString('earlier customer was Sample Customer', $context->metadata['conversation_summary']);
        $this->assertStringContainsString('new detail 1', $context->metadata['conversation_summary']);
        $this->assertSame(5, $context->metadata['conversation_compacted_messages']);
    }

    public function test_metrics_track_large_history_under_configured_context_budget(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 12);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 4);
        config()->set('ai-agent.context_compaction.max_message_chars', 500);
        config()->set('ai-agent.context_compaction.max_total_chars', 5000);
        config()->set('ai-agent.context_compaction.max_summary_chars', 1200);

        $context = new UnifiedActionContext('large-context-session', 7);

        for ($i = 1; $i <= 80; $i++) {
            $context->conversationHistory[] = [
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => str_repeat("turn {$i} invoice customer product warehouse ", 8),
            ];
        }

        $compactor = app(ConversationContextCompactor::class);
        $compactor->compact($context);
        $metrics = $compactor->metrics($context);

        $this->assertCount(4, $context->conversationHistory);
        $this->assertLessThanOrEqual(5000, $metrics['prompt_size_chars']);
        $this->assertLessThanOrEqual(1200, $metrics['summary_size_chars']);
        $this->assertGreaterThan(0, $metrics['compacted_messages']);
        $this->assertSame($metrics['prompt_size_chars'], $context->metadata['conversation_context_metrics']['prompt_size_chars']);
        $this->assertGreaterThan($metrics['recent_memory_size_chars'], $context->metadata['conversation_context_metrics']['pre_compaction_history_size_chars']);
    }

    public function test_it_extracts_durable_memories_only_when_context_is_compacted(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 3);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.extract_on_compaction', true);
        config()->set('ai-agent.conversation_memory.scopes.tenant_key', 'tenant_id');
        config()->set('ai-agent.conversation_memory.scopes.workspace_key', 'workspace_id');

        $policy = app(ConversationMemoryPolicy::class);
        $extractor = Mockery::mock(ConversationMemoryExtractor::class);
        $repository = Mockery::mock(ConversationMemoryRepository::class);

        $context = new UnifiedActionContext(
            sessionId: 'compact-memory-session',
            userId: 77,
            conversationHistory: [
                ['role' => 'user', 'content' => 'أفضل الردود العربية في هذا المشروع'],
                ['role' => 'assistant', 'content' => 'تم.'],
                ['role' => 'user', 'content' => 'continue'],
                ['role' => 'assistant', 'content' => 'ok'],
            ],
            metadata: [
                'tenant_id' => 'tenant-a',
                'workspace_id' => 'workspace-a',
            ]
        );

        $item = ConversationMemoryItem::fromArray([
            'namespace' => 'preferences',
            'key' => 'reply_language',
            'summary' => 'User prefers Arabic replies for this project.',
            'user_id' => '77',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'session_id' => 'compact-memory-session',
        ]);

        $extractor->shouldReceive('extract')
            ->once()
            ->withArgs(function (array $messages, array $scope): bool {
                return count($messages) === 2
                    && $scope['user_id'] === '77'
                    && $scope['tenant_id'] === 'tenant-a'
                    && $scope['workspace_id'] === 'workspace-a'
                    && $scope['session_id'] === 'compact-memory-session';
            })
            ->andReturn([$item]);

        $repository->shouldReceive('upsert')->once()->with($item)->andReturn($item);

        $compactor = new ConversationContextCompactor($policy, $extractor, $repository);
        $compactor->compact($context);

        $this->assertSame(1, $context->metadata['conversation_memory_extracted']);
        $this->assertArrayNotHasKey('conversation_memory_extraction_error', $context->metadata);
    }
}
