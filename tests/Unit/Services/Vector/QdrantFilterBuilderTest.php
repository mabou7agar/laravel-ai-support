<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Vector;

use LaravelAIEngine\Services\Vector\Drivers\QdrantFilterBuilder;
use LaravelAIEngine\Tests\UnitTestCase;

class QdrantFilterBuilderTest extends UnitTestCase
{
    public function test_builds_typed_exact_and_any_filters(): void
    {
        $filter = (new QdrantFilterBuilder())->build([
            'model_class' => 'App\\Models\\Invoice',
            'user_id' => '42',
            'status' => ['paid', 'draft'],
            'is_archived' => 'false',
        ], 'invoices', [
            'user_id' => 'integer',
            'status' => 'keyword',
            'is_archived' => 'bool',
        ]);

        $this->assertSame([
            'must' => [
                ['key' => 'user_id', 'match' => ['value' => 42]],
                ['key' => 'status', 'match' => ['any' => ['paid', 'draft']]],
                ['key' => 'is_archived', 'match' => ['value' => false]],
            ],
        ], $filter);
    }

    public function test_builds_range_filters_with_numeric_conversion(): void
    {
        $filter = (new QdrantFilterBuilder())->build([
            'total' => ['gte' => '10.5', 'lt' => '99'],
        ], 'invoices', [
            'total' => 'float',
        ]);

        $this->assertSame([
            'must' => [
                ['key' => 'total', 'range' => ['gte' => 10.5, 'lt' => 99.0]],
            ],
        ], $filter);
    }

    public function test_builds_date_filters_and_requests_timestamp_index(): void
    {
        $ensured = [];
        $filter = (new QdrantFilterBuilder())->build(
            ['created_at' => ['gte' => '2026-01-01', 'lte' => '2026-01-31']],
            'invoices',
            [],
            function (string $collection, array $fields) use (&$ensured): void {
                $ensured[] = [$collection, $fields];
            }
        );

        $this->assertSame([
            ['invoices', ['created_at_ts']],
        ], $ensured);
        $this->assertSame('created_at_ts', $filter['must'][0]['key']);
        $this->assertSame(strtotime('2026-01-01'), $filter['must'][0]['range']['gte']);
        $this->assertSame(strtotime('2026-01-31 23:59:59'), $filter['must'][0]['range']['lte']);
    }
}
