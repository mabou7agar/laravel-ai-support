<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Knowledge;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use LaravelAIEngine\Enums\KnowledgeScope;
use LaravelAIEngine\Services\Knowledge\InMemoryScopedKnowledgeIndex;
use LaravelAIEngine\Services\Knowledge\PersistentVectorScopedKnowledgeIndex;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Tests\UnitTestCase;

final class PersistentVectorScopedKnowledgeIndexTest extends UnitTestCase
{
    public function test_it_persists_only_changed_documents_with_scope_safe_ids(): void
    {
        config()->set('ai-agent.assistant.knowledge_index.vector.collection', 'assistant_knowledge');

        $upserted = [];
        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects(self::exactly(2))
            ->method('collectionExists')
            ->with('assistant_knowledge')
            ->willReturnOnConsecutiveCalls(false, true);
        $driver->expects(self::once())
            ->method('createCollection')
            ->with('assistant_knowledge', 2)
            ->willReturn(true);
        $driver->expects(self::once())
            ->method('upsert')
            ->willReturnCallback(function (string $collection, array $points) use (&$upserted): bool {
                self::assertSame('assistant_knowledge', $collection);
                $upserted = $points;

                return true;
            });

        $embeddings = $this->createMock(EmbeddingService::class);
        $embeddings->expects(self::once())
            ->method('embedBatch')
            ->willReturn([[1.0, 0.0], [0.0, 1.0]]);

        $index = $this->index($driver, $embeddings);
        $documents = [
            $this->document('course:1', 'Tenant one course', 'tenant-1'),
            $this->document('course:1', 'Tenant two course', 'tenant-2'),
        ];

        self::assertSame(2, $index->sync($documents));
        self::assertSame(0, $index->sync($documents));
        self::assertCount(2, $upserted);
        self::assertNotSame($upserted[0]['id'], $upserted[1]['id']);
        self::assertSame($upserted[0]['id'], $upserted[0]['metadata']['knowledge_storage_id']);
        self::assertSame('course:1', $upserted[0]['metadata']['knowledge_document_id']);
    }

    public function test_it_fails_closed_when_a_driver_returns_an_unscoped_result(): void
    {
        config()->set('ai-agent.assistant.knowledge_index.vector.sync_on_search', false);
        config()->set('ai-agent.assistant.knowledge_index.vector.collection', 'assistant_knowledge');
        $allowed = $this->document('course:1', 'Allowed testing course', 'tenant-1');
        $allowedStorageId = hash('sha256', 'tenant_private|tenant-1|||course:1');
        $otherStorageId = hash('sha256', 'tenant_private|tenant-2|||course:1');

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->method('collectionExists')->willReturn(true);
        $driver->expects(self::once())
            ->method('search')
            ->with(
                'assistant_knowledge',
                [1.0, 0.0],
                8,
                0.25,
                ['knowledge_storage_id' => [$allowedStorageId]],
            )
            ->willReturn([
                [
                    'score' => 0.99,
                    'metadata' => ['knowledge_storage_id' => $otherStorageId],
                ],
                [
                    'score' => 0.91,
                    'metadata' => ['knowledge_storage_id' => $allowedStorageId],
                ],
            ]);

        $embeddings = $this->createMock(EmbeddingService::class);
        $embeddings->expects(self::once())->method('embed')->willReturn([1.0, 0.0]);

        $matches = $this->index($driver, $embeddings)->search([$allowed], 'testing course');

        self::assertCount(1, $matches);
        self::assertSame('tenant-1', $matches[0]->document->tenantId);
        self::assertSame(0.91, $matches[0]->score);
    }

    private function index(
        VectorDriverInterface $driver,
        EmbeddingService $embeddings,
    ): PersistentVectorScopedKnowledgeIndex {
        $manager = $this->createMock(VectorDriverManager::class);
        $manager->method('driver')->willReturn($driver);
        $manager->method('getDefaultDriver')->willReturn('qdrant');

        return new PersistentVectorScopedKnowledgeIndex(
            $manager,
            $embeddings,
            new Repository(new ArrayStore()),
            new InMemoryScopedKnowledgeIndex(),
        );
    }

    private function document(string $id, string $text, string $tenant): ScopedKnowledgeDocument
    {
        return new ScopedKnowledgeDocument(
            id: $id,
            text: $text,
            scope: KnowledgeScope::TenantPrivate,
            tenantId: $tenant,
            metadata: ['title' => $text],
        );
    }
}
