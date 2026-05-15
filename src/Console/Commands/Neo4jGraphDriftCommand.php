<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphDriftDetectionService;

class Neo4jGraphDriftCommand extends Command
{
    protected $signature = 'ai:neo4j-drift
                            {--model=* : Restrict scan to one or more model classes}
                            {--limit= : Limit scanned records per model}
                            {--repair : Publish missing local entities back to graph}
                            {--prune : Delete stale graph-only entities during repair}
                            {--json : Emit JSON output}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Diff local models against Neo4j graph state and optionally repair drift';

    public function handle(GraphDriftDetectionService $drift): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        $this->applyOverrides();

        $models = array_values(array_filter(array_map('strval', (array) $this->option('model'))));
        $limit = $this->option('limit') !== null && $this->option('limit') !== '' ? (int) $this->option('limit') : null;
        $report = $drift->scan($models, $limit);
        $repair = ['published' => 0, 'pruned' => 0];

        if ((bool) $this->option('repair')) {
            $repair = $drift->repair($report, (bool) $this->option('prune'));
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'report' => $report,
                'repair' => $repair,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Model', 'Local', 'Graph', 'Missing', 'Stale'], array_map(
            static fn (array $row): array => [
                $row['model'] ?? 'n/a',
                $row['local_total'] ?? 0,
                $row['graph_total'] ?? 0,
                $row['missing_in_graph'] ?? 0,
                $row['stale_in_graph'] ?? 0,
            ],
            (array) ($report['models'] ?? [])
        ));

        $totals = (array) ($report['totals'] ?? []);
        $this->line(sprintf(
            'Totals: local=%d graph=%d missing=%d stale=%d',
            $totals['local_entities'] ?? 0,
            $totals['graph_entities'] ?? 0,
            $totals['missing_in_graph'] ?? 0,
            $totals['stale_in_graph'] ?? 0
        ));

        if ((bool) $this->option('repair')) {
            $this->info(sprintf(
                'Repair completed. Published %d missing entities and pruned %d stale entities.',
                $repair['published'] ?? 0,
                $repair['pruned'] ?? 0
            ));
        }

        return self::SUCCESS;
    }

    protected function applyOverrides(): void
    {
        $map = [
            'url' => 'ai-engine.graph.neo4j.url',
            'database' => 'ai-engine.graph.neo4j.database',
            'username' => 'ai-engine.graph.neo4j.username',
            'password' => 'ai-engine.graph.neo4j.password',
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
