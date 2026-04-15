<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Throwable;

class SyncNeo4jGraphCommand extends Command
{
    protected $signature = 'ai-engine:neo4j-sync
                            {model? : The model class to sync (optional - syncs all discovered vectorizable models if omitted)}
                            {--id=* : Specific model IDs to sync}
                            {--batch=100 : Batch size for bulk sync}
                            {--fresh : Clear the current graph before syncing}
                            {--with-relations=true : Extract graph edges from configured vector relationships}
                            {--index= : Override the chunk vector index name}
                            {--property= : Override the chunk vector property name}
                            {--scope-node= : Override the vector naming node scope}
                            {--scope-tenant= : Override the vector naming tenant scope}
                            {--url= : Override the Neo4j base URL}
                            {--database= : Override the Neo4j database name}
                            {--username= : Override the Neo4j username}
                            {--password= : Override the Neo4j password}
                            {--timeout= : Override the Neo4j HTTP timeout in seconds}';

    protected $description = 'Bulk publish vectorizable models into the central Neo4j graph';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');

        $this->applyOverrides();
        $this->applyRelationOption();

        $models = $this->resolveModelsToSync();
        if ($models === []) {
            $this->warn('No vectorizable models found for Neo4j sync.');

            return self::SUCCESS;
        }

        $graphSync = $this->graphSync();

        if ($this->option('fresh')) {
            $this->warn('Clearing existing Neo4j graph data before sync.');
            if (!$graphSync->resetGraph()) {
                $this->error('Neo4j graph reset failed.');

                return self::FAILURE;
            }
        }

        if (!$graphSync->ensureSchema()) {
            $this->error('Neo4j schema initialization failed before sync.');

            return self::FAILURE;
        }

        $totalSynced = 0;
        $totalSkipped = 0;
        $totalFailed = 0;

        foreach ($models as $modelClass) {
            $this->line("Syncing {$modelClass}");
            [$synced, $skipped, $failed] = $this->syncModel($modelClass);
            $totalSynced += $synced;
            $totalSkipped += $skipped;
            $totalFailed += $failed;
        }

        $this->newLine();
        $this->info("Synced {$totalSynced} record(s) to Neo4j.");
        if ($totalSkipped > 0) {
            $this->line("Skipped {$totalSkipped} record(s).");
        }
        if ($totalFailed > 0) {
            $this->error("Failed to sync {$totalFailed} record(s).");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveModelsToSync(): array
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
     * @return array{0:int,1:int,2:int}
     */
    protected function syncModel(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");

            return [0, 0, 1];
        }

        $instance = new $modelClass();
        if (!$instance instanceof Model) {
            $this->error("{$modelClass} is not an Eloquent model.");

            return [0, 0, 1];
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

        $batchSize = max(1, (int) $this->option('batch'));
        $keyName = $instance->getKeyName();
        $total = (clone $query)->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        $query->orderBy($keyName)->chunk($batchSize, function ($records) use (&$synced, &$skipped, &$failed, $bar): void {
            foreach ($records as $record) {
                if (method_exists($record, 'shouldBeIndexed') && !$record->shouldBeIndexed()) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                try {
                    if ($this->graphSync()->publish($record)) {
                        $synced++;
                    } else {
                        $failed++;
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error('Sync failed for ' . get_class($record) . '#' . $record->getKey() . ': ' . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        return [$synced, $skipped, $failed];
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
