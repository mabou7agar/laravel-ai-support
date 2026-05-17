<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemorySemanticIndex;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ConversationMemoryRetrieverTest extends TestCase
{
    public function test_retriever_filters_by_score_and_limits_results(): void
    {
        config()->set('ai-agent.conversation_memory.min_score', 0.45);
        config()->set('ai-agent.conversation_memory.max_memories_per_turn', 1);

        $repo = app(ConversationMemoryRepository::class);
        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'preferences',
            'key' => 'reply_language',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'user_id' => '7',
            'confidence' => 0.95,
        ]));
        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'conversation',
            'key' => 'other',
            'summary' => 'User discussed unrelated deployment logs.',
            'user_id' => '7',
            'confidence' => 0.2,
        ]));

        $results = app(ConversationMemoryRetriever::class)->retrieve(new ConversationMemoryQuery(
            message: 'which language should you use when you reply?',
            userId: '7',
            limit: 5,
        ));

        $this->assertCount(1, $results);
        $this->assertSame('reply_language', $results[0]->item->key);
    }

    public function test_retriever_uses_semantic_index_but_rechecks_sql_scope(): void
    {
        config()->set('ai-agent.conversation_memory.semantic.enabled', true);
        config()->set('ai-agent.conversation_memory.min_score', 0.45);

        $item = ConversationMemoryItem::fromArray([
            'memory_id' => 'mem_reply_language',
            'namespace' => 'preferences',
            'key' => 'reply_language',
            'summary' => 'User prefers Arabic replies.',
            'user_id' => '7',
            'workspace_id' => 'workspace-a',
        ]);

        $query = new ConversationMemoryQuery(
            message: 'which language should you use?',
            userId: '7',
            workspaceId: 'workspace-a',
            limit: 5,
        );

        $semantic = Mockery::mock(ConversationMemorySemanticIndex::class);
        $semantic->shouldReceive('search')->once()->with($query)->andReturn(['mem_reply_language' => 0.91]);

        $repo = Mockery::mock(ConversationMemoryRepository::class);
        $repo->shouldReceive('findScopedByMemoryIds')
            ->once()
            ->with(['mem_reply_language'], $query)
            ->andReturn(['mem_reply_language' => $item]);
        $repo->shouldReceive('search')->once()->with($query)->andReturn([]);

        $results = (new ConversationMemoryRetriever($repo, app(ConversationMemoryPolicy::class), $semantic))->retrieve($query);

        $this->assertCount(1, $results);
        $this->assertSame('reply_language', $results[0]->item->key);
        $this->assertSame('semantic_index', $results[0]->reason);
    }
}
