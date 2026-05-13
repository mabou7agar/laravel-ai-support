<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;
use Mockery;

class HybridGraphVectorSearchServiceTest extends UnitTestCase
{
    public function test_returns_empty_when_hybrid_retrieval_is_disabled(): void
    {
        config()->set('ai-engine.rag.hybrid.enabled', false);

        $vector = Mockery::mock(VectorSearchService::class);
        $graph = Mockery::mock(Neo4jRetrievalService::class);

        $vector->shouldNotReceive('search');
        $graph->shouldNotReceive('retrieveRelevantContext');

        $service = new HybridGraphVectorSearchService($vector, $graph);

        $this->assertTrue($service->retrieveRelevantContext(
            ['invoice owner'],
            [HybridGraphVectorSearchTestModel::class],
            5
        )->isEmpty());
    }

    public function test_merges_qdrant_vector_hits_with_neo4j_graph_expansion(): void
    {
        config()->set('ai-engine.rag.hybrid.enabled', true);
        config()->set('ai-engine.rag.hybrid.strategy', 'vector_then_graph');
        config()->set('ai-engine.rag.hybrid.vector_weight', 0.6);
        config()->set('ai-engine.rag.hybrid.graph_weight', 0.4);

        $vectorHit = (object) [
            'id' => 1,
            'vector_score' => 0.8,
            'vector_metadata' => [
                'model_class' => HybridGraphVectorSearchTestModel::class,
                'model_id' => 1,
                'graph_node_id' => 'local:' . HybridGraphVectorSearchTestModel::class . ':1',
            ],
        ];
        $graphDuplicate = (object) [
            'id' => 1,
            'entity_key' => 'local:' . HybridGraphVectorSearchTestModel::class . ':1',
            'vector_score' => 0.9,
            'vector_metadata' => ['relation_path' => ['BELONGS_TO']],
        ];
        $graphNeighbor = (object) [
            'id' => 2,
            'entity_key' => 'local:' . HybridGraphVectorSearchTestModel::class . ':2',
            'vector_score' => 0.7,
            'vector_metadata' => ['relation_path' => ['HAS_INVOICE']],
        ];

        $vector = Mockery::mock(VectorSearchService::class);
        $vector->shouldReceive('search')
            ->once()
            ->with(HybridGraphVectorSearchTestModel::class, 'invoice owner', 10, 0.3, [], null)
            ->andReturn(collect([$vectorHit]));

        $graph = Mockery::mock(Neo4jRetrievalService::class);
        $graph->shouldReceive('enabled')->once()->andReturn(true);
        $graph->shouldReceive('retrieveRelevantContext')
            ->once()
            ->with(
                ['invoice owner'],
                [HybridGraphVectorSearchTestModel::class],
                10,
                Mockery::on(function (array $options): bool {
                    return ($options['last_entity_list']['entity_refs'][0]['entity_key'] ?? null)
                        === 'local:' . HybridGraphVectorSearchTestModel::class . ':1';
                }),
                null
            )
            ->andReturn(collect([$graphDuplicate, $graphNeighbor]));

        $service = new HybridGraphVectorSearchService($vector, $graph);
        $results = $service->retrieveRelevantContext(
            ['invoice owner'],
            [HybridGraphVectorSearchTestModel::class],
            5,
            0.3
        );

        $this->assertCount(2, $results);
        $first = $results->first();
        $this->assertSame(1, $first->id);
        $this->assertSame(['vector', 'graph'], $first->vector_metadata['hybrid_sources']);
        $this->assertEqualsWithDelta(0.84, $first->vector_metadata['hybrid_score'], 0.000001);
        $this->assertSame(['graph'], $results->last()->vector_metadata['hybrid_sources']);
    }

    public function test_reciprocal_rank_combines_independent_vector_and_graph_rankings(): void
    {
        config()->set('ai-engine.rag.hybrid.enabled', true);
        config()->set('ai-engine.rag.hybrid.strategy', 'reciprocal_rank');
        config()->set('ai-engine.rag.hybrid.rrf_k', 60);

        $vector = Mockery::mock(VectorSearchService::class);
        $vector->shouldReceive('search')->once()->andReturn(collect([
            (object) ['id' => 10, 'vector_score' => 0.4, 'vector_metadata' => ['model_class' => HybridGraphVectorSearchTestModel::class, 'model_id' => 10]],
        ]));

        $graph = Mockery::mock(Neo4jRetrievalService::class);
        $graph->shouldReceive('enabled')->once()->andReturn(true);
        $graph->shouldReceive('retrieveRelevantContext')->once()->andReturn(collect([
            (object) ['id' => 10, 'vector_score' => 0.2, 'vector_metadata' => [
                'model_class' => HybridGraphVectorSearchTestModel::class,
                'model_id' => 10,
            ]],
        ]));

        $service = new HybridGraphVectorSearchService($vector, $graph);
        $results = $service->retrieveRelevantContext(['query'], [HybridGraphVectorSearchTestModel::class], 3);

        $this->assertCount(1, $results);
        $this->assertSame(['vector', 'graph'], $results->first()->vector_metadata['hybrid_sources']);
        $this->assertGreaterThan(0, $results->first()->vector_metadata['reciprocal_rank_score']);
    }
}

class HybridGraphVectorSearchTestModel extends Model
{
    use Vectorizable;

    protected $table = 'hybrid_graph_vector_search_test_models';
}
