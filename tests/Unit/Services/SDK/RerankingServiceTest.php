<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Services\SDK\RerankingService;
use LaravelAIEngine\Tests\UnitTestCase;

class RerankingServiceTest extends UnitTestCase
{
    public function test_rerank_orders_documents_by_query_overlap(): void
    {
        $service = new RerankingService();

        $results = $service->rerank('laravel ai sdk tools', [
            'Billing invoice workflow',
            'Laravel AI agents and tools',
            'Vector database maintenance',
        ]);

        $this->assertSame('Laravel AI agents and tools', $results[0]->document);
        $this->assertGreaterThan(0, $results[0]->score);
    }

    public function test_rerank_fake_records_requests(): void
    {
        $service = (new RerankingService())->fake([
            ['index' => 2, 'document' => 'Pinned result', 'score' => 0.99],
        ]);

        $results = $service->rerank('anything', ['a', 'b']);

        $this->assertSame('Pinned result', $results[0]->document);
        $service->assertReranked(fn (array $request): bool => $request['query'] === 'anything');
    }
}
