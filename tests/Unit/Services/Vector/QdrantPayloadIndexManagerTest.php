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
