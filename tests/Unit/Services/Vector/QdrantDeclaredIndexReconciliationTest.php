<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Services\Vector\Drivers\QdrantDriver;
use LaravelAIEngine\Tests\TestCase;

final class QdrantDeclaredIndexReconciliationTest extends TestCase
{
    public function test_model_backed_search_reconciles_declared_indexes_before_building_filters(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], '{"result":{}}'),
            new Response(200, [], '{"result":[]}'),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'http_errors' => true]);

        $driver = new class(['host' => 'https://qdrant.test']) extends QdrantDriver {
            /** @var list<array{collection: string, model: string|null}> */
            public array $reconciliations = [];

            public function ensureAllPayloadIndexes(string $collection, ?string $modelClass = null): void
            {
                $this->reconciliations[] = [
                    'collection' => $collection,
                    'model' => $modelClass,
                ];
            }

            public function ensureFilterIndexes(string $collection, array $filterFields): void
            {
            }

            protected function getCachedIndexTypes(string $collection): array
            {
                return ['projection_version' => 'integer'];
            }
        };

        $property = new \ReflectionProperty(QdrantDriver::class, 'client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        self::assertSame([], $driver->search('courses', [0.1, 0.2], 8, 0.0, [
            'model_class' => SearchDeclaredPayloadIndexModel::class,
            'projection_version' => 5,
        ]));
        self::assertSame([[
            'collection' => 'courses',
            'model' => SearchDeclaredPayloadIndexModel::class,
        ]], $driver->reconciliations);

        $payload = json_decode(
            (string) $history[1]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame(5, $payload['filter']['must'][0]['match']['value']);
        self::assertStringNotContainsString(
            'model_class',
            json_encode($payload['filter'], JSON_THROW_ON_ERROR),
        );
    }
}

final class SearchDeclaredPayloadIndexModel
{
    public function getQdrantIndexes(): array
    {
        return ['projection_version' => 'integer'];
    }
}
