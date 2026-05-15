<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Console\Commands\SyncNeo4jGraphCommand;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class SyncNeo4jGraphCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('graph_sync_records', function ($table): void {
            $table->id();
            $table->string('title');
        });
    }

    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(SyncNeo4jGraphCommand::class));
        $this->assertSame('ai:neo4j-sync', (new SyncNeo4jGraphCommand())->getName());
    }

    public function test_command_discovers_and_syncs_records_with_fresh_option(): void
    {
        CommandGraphSyncModel::query()->create(['title' => 'Apollo']);
        CommandGraphSyncModel::query()->create(['title' => 'Hermes']);

        $sync = Mockery::mock(Neo4jGraphSyncService::class);
        $sync->shouldReceive('resetGraph')->once()->andReturn(true);
        $sync->shouldReceive('ensureSchema')->once()->andReturn(true);
        $sync->shouldReceive('publish')->twice()->andReturn(true);
        $this->app->instance(Neo4jGraphSyncService::class, $sync);

        $discovery = Mockery::mock(RAGCollectionDiscovery::class);
        $discovery->shouldReceive('discover')->once()->with(false, false)->andReturn([CommandGraphSyncModel::class]);
        $this->app->instance(RAGCollectionDiscovery::class, $discovery);

        $exitCode = Artisan::call('ai:neo4j-sync', [
            '--fresh' => true,
            '--batch' => 1,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Synced 2 record(s) to Neo4j.', Artisan::output());
    }
}

class CommandGraphSyncModel extends Model
{
    protected $table = 'graph_sync_records';
    public $timestamps = false;
    protected $guarded = [];

    public function getVectorContent(): string
    {
        return (string) $this->title;
    }

    public function getVectorMetadata(): array
    {
        return [];
    }
}
