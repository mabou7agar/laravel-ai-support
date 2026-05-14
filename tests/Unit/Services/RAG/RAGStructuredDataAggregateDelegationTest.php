<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\RAGAggregateService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;

class RAGStructuredDataAggregateDelegationTest extends UnitTestCase
{
    public function test_aggregate_delegates_to_aggregate_service(): void
    {
        $delegate = $this->createMock(RAGAggregateService::class);
        $delegate->expects($this->once())
            ->method('aggregate')
            ->with(
                $this->callback(fn (array $params): bool => ($params['model'] ?? null) === 'invoice'),
                9,
                $this->callback(fn (array $options): bool => ($options['session_id'] ?? null) === 'session-1'),
                $this->callback(fn (array $dependencies): bool => isset($dependencies['findModelClass']))
            )
            ->willReturn([
                'success' => true,
                'tool' => 'db_aggregate',
                'response' => 'delegated',
            ]);

        $service = new RAGStructuredDataService(
            new RAGDecisionStateService(new RAGDecisionPolicy()),
            new RAGDecisionPolicy(),
            $delegate
        );

        $result = $service->aggregate(
            ['model' => 'invoice'],
            9,
            ['session_id' => 'session-1'],
            ['findModelClass' => fn () => null]
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delegated', $result['response']);
    }
}
