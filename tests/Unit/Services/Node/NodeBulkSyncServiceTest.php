<?php

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use LaravelAIEngine\Services\Node\NodeBulkSyncService;
use LaravelAIEngine\Tests\UnitTestCase;

class NodeBulkSyncServiceTest extends UnitTestCase
{
    public function test_normalize_definitions_with_diagnostics_returns_invalid_reasons_and_suggestions(): void
    {
        $service = app(NodeBulkSyncService::class);

        $result = $service->normalizeDefinitionsWithDiagnostics([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                ],
                [
                    'name' => 'Broken URL',
                    'slug' => 'broken-url',
                    'url' => 'broken',
                ],
                [
                    'name' => 'Duplicate',
                    'slug' => 'billing-node',
                    'url' => 'https://dup.example.test',
                ],
            ],
        ]);

        $this->assertCount(1, $result['definitions']);
        $this->assertCount(2, $result['invalid']);
        $this->assertSame('Invalid URL format.', $result['invalid'][0]['reason']);
        $this->assertSame('Duplicate slug in payload.', $result['invalid'][1]['reason']);
        $this->assertSame(
            'Use a valid absolute URL starting with http:// or https://.',
            $result['invalid'][0]['suggestion']
        );
    }

    public function test_summarize_plan_counts_invalid_rows(): void
    {
        $service = app(NodeBulkSyncService::class);

        $summary = $service->summarizePlan([
            'create' => [[], []],
            'update' => [[]],
            'unchanged' => [[]],
            'invalid' => [[], [], []],
            'desired_slugs' => ['a', 'b'],
        ], 'test');

        $this->assertSame(2, $summary['create']);
        $this->assertSame(1, $summary['update']);
        $this->assertSame(1, $summary['unchanged']);
        $this->assertSame(3, $summary['invalid']);
        $this->assertSame(2, $summary['desired_slugs']);
    }

    public function test_auto_fix_payload_normalizes_common_fields(): void
    {
        $service = app(NodeBulkSyncService::class);

        $result = $service->autoFixPayload([
            'nodes' => [
                [
                    'name' => ' Billing Node ',
                    'slug' => 'Billing Node',
                    'url' => 'billing.example.test',
                    'type' => 'weird',
                    'status' => 'bad',
                    'capabilities' => 'search, rag',
                    'weight' => 0,
                ],
                [
                    'name' => 'Another Node',
                    'slug' => 'billing-node',
                    'url' => 'https://another.example.test',
                ],
            ],
        ]);

        $nodes = $result['payload']['nodes'] ?? [];

        $this->assertSame('Billing Node', $nodes[0]['name']);
        $this->assertSame('billing-node', $nodes[0]['slug']);
        $this->assertSame('https://billing.example.test', $nodes[0]['url']);
        $this->assertSame('child', $nodes[0]['type']);
        $this->assertSame('active', $nodes[0]['status']);
        $this->assertSame(['search', 'rag'], $nodes[0]['capabilities']);
        $this->assertSame(1, $nodes[0]['weight']);
        $this->assertSame('billing-node-2', $nodes[1]['slug']);
        $this->assertNotEmpty($result['changes']);
    }

    public function test_auto_fix_strict_mode_keeps_semantic_values_and_can_leave_duplicates(): void
    {
        $service = app(NodeBulkSyncService::class);

        $result = $service->autoFixPayload([
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing-node',
                    'url' => 'https://billing.example.test',
                    'status' => 'bad',
                ],
                [
                    'name' => 'Another Billing',
                    'slug' => 'billing-node',
                    'url' => 'https://billing2.example.test',
                ],
            ],
        ], true);

        $this->assertSame('strict', $result['mode']);
        $this->assertSame('bad', $result['payload']['nodes'][0]['status']);
        $this->assertSame('billing-node', $result['payload']['nodes'][1]['slug']);

        $normalized = $service->normalizeDefinitionsWithDiagnostics($result['payload']);
        $this->assertCount(1, $normalized['invalid']);
        $this->assertSame('Duplicate slug in payload.', $normalized['invalid'][0]['reason']);
    }
}
