<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\Tenant\MultiTenantVectorService;
use LaravelAIEngine\Services\Vector\ChunkingService;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorAccessControl;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\UnitTestCase;

class VectorSearchServiceContractTest extends UnitTestCase
{
    public function test_indexes_multi_chunk_document_using_search_document_chunks_and_metadata(): void
    {
        config()->set('ai-engine.vector.multi_chunk_enabled', true);
        config()->set('ai-engine.vector.max_content_size', 5500);

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->once())
            ->method('upsert')
            ->with(
                'vec_contract_records',
                $this->callback(function (array $points): bool {
                    $this->assertCount(2, $points);
                    $this->assertSame('alpha', $points[0]['metadata']['chunk_text']);
                    $this->assertSame('beta', $points[1]['metadata']['chunk_text']);
                    $this->assertSame('contract-app', $points[0]['metadata']['app_slug']);
                    $this->assertSame(77, $points[0]['metadata']['entity_ref']['model_id']);
                    $this->assertSame('Contract Record', $points[0]['metadata']['object']['title']);

                    return true;
                })
            )
            ->willReturn(true);

        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->expects($this->exactly(2))
            ->method('embed')
            ->with($this->callback(static fn (string $text): bool => in_array($text, ['alpha', 'beta'], true)))
            ->willReturn([0.1, 0.2, 0.3]);

        $service = new VectorSearchService(
            $driverManager,
            $embeddingService,
            $this->createMock(VectorAccessControl::class),
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class)
        );

        $model = new class extends Model {
            protected $table = 'contract_records';
            public $id = 77;

            public function toSearchDocument(): array
            {
                return [
                    'content' => 'alpha beta',
                    'chunks' => [
                        ['content' => 'alpha', 'index' => 0],
                        ['content' => 'beta', 'index' => 1],
                    ],
                    'object' => ['id' => 77, 'title' => 'Contract Record'],
                    'source_node' => 'contracts',
                    'app_slug' => 'contract-app',
                ];
            }
        };

        $this->assertTrue($service->index($model));
    }

    public function test_deletes_only_chunk_ids_belonging_to_the_same_model_from_the_index(): void
    {
        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->exactly(2))
            ->method('scroll')
            ->willReturnOnConsecutiveCalls(
                [
                    'points' => [
                        ['id' => '88_chunk_0', 'metadata' => ['model_id' => 88]],
                        ['id' => 'other_chunk_0', 'metadata' => ['model_id' => 99]],
                    ],
                    'next_offset' => 'page-2',
                ],
                [
                    'points' => [
                        ['id' => '88_chunk_1', 'metadata' => ['model_id' => 88]],
                    ],
                    'next_offset' => null,
                ]
            );
        $driver->expects($this->once())
            ->method('delete')
            ->with('vec_delete_records', ['88', '88_chunk_0', '88_chunk_1'])
            ->willReturn(true);

        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        $service = new VectorSearchService(
            $driverManager,
            $this->createMock(EmbeddingService::class),
            $this->createMock(VectorAccessControl::class),
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class)
        );

        $model = new class extends Model {
            protected $table = 'delete_records';
            public $id = 88;
        };

        $this->assertTrue($service->deleteFromIndex($model));
    }

    public function test_stores_tenant_and_workspace_scope_metadata_on_indexed_vectors(): void
    {
        config()->set('ai-engine.vector.multi_chunk_enabled', false);

        $expectedScopeKey = sha1(json_encode([
            'tenant_id' => 'tenant-1',
            'workspace_id' => 'workspace-9',
        ], JSON_THROW_ON_ERROR));

        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->once())
            ->method('upsert')
            ->with(
                'vec_scoped_records',
                $this->callback(function (array $points) use ($expectedScopeKey): bool {
                    $metadata = $points[0]['metadata'];
                    $this->assertSame('tenant-1', $metadata['tenant_id']);
                    $this->assertSame('workspace-9', $metadata['workspace_id']);
                    $this->assertSame($expectedScopeKey, $metadata['scope_key']);

                    return true;
                })
            )
            ->willReturn(true);

        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        $embeddingService = $this->createMock(EmbeddingService::class);
        $embeddingService->expects($this->once())
            ->method('embed')
            ->willReturn([0.1, 0.2, 0.3]);

        $service = new VectorSearchService(
            $driverManager,
            $embeddingService,
            $this->createMock(VectorAccessControl::class),
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class),
            new MultiTenantVectorService()
        );

        $model = new class extends Model {
            protected $table = 'scoped_records';
            public $id = 99;

            public function toSearchDocument(): array
            {
                return [
                    'content' => 'scoped vector content',
                    'metadata' => [
                        'tenant_id' => 'tenant-1',
                        'workspace_id' => 'workspace-9',
                    ],
                ];
            }
        };

        $this->assertTrue($service->index($model));
    }
}
