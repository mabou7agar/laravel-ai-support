<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;

class InitNeo4jGraphCommand extends Command
{
    protected $signature = 'ai:neo4j-init
                            {--url= : Override the Neo4j base URL}
                            {--database= : Override the Neo4j database name}
                            {--username= : Override the Neo4j username}
                            {--password= : Override the Neo4j password}
                            {--index= : Override the chunk vector index name}
                            {--property= : Override the chunk vector property name}
                            {--scope-node= : Override the vector naming node scope}
                            {--scope-tenant= : Override the vector naming tenant scope}
                            {--dimensions= : Override the vector dimensions for index creation}
                            {--similarity= : Override the vector similarity function (cosine|euclidean)}
                            {--timeout= : Override the Neo4j HTTP timeout in seconds}';

    protected $description = 'Initialize Neo4j GraphRAG schema and chunk vector index for a target database';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');

        $this->applyOverrides();

        $this->line('Initializing Neo4j graph schema');
        $this->line('URL: ' . config('ai-engine.graph.neo4j.url'));
        $this->line('Database: ' . config('ai-engine.graph.neo4j.database'));
        $this->line('Vector index: ' . config('ai-engine.graph.neo4j.chunk_vector_index'));
        $this->line('Vector property: ' . config('ai-engine.graph.neo4j.chunk_vector_property'));
        $this->line('Dimensions: ' . config('ai-engine.vector.embedding_dimensions'));
        $this->line('Similarity: ' . config('ai-engine.graph.neo4j.vector_similarity'));

        if (!$this->graphSync()->ensureSchema()) {
            $this->error('Neo4j schema initialization failed.');

            return self::FAILURE;
        }

        $this->info('Neo4j schema initialized successfully.');

        return self::SUCCESS;
    }

    protected function applyOverrides(): void
    {
        $map = [
            'url' => 'ai-engine.graph.neo4j.url',
            'database' => 'ai-engine.graph.neo4j.database',
            'username' => 'ai-engine.graph.neo4j.username',
            'password' => 'ai-engine.graph.neo4j.password',
            'index' => 'ai-engine.graph.neo4j.chunk_vector_index',
            'property' => 'ai-engine.graph.neo4j.chunk_vector_property',
            'scope-node' => 'ai-engine.graph.neo4j.vector_naming.node_slug',
            'scope-tenant' => 'ai-engine.graph.neo4j.vector_naming.tenant_key',
            'similarity' => 'ai-engine.graph.neo4j.vector_similarity',
        ];

        foreach ($map as $option => $configKey) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                config()->set($configKey, trim($value));
            }
        }

        $dimensions = $this->option('dimensions');
        if ($dimensions !== null && $dimensions !== '') {
            config()->set('ai-engine.vector.embedding_dimensions', (int) $dimensions);
        }

        $timeout = $this->option('timeout');
        if ($timeout !== null && $timeout !== '') {
            config()->set('ai-engine.graph.timeout', (int) $timeout);
        }
    }

    protected function graphSync(): Neo4jGraphSyncService
    {
        return app(Neo4jGraphSyncService::class);
    }
}
