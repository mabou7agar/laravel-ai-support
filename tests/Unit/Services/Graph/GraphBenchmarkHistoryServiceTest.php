<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphBenchmarkHistoryServiceTest extends UnitTestCase
{
    public function test_it_records_and_lists_history(): void
    {
        $service = new GraphBenchmarkHistoryService();

        $service->record('retrieval', [
            'query' => 'who owns apollo?',
            'avg_ms' => 123.45,
            'details' => 'strategy=ownership',
        ]);

        $history = $service->latest('retrieval');

        $this->assertCount(1, $history);
        $this->assertSame('retrieval', $history[0]['type']);
        $this->assertSame('who owns apollo?', $history[0]['query']);
        $this->assertSame(123.45, $history[0]['avg_ms']);
    }
}
