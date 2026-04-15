<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Throwable;

class Neo4jIndexBenchmarkCommand extends Command
{
    protected $signature = 'ai-engine:neo4j-index-benchmark
                            {model? : The model class to benchmark (optional - benchmarks discovered vectorizable models if omitted)}
                            {--id=* : Specific model IDs to publish}
                            {--limit= : Max records per model to benchmark}
                            {--iterations=1 : Number of publish passes}
                            {--with-relations=true : Extract graph edges from configured vector relationships}
                            {--fresh : Clear the current graph before the benchmark}
                            {--index=}
                            {--property=}
                            {--scope-node=}
                            {--scope-tenant=}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Benchmark Neo4j graph indexing/publish latency for one or more vectorizable models';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');

        $this->applyOverrides();
        $this->applyRelationOption();

        $graphSync = app(Neo4jGraphSyncService::class);
        $models = $this->resolveModelsToBenchmark();
        if ($models === []) {
            $this->warn('No vectorizable models found for Neo4j index benchmark.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            if (!$graphSync->resetGraph()) {
                $this->error('Neo4j graph reset failed.');

                return self::FAILURE;
            }
        }

        if (!$graphSync->ensureSchema()) {
            $this->error('Neo4j schema initialization failed before benchmark.');

            return self::FAILURE;
        }

        $rows = [];
        foreach ($models as $modelClass) {
            $rows[] = $this->benchmarkModel($modelClass, $graphSync);
        }

        $this->table(
            ['Model', 'Records', 'Iterations', 'Avg publish ms', 'Max publish ms', 'Avg chunks', 'Avg relations'],
            $rows
        );

        foreach ($rows as $row) {
            app(GraphBenchmarkHistoryService::class)->record('indexing', [
                'query' => (string) $row[0],
                'avg_ms' => (float) str_replace(',', '', (string) $row[3]),
                'details' => sprintf(
                    'records=%s iterations=%s chunks=%s relations=%s',
                    $row[1],
                    $row[2],
                    $row[5],
                    $row[6]
                ),
            ]);
        }
        $this->info('Neo4j index benchmark completed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveModelsToBenchmark(): array
    {
        $modelClass = $this->argument('model');
        if (is_string($modelClass) && trim($modelClass) !== '') {
            return [trim($modelClass)];
        }

        try {
            return app(RAGCollectionDiscovery::class)->discover(useCache: false, includeFederated: false);
        } catch (Throwable $e) {
            $this->error('Failed to discover vectorizable models: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @return array<int, string|int>
     */
    protected function benchmarkModel(string $modelClass, Neo4jGraphSyncService $graphSync): array
    {
        if (!class_exists($modelClass)) {
            return [$modelClass, 0, 0, 'n/a', 'n/a', 'n/a', 'n/a'];
        }

        $instance = new $modelClass();
        if (!$instance instanceof Model) {
            return [$modelClass, 0, 0, 'n/a', 'n/a', 'n/a', 'n/a'];
        }

        $query = $modelClass::query();
        $ids = array_values(array_filter((array) $this->option('id'), static fn ($value) => $value !== null && $value !== ''));
        if ($ids !== []) {
            $query->whereKey($ids);
        }

        $relationships = $this->relationshipsToPreload($instance);
        if ($relationships !== []) {
            $query->with($relationships);
        }

        $limit = max(1, (int) ($this->option('limit') ?: config('ai-engine.graph.benchmark.default_index_limit', 10)));
        $records = $query->limit($limit)->get();
        if ($records->isEmpty()) {
            return [$modelClass, 0, 0, '0.00', '0.00', '0.00', '0.00'];
        }

        $iterations = max(1, (int) $this->option('iterations'));
        $durations = [];
        $chunkCounts = [];
        $relationCounts = [];

        for ($pass = 0; $pass < $iterations; $pass++) {
            foreach ($records as $record) {
                $payload = $graphSync->buildEntityPayload($record);
                $chunkCounts[] = count((array) ($payload['chunks'] ?? []));
                $relationCounts[] = count((array) ($payload['relations'] ?? []));

                $started = microtime(true);
                $graphSync->publish($record);
                $durations[] = (microtime(true) - $started) * 1000;
            }
        }

        return [
            $modelClass,
            $records->count(),
            $iterations,
            number_format($this->average($durations), 2),
            number_format(max($durations), 2),
            number_format($this->average($chunkCounts), 2),
            number_format($this->average($relationCounts), 2),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function relationshipsToPreload(Model $model): array
    {
        if (!method_exists($model, 'getIndexableRelationships')) {
            return [];
        }

        $relationships = $model->getIndexableRelationships(1);
        if (!is_array($relationships)) {
            return [];
        }

        return array_values(array_filter($relationships, static fn ($relation): bool => is_string($relation) && trim($relation) !== ''));
    }

    protected function applyRelationOption(): void
    {
        $withRelations = filter_var($this->option('with-relations'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        config()->set('ai-engine.graph.extract_relations_from_vector_relationships', $withRelations !== false);
    }

    protected function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
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
        ];

        foreach ($map as $option => $configKey) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                config()->set($configKey, trim($value));
            }
        }

        if (($timeout = $this->option('timeout')) !== null && $timeout !== '') {
            config()->set('ai-engine.graph.timeout', (int) $timeout);
        }
    }
}
