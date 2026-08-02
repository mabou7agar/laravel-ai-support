<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Services\Vector\Drivers\QdrantPayloadIndexManager;
use LaravelAIEngine\Tests\UnitTestCase;

class QdrantPayloadIndexManagerTest extends UnitTestCase
{
    public function test_guesses_common_payload_field_types(): void
    {
        $manager = new QdrantPayloadIndexManager($this->client());

        $this->assertSame('integer', $manager->guessFieldType('user_id'));
        $this->assertSame('keyword', $manager->guessFieldType('status'));
        $this->assertSame('bool', $manager->guessFieldType('is_active'));
        $this->assertSame('integer', $manager->guessFieldType('created_at_ts'));
        $this->assertSame('keyword', $manager->guessFieldType('title'));
    }

    public function test_reads_existing_index_types_from_payload_schema(): void
    {
        $manager = new QdrantPayloadIndexManager($this->client([
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'user_id' => ['data_type' => 'Integer'],
                        'status' => ['data_type' => 'Keyword'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]));

        $this->assertSame([
            'user_id' => 'integer',
            'status' => 'keyword',
        ], $manager->getExistingIndexesWithTypes('invoices'));
    }

    public function test_auto_fix_recreates_mismatched_index_types(): void
    {
        $manager = new QdrantPayloadIndexManager($this->client([
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'user_id' => ['data_type' => 'keyword'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200, [], json_encode([
                'result' => [
                    'points' => [
                        ['payload' => ['user_id' => 42]],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200),
            new Response(200),
        ]));

        $this->assertSame(['user_id'], $manager->autoFixIndexTypes('invoices'));
    }

    public function test_creates_custom_indexes_from_field_type_map_without_indexing_type_values(): void
    {
        $history = [];
        $handler = new MockHandler([
            new Response(200),
            new Response(200),
            new Response(200),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        config()->set('ai-engine.vector.payload_index_fields', []);

        $manager = new QdrantPayloadIndexManager(new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => $stack,
        ]));
        $manager->createPayloadIndexes('guides', CustomPayloadIndexModel::class);

        $payloads = array_map(
            static fn (array $transaction): array => json_decode(
                (string) $transaction['request']->getBody(),
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
            $history,
        );

        $this->assertSame([
            ['field_name' => 'is_public', 'field_schema' => 'bool'],
            ['field_name' => 'category_key', 'field_schema' => 'keyword'],
            ['field_name' => 'legacy_field', 'field_schema' => 'keyword'],
        ], $payloads);
    }

    public function test_existing_collection_reconciles_model_declared_index_types(): void
    {
        $history = [];
        $handler = new MockHandler([
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'projection_version' => ['data_type' => 'keyword'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
            new Response(200),
            new Response(200),
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'projection_version' => ['data_type' => 'integer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        config()->set('ai-engine.vector.payload_index_fields', []);

        $manager = new QdrantPayloadIndexManager(new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => $stack,
        ]));
        $manager->ensureAllPayloadIndexes(
            'existing-guides',
            TypedProjectionPayloadIndexModel::class,
        );

        $this->assertSame(
            ['GET', 'DELETE', 'PUT', 'GET'],
            array_map(
                static fn (array $transaction): string => $transaction['request']->getMethod(),
                $history,
            ),
        );
        $this->assertSame(
            '/collections/existing-guides/index/projection_version',
            $history[1]['request']->getUri()->getPath(),
        );
        $this->assertSame([
            'field_name' => 'projection_version',
            'field_schema' => 'integer',
        ], json_decode(
            (string) $history[2]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function test_existing_empty_collection_creates_typed_model_index_without_guessing(): void
    {
        $history = [];
        $handler = new MockHandler([
            new Response(200, [], json_encode([
                'result' => ['payload_schema' => []],
            ], JSON_THROW_ON_ERROR)),
            new Response(200),
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'projection_version' => ['data_type' => 'integer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        config()->set('ai-engine.vector.payload_index_fields', []);

        $manager = new QdrantPayloadIndexManager(new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => $stack,
        ]));
        $manager->ensureAllPayloadIndexes(
            'empty-guides',
            TypedProjectionPayloadIndexModel::class,
        );

        $this->assertSame(['GET', 'PUT', 'GET'], array_map(
            static fn (array $transaction): string => $transaction['request']->getMethod(),
            $history,
        ));
        $this->assertSame([
            'field_name' => 'projection_version',
            'field_schema' => 'integer',
        ], json_decode(
            (string) $history[1]['request']->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
    }

    public function test_generic_reconciliation_cache_does_not_suppress_typed_model_repair(): void
    {
        $history = [];
        $schemaWithKeyword = json_encode([
            'result' => [
                'payload_schema' => [
                    'projection_version' => ['data_type' => 'keyword'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $handler = new MockHandler([
            new Response(200, [], $schemaWithKeyword),
            new Response(200, [], $schemaWithKeyword),
            new Response(200),
            new Response(200),
            new Response(200, [], json_encode([
                'result' => [
                    'payload_schema' => [
                        'projection_version' => ['data_type' => 'integer'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));
        config()->set('ai-engine.vector.payload_index_fields', []);

        $manager = new QdrantPayloadIndexManager(new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => $stack,
        ]));

        $manager->ensureAllPayloadIndexes('cached-guides');
        $manager->ensureAllPayloadIndexes(
            'cached-guides',
            TypedProjectionPayloadIndexModel::class,
        );

        $this->assertSame(
            ['GET', 'GET', 'DELETE', 'PUT', 'GET'],
            array_map(
                static fn (array $transaction): string => $transaction['request']->getMethod(),
                $history,
            ),
        );
    }

    private function client(array $responses = []): Client
    {
        return new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);
    }
}

final class CustomPayloadIndexModel
{
    public function getQdrantIndexes(): array
    {
        return [
            'is_public' => 'bool',
            'category_key' => 'keyword',
            'legacy_field',
        ];
    }
}

final class TypedProjectionPayloadIndexModel
{
    public function getQdrantIndexes(): array
    {
        return [
            'projection_version' => 'integer',
        ];
    }
}
