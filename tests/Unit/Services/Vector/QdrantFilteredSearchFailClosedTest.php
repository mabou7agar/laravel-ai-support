<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LaravelAIEngine\Services\Vector\Drivers\QdrantDriver;
use LaravelAIEngine\Tests\TestCase;

final class QdrantFilteredSearchFailClosedTest extends TestCase
{
    public function test_failed_filtered_search_never_retries_without_filters(): void
    {
        $failures = [
            new Response(400, [], '{"status":{"error":"invalid filter"}}'),
            new Response(422, [], '{"status":{"error":"unprocessable filter"}}'),
            new Response(500, [], '{"status":{"error":"unavailable"}}'),
            new ConnectException('provider timeout', new Request('POST', 'https://qdrant.test/search')),
        ];

        foreach ($failures as $failure) {
            $history = [];
            $driver = $this->driverWithResponses([
                new Response(200, [], '{"result":{}}'),
                $failure,
            ], $history);

            $result = $driver->search('tenant_docs', [0.1, 0.2], 5, 0.0, [
                'tenant_id' => 'tenant-secret-a',
                'workspace_id' => 'workspace-secret-a',
            ]);

            self::assertSame([], $result);
            self::assertCount(2, $history, 'Expected one collection probe and exactly one search request.');
            self::assertSame('GET', $history[0]['request']->getMethod());
            self::assertSame('POST', $history[1]['request']->getMethod());

            $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('filter', $payload);
            self::assertStringContainsString('tenant-secret-a', json_encode($payload['filter'], JSON_THROW_ON_ERROR));
            self::assertStringContainsString('workspace-secret-a', json_encode($payload['filter'], JSON_THROW_ON_ERROR));
        }
    }

    public function test_failed_unfiltered_search_remains_a_single_attempt(): void
    {
        $history = [];
        $driver = $this->driverWithResponses([
            new Response(200, [], '{"result":{}}'),
            new Response(500, [], '{"status":{"error":"unavailable"}}'),
        ], $history);

        self::assertSame([], $driver->search('public_docs', [0.1, 0.2]));
        self::assertCount(2, $history, 'Expected one collection probe and one unfiltered search request.');

        $payload = json_decode((string) $history[1]['request']->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('filter', $payload);
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function driverWithResponses(array $responses, array &$history): QdrantDriver
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));
        $client = new Client(['handler' => $stack, 'http_errors' => true]);

        $driver = new class(['host' => 'https://qdrant.test']) extends QdrantDriver {
            public function ensureFilterIndexes(string $collection, array $filterFields): void
            {
                // Index provisioning is orthogonal to the request-count contract.
            }

            protected function getCachedIndexTypes(string $collection): array
            {
                // Index discovery is orthogonal to the request-count contract.
                return [];
            }
        };

        $property = new \ReflectionProperty(QdrantDriver::class, 'client');
        $property->setAccessible(true);
        $property->setValue($driver, $client);

        return $driver;
    }
}
