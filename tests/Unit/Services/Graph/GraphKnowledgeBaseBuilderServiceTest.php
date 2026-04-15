<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService;
use LaravelAIEngine\Services\Graph\GraphQueryPlanner;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GraphKnowledgeBaseBuilderServiceTest extends UnitTestCase
{
    public function test_build_entity_snapshots_caches_neighbors_for_scope(): void
    {
        $kb = Mockery::mock(GraphKnowledgeBaseService::class);
        $kb->shouldReceive('cacheEntitySnapshot')
            ->once()
            ->withArgs(function (string $entityKey, array $scope, array $snapshot): bool {
                $this->assertSame('local:App\\Models\\Project:7', $entityKey);
                $this->assertSame('7', $scope['canonical_user_id'] ?? null);
                $this->assertSame('Apollo', $snapshot['title'] ?? null);
                $this->assertCount(1, $snapshot['neighbors'] ?? []);

                return true;
            });

        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')
            ->once()
            ->andReturn([
                'success' => true,
                'rows' => [[
                    'entity_key' => 'local:App\\Models\\Project:7',
                    'title' => 'Apollo',
                    'rag_summary' => 'Apollo project',
                    'model_class' => 'App\\Models\\Project',
                    'neighbors' => [[
                        'relation_type' => 'HAS_TASK',
                        'entity_key' => 'local:App\\Models\\Task:9',
                        'title' => 'Review Apollo dependencies',
                        'model_class' => 'App\\Models\\Task',
                        'rag_summary' => 'Open task',
                    ]],
                ]],
                'error' => null,
            ]);

        $service = new GraphKnowledgeBaseBuilderService(
            $kb,
            $transport,
            Mockery::mock(GraphQueryPlanner::class),
            Mockery::mock(Neo4jRetrievalService::class)
        );

        $result = $service->buildEntitySnapshots(['canonical_user_id' => '7'], 10);

        $this->assertSame(['snapshots' => 1], $result);
    }
}
