<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use Illuminate\Support\Facades\DB;
use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Tests\TestCase;

class ConversationMemoryRepositoryTest extends TestCase
{
    public function test_repository_stores_and_filters_memory_by_generic_scope(): void
    {
        $repo = app(ConversationMemoryRepository::class);

        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'profile',
            'key' => 'preferred_language',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'scope_type' => 'workspace',
            'scope_id' => 'workspace-a',
            'confidence' => 0.95,
        ]));

        $query = new ConversationMemoryQuery(
            message: 'reply in my preferred language',
            scopeType: 'workspace',
            scopeId: 'workspace-a',
            limit: 5,
        );

        $results = $repo->search($query);

        $this->assertCount(1, $results);
        $this->assertSame('preferred_language', $results[0]->item->key);
        $this->assertSame('Arabic', $results[0]->item->value);

        $otherWorkspace = new ConversationMemoryQuery(
            message: 'reply in my preferred language',
            scopeType: 'workspace',
            scopeId: 'workspace-b',
            limit: 5,
        );

        $this->assertSame([], $repo->search($otherWorkspace));
    }

    public function test_fresh_memory_outranks_stale_memory_at_equal_lexical_and_confidence(): void
    {
        config()->set('ai-agent.conversation_memory.ttl_days', 30);

        $repo = app(ConversationMemoryRepository::class);

        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'preferences',
            'key' => 'reply_language_stale',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'scope_type' => 'workspace',
            'scope_id' => 'workspace-recency',
            'confidence' => 0.9,
            'last_seen_at' => now()->subDays(60),
        ]));

        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'preferences',
            'key' => 'reply_language_fresh',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'scope_type' => 'workspace',
            'scope_id' => 'workspace-recency',
            'confidence' => 0.9,
            'last_seen_at' => now(),
        ]));

        $results = $repo->search(new ConversationMemoryQuery(
            message: 'what language should you use for replies?',
            scopeType: 'workspace',
            scopeId: 'workspace-recency',
            limit: 5,
        ));

        $this->assertCount(2, $results);
        $this->assertSame('reply_language_fresh', $results[0]->item->key);
        $this->assertGreaterThan($results[1]->score, $results[0]->score);
    }

    public function test_repository_upserts_with_nullable_scopes_without_duplicate_rows(): void
    {
        $repo = app(ConversationMemoryRepository::class);

        $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'profile',
            'key' => 'preferred_language',
            'value' => 'Arabic',
            'summary' => 'User prefers Arabic replies.',
            'scope_type' => 'user',
            'scope_id' => 'uuid-user-1',
        ]));

        $updated = $repo->upsert(ConversationMemoryItem::fromArray([
            'namespace' => 'profile',
            'key' => 'preferred_language',
            'value' => 'English',
            'summary' => 'User prefers English replies.',
            'scope_type' => 'user',
            'scope_id' => 'uuid-user-1',
        ]));

        $this->assertSame('English', $updated->value);
        $this->assertSame(1, DB::table('ai_conversation_memories')->count());
        $this->assertSame(64, strlen((string) DB::table('ai_conversation_memories')->value('scope_hash')));
        $this->assertSame(64, strlen((string) DB::table('ai_conversation_memories')->value('key_hash')));
    }
}
