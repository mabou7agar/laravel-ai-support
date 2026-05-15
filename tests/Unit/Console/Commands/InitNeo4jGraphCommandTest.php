<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Console\Commands\InitNeo4jGraphCommand;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class InitNeo4jGraphCommandTest extends TestCase
{
    public function test_command_exists(): void
    {
        $this->assertTrue(class_exists(InitNeo4jGraphCommand::class));
        $this->assertSame('ai:neo4j-init', (new InitNeo4jGraphCommand())->getName());
    }

    public function test_command_initializes_schema_with_overrides(): void
    {
        $mock = Mockery::mock(Neo4jGraphSyncService::class);
        $mock->shouldReceive('ensureSchema')->once()->andReturn(true);
        $this->app->instance(Neo4jGraphSyncService::class, $mock);

        $exitCode = Artisan::call('ai:neo4j-init', [
            '--url' => 'http://neo4j.test',
            '--database' => 'tenant_graph',
            '--username' => 'neo4j',
            '--password' => 'secret',
            '--index' => 'tenant_chunk_index',
            '--property' => 'tenant_embedding_field',
            '--scope-node' => 'dash',
            '--scope-tenant' => 'tenant-a',
            '--dimensions' => 256,
            '--similarity' => 'cosine',
            '--timeout' => 15,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('http://neo4j.test', config('ai-engine.graph.neo4j.url'));
        $this->assertSame('tenant_graph', config('ai-engine.graph.neo4j.database'));
        $this->assertSame('tenant_chunk_index', config('ai-engine.graph.neo4j.chunk_vector_index'));
        $this->assertSame('tenant_embedding_field', config('ai-engine.graph.neo4j.chunk_vector_property'));
        $this->assertSame('dash', config('ai-engine.graph.neo4j.vector_naming.node_slug'));
        $this->assertSame('tenant-a', config('ai-engine.graph.neo4j.vector_naming.tenant_key'));
        $this->assertSame(256, config('ai-engine.vector.embedding_dimensions'));
        $this->assertSame(15, config('ai-engine.graph.timeout'));

        $output = Artisan::output();
        $this->assertStringContainsString('Initializing Neo4j graph schema', $output);
        $this->assertStringContainsString('Neo4j schema initialized successfully.', $output);
    }

    public function test_command_returns_failure_when_schema_init_fails(): void
    {
        $mock = Mockery::mock(Neo4jGraphSyncService::class);
        $mock->shouldReceive('ensureSchema')->once()->andReturn(false);
        $this->app->instance(Neo4jGraphSyncService::class, $mock);

        $exitCode = Artisan::call('ai:neo4j-init');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Neo4j schema initialization failed.', Artisan::output());
    }
}
