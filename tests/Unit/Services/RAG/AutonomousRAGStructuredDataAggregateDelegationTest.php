<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGAggregateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousRAGStructuredDataAggregateDelegationTest extends UnitTestCase
{
    public function test_aggregate_delegates_to_aggregate_service(): void
    {
        $delegate = $this->createMock(AutonomousRAGAggregateService::class);
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

        $service = new AutonomousRAGStructuredDataService(
            new AutonomousRAGStateService(new AutonomousRAGPolicy()),
            new AutonomousRAGPolicy(),
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
