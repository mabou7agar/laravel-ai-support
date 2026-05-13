<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\DTOs\GraphVectorLink;
use LaravelAIEngine\DTOs\SearchDocument;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;

class GraphVectorLinkTest extends UnitTestCase
{
    public function test_builds_stable_graph_and_qdrant_references_from_search_document(): void
    {
        config()->set('ai-engine.vector.collection_prefix', 'vec_');

        $document = new SearchDocument(
            modelClass: GraphVectorLinkTestModel::class,
            modelId: 42,
            content: 'Hybrid retrieval content',
            sourceNode: 'local-app'
        );

        $link = GraphVectorLink::fromSearchDocument(
            $document,
            1,
            null,
            GraphVectorLink::pointId($document->modelId, 1, true)
        );

        $this->assertSame('local-app:' . GraphVectorLinkTestModel::class . ':42', $link->graphNodeId);
        $this->assertSame($link->graphNodeId . '#chunk:1', $link->graphChunkId);
        $this->assertSame('vec_hybrid_graph_vector_link_test_models', $link->vectorCollection);
        $this->assertSame('42_chunk_1', $link->vectorPointId);
        $this->assertSame('42_chunk_0', GraphVectorLink::pointId(42, 0, true));
        $this->assertSame('42', GraphVectorLink::pointId(42));
    }

    public function test_restores_link_from_vector_metadata(): void
    {
        $link = GraphVectorLink::fromVectorMetadata([
            'graph_vector_link' => [
                'graph_node_id' => 'local:Model:7',
                'graph_chunk_id' => 'local:Model:7#chunk:0',
                'qdrant_collection' => 'vec_models',
                'qdrant_point_id' => '7',
                'model_class' => 'Model',
                'model_id' => 7,
                'chunk_index' => 0,
            ],
        ]);

        $this->assertNotNull($link);
        $this->assertSame('local:Model:7', $link->graphNodeId);
        $this->assertSame('vec_models', $link->vectorCollection);
        $this->assertSame('7', $link->vectorPointId);
    }
}

class GraphVectorLinkTestModel extends Model
{
    use Vectorizable;

    protected $table = 'hybrid_graph_vector_link_test_models';
}
