<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Services\Graph\GraphDriftDetectionService;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GraphDriftDetectionServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_drift_records', function ($table): void {
            $table->increments('id');
            $table->string('title')->nullable();
        });
    }

    public function test_it_detects_missing_and_stale_entities(): void
    {
        GraphDriftRecord::query()->create(['title' => 'Apollo']);
        GraphDriftRecord::query()->create(['title' => 'Hermes']);

        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')
            ->once()
            ->andReturn([
                'success' => true,
                'rows' => [
                    ['entity_key' => 'local:' . GraphDriftRecord::class . ':1'],
                    ['entity_key' => 'local:' . GraphDriftRecord::class . ':99'],
                ],
            ]);

        $sync = Mockery::mock(Neo4jGraphSyncService::class);
        $discovery = Mockery::mock(RAGCollectionDiscovery::class);
        $discovery->shouldReceive('discover')->once()->andReturn([GraphDriftRecord::class]);

        $service = new GraphDriftDetectionService(
            new SearchDocumentBuilder(),
            $transport,
            $sync,
            $discovery
        );

        $report = $service->scan();

        $this->assertSame(2, $report['totals']['local_entities']);
        $this->assertSame(2, $report['totals']['graph_entities']);
        $this->assertSame(1, $report['totals']['missing_in_graph']);
        $this->assertSame(1, $report['totals']['stale_in_graph']);
        $this->assertSame([2], $report['models'][0]['missing_model_ids']);
        $this->assertSame(['local:' . GraphDriftRecord::class . ':99'], $report['models'][0]['stale_entity_keys']);
    }

    public function test_it_repairs_missing_and_prunes_stale_entities(): void
    {
        $record = GraphDriftRecord::query()->create(['title' => 'Apollo']);

        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')
            ->once()
            ->andReturn(['success' => true, 'rows' => [['deleted' => 1]]]);

        $sync = Mockery::mock(Neo4jGraphSyncService::class);
        $sync->shouldReceive('publish')->once()->withArgs(fn ($model) => $model instanceof GraphDriftRecord && $model->id === $record->id)->andReturn(true);

        $service = new GraphDriftDetectionService(
            new SearchDocumentBuilder(),
            $transport,
            $sync,
            Mockery::mock(RAGCollectionDiscovery::class)
        );

        $repair = $service->repair([
            'models' => [[
                'model' => GraphDriftRecord::class,
                'missing_model_ids' => [$record->id],
                'stale_entity_keys' => ['local:' . GraphDriftRecord::class . ':77'],
            ]],
            'totals' => [],
        ], true);

        $this->assertSame(1, $repair['published']);
        $this->assertSame(1, $repair['pruned']);
    }
}

class GraphDriftRecord extends Model
{
    protected $table = 'graph_drift_records';
    public $timestamps = false;
    protected $guarded = [];

    public function getVectorContent(): string
    {
        return (string) $this->title;
    }

    public function getVectorMetadata(): array
    {
        return [
            'source_node' => 'local',
        ];
    }
}
