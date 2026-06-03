<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemorySemanticIndex;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ConversationMemorySemanticIndexTest extends UnitTestCase
{
    public function test_it_indexes_and_searches_memory_using_configured_vector_driver_and_scope_fields(): void
    {
        config()->set('ai-agent.conversation_memory.semantic.enabled', true);
        config()->set('ai-agent.conversation_memory.semantic.driver', 'memory');
        config()->set('ai-agent.conversation_memory.semantic.collection', 'chat_memories');
        config()->set('ai-agent.conversation_memory.semantic.payload_scope_fields', [
            'scope_type',
            'scope_id',
            'session_id',
            'namespace',
        ]);

        $driver = new InMemoryConversationMemoryVectorDriver();
        $manager = Mockery::mock(VectorDriverManager::class);
        $manager->shouldReceive('driver')->with('memory')->twice()->andReturn($driver);

        $embeddings = Mockery::mock(EmbeddingService::class);
        $embeddings->shouldReceive('embed')
            ->once()
            ->with(Mockery::on(fn (string $text): bool => str_contains($text, 'Arabic replies')), '7')
            ->andReturn([0.1, 0.2, 0.3]);
        $embeddings->shouldReceive('embed')
            ->once()
            ->with('which language should you use?', '7')
            ->andReturn([0.1, 0.2, 0.3]);

        $index = new ConversationMemorySemanticIndex(app(ConversationMemoryPolicy::class), $manager, $embeddings);

        $stored = $index->index(ConversationMemoryItem::fromArray([
            'memory_id' => 'mem_reply_language',
            'namespace' => 'preferences',
            'key' => 'reply_language',
            'summary' => 'User prefers Arabic replies.',
            'scope_type' => 'workspace',
            'scope_id' => 'workspace-a',
            'user_id' => '7',
            'session_id' => 'session-a',
        ]));

        $scores = $index->search(new ConversationMemoryQuery(
            message: 'which language should you use?',
            scopeType: 'workspace',
            scopeId: 'workspace-a',
            userId: '7',
            sessionId: 'session-a',
            namespace: 'preferences',
            limit: 3,
        ));

        $this->assertTrue($stored);
        $this->assertSame(['mem_reply_language' => 0.92], $scores);
        $this->assertSame('chat_memories', $driver->lastSearchCollection);
        $this->assertSame([
            'scope_type' => 'workspace',
            'scope_id' => 'workspace-a',
            'session_id' => 'session-a',
            'namespace' => 'preferences',
        ], $driver->lastSearchFilters);
    }
}

class InMemoryConversationMemoryVectorDriver implements VectorDriverInterface
{
    public ?string $lastSearchCollection = null;

    /** @var array<string, string> */
    public array $lastSearchFilters = [];

    /** @var array<int, array<string, mixed>> */
    private array $vectors = [];

    public function createCollection(string $name, int $dimensions, array $config = []): bool
    {
        return true;
    }

    public function deleteCollection(string $name): bool
    {
        return true;
    }

    public function collectionExists(string $name): bool
    {
        return true;
    }

    public function upsert(string $collection, array $vectors): bool
    {
        $this->vectors = $vectors;

        return true;
    }

    public function search(string $collection, array $vector, int $limit = 10, float $threshold = 0.0, array $filters = []): array
    {
        $this->lastSearchCollection = $collection;
        $this->lastSearchFilters = $filters;

        return [[
            'id' => $this->vectors[0]['id'] ?? 'mem_reply_language',
            'score' => 0.92,
            'metadata' => $this->vectors[0]['metadata'] ?? ['memory_id' => 'mem_reply_language'],
        ]];
    }

    public function delete(string $collection, array $ids): bool
    {
        return true;
    }

    public function getCollectionInfo(string $collection): array
    {
        return [];
    }

    public function get(string $collection, string $id): ?array
    {
        return null;
    }

    public function updateMetadata(string $collection, string $id, array $metadata): bool
    {
        return true;
    }

    public function count(string $collection, array $filters = []): int
    {
        return count($this->vectors);
    }

    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array
    {
        return [];
    }

    public function getMatchingIds(string $collection, array $filters = []): array
    {
        return [];
    }
}
