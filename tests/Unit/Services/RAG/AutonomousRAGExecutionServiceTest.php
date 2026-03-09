<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService;
use PHPUnit\Framework\TestCase;

class AutonomousRAGExecutionServiceTest extends TestCase
{
    public function test_normalize_defaults_to_db_query(): void
    {
        $service = new AutonomousRAGExecutionService();

        $normalized = $service->normalize([]);

        $this->assertSame('db_query', $normalized['tool']);
        $this->assertSame([], $normalized['parameters']);
    }

    public function test_execute_uses_matching_handler(): void
    {
        $service = new AutonomousRAGExecutionService();

        $result = $service->execute([
            'tool' => 'vector_search',
            'parameters' => ['query' => 'urgent'],
        ], [
            'vector_search' => fn (array $plan) => ['tool' => $plan['tool'], 'query' => $plan['parameters']['query']],
            'db_query' => fn (array $plan) => ['tool' => 'db_query'],
        ]);

        $this->assertSame('vector_search', $result['tool']);
        $this->assertSame('urgent', $result['query']);
    }
}
