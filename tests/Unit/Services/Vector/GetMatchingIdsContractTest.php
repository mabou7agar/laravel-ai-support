<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Services\Vector\ChunkingService;
use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\Drivers\PineconeDriver;
use LaravelAIEngine\Services\Vector\Drivers\QdrantDriver;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vector\VectorAccessControl;
use LaravelAIEngine\Services\Vector\VectorDriverManager;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class GetMatchingIdsContractTest extends UnitTestCase
{
    public function test_interface_declares_get_matching_ids(): void
    {
        $this->assertTrue(
            method_exists(VectorDriverInterface::class, 'getMatchingIds'),
            'VectorDriverInterface must declare getMatchingIds()'
        );

        $method = new ReflectionMethod(VectorDriverInterface::class, 'getMatchingIds');
        $params = $method->getParameters();

        $this->assertSame('collection', $params[0]->getName());
        $this->assertSame('filters', $params[1]->getName());
        $this->assertTrue($params[1]->isOptional());
        $this->assertSame('array', (string) $method->getReturnType());
    }

    public function test_concrete_drivers_implement_get_matching_ids(): void
    {
        foreach ([QdrantDriver::class, PineconeDriver::class] as $driver) {
            $this->assertTrue(
                (new ReflectionClass($driver))->getMethod('getMatchingIds')->getDeclaringClass()->getName() === $driver,
                "{$driver} must implement getMatchingIds()"
            );
        }
    }

    public function test_vector_search_service_get_matching_ids_uses_driver_interface(): void
    {
        $driver = $this->createMock(VectorDriverInterface::class);
        $driver->expects($this->once())
            ->method('getMatchingIds')
            ->with('vec_matching_records', ['user_id' => 7])
            ->willReturn([10, 20, 30]);

        $driverManager = $this->createMock(VectorDriverManager::class);
        $driverManager->method('driver')->willReturn($driver);

        $service = $this->makeService($driverManager);

        $model = new class extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'matching_records';
        };

        $ids = $service->getMatchingIds(get_class($model), ['user_id' => 7]);

        $this->assertSame([10, 20, 30], $ids);
    }

    public function test_pinecone_driver_get_matching_ids_dedupes_model_ids_from_metadata(): void
    {
        $mock = new MockHandler([
            // getIndexHost() -> getCollectionInfo() controller lookup
            new Response(200, [], json_encode([
                'database' => ['status' => ['host' => 'index-host.pinecone.io']],
            ], JSON_THROW_ON_ERROR)),
            // describe_index_stats
            new Response(200, [], json_encode([
                'dimension' => 3,
                'totalVectorCount' => 5,
            ], JSON_THROW_ON_ERROR)),
            // query
            new Response(200, [], json_encode([
                'matches' => [
                    ['id' => '10_chunk_0', 'metadata' => ['model_id' => 10]],
                    ['id' => '10_chunk_1', 'metadata' => ['model_id' => 10]],
                    ['id' => '20_chunk_0', 'metadata' => ['model_id' => 20]],
                    // No metadata.model_id -> falls back to point id
                    ['id' => '30'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new Client(['handler' => HandlerStack::create($mock)]);

        $driver = new PineconeDriver(['api_key' => 'test', 'environment' => 'test-env']);
        $clientProp = new ReflectionProperty($driver, 'client');
        $clientProp->setAccessible(true);
        $clientProp->setValue($driver, $client);

        $ids = $driver->getMatchingIds('vec_pinecone', ['user_id' => 7]);

        $this->assertSame([10, 20, '30'], $ids);
    }

    private function makeService(VectorDriverManager $driverManager): VectorSearchService
    {
        return new VectorSearchService(
            $driverManager,
            $this->createMock(EmbeddingService::class),
            $this->createMock(VectorAccessControl::class),
            app(SearchDocumentBuilder::class),
            app(ChunkingService::class)
        );
    }
}
