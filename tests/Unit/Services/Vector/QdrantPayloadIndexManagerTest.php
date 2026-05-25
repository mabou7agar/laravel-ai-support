<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
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

    private function client(array $responses = []): Client
    {
        return new Client([
            'base_uri' => 'http://qdrant.test',
            'handler' => HandlerStack::create(new MockHandler($responses)),
        ]);
    }
}
