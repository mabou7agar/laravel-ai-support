<?php

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use Illuminate\Database\Eloquent\Model;
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
    public function test_index_prefers_search_document_chunks_and_metadata(): void
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
            ->with($this->callback(fn (string $text): bool => in_array($text, ['alpha', 'beta'], true)))
            ->willReturn([0.1, 0.2, 0.3]);

        $accessControl = $this->createMock(VectorAccessControl::class);

        $service = new VectorSearchService(
            $driverManager,
            $embeddingService,
            $accessControl,
            $this->app->make(SearchDocumentBuilder::class),
            $this->app->make(ChunkingService::class)
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

    public function test_delete_from_index_removes_chunk_ids_for_same_model(): void
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
            $this->app->make(SearchDocumentBuilder::class),
            $this->app->make(ChunkingService::class)
        );

        $model = new class extends Model {
            protected $table = 'delete_records';
            public $id = 88;
        };

        $this->assertTrue($service->deleteFromIndex($model));
    }
}
