<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;

class Neo4jGraphStatsCommand extends Command
{
    protected $signature = 'ai:neo4j-stats
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Show central Neo4j graph label, relation, and index statistics';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        $this->applyOverrides();

        $transport = app(Neo4jHttpTransport::class);

        $labelStats = $transport->executeStatement([
            'statement' => <<<'CYPHER'
MATCH (n)
UNWIND labels(n) AS label
RETURN label, count(*) AS total
ORDER BY total DESC, label ASC
CYPHER,
            'parameters' => [],
        ]);

        $relationStats = $transport->executeStatement([
            'statement' => <<<'CYPHER'
MATCH ()-[r]->()
RETURN type(r) AS relation, count(*) AS total
ORDER BY total DESC, relation ASC
CYPHER,
            'parameters' => [],
        ]);

        $indexStats = $transport->executeStatement([
            'statement' => <<<'CYPHER'
SHOW INDEXES
YIELD name, type, entityType, labelsOrTypes, properties, state
RETURN name, type, entityType, labelsOrTypes, properties, state
ORDER BY name ASC
CYPHER,
            'parameters' => [],
        ]);

        if (!$labelStats['success'] || !$relationStats['success'] || !$indexStats['success']) {
            $this->error('Failed to read Neo4j graph statistics.');

            return self::FAILURE;
        }

        $this->info('Node labels');
        $this->table(['Label', 'Count'], array_map(
            static fn (array $row): array => [$row['label'] ?? 'unknown', $row['total'] ?? 0],
            $labelStats['rows']
        ));

        $this->info('Relations');
        $this->table(['Relation', 'Count'], array_map(
            static fn (array $row): array => [$row['relation'] ?? 'unknown', $row['total'] ?? 0],
            $relationStats['rows']
        ));

        $this->info('Indexes');
        $this->table(['Name', 'Type', 'Entity', 'Labels', 'Properties', 'State'], array_map(
            static fn (array $row): array => [
                $row['name'] ?? 'unknown',
                $row['type'] ?? '',
                $row['entityType'] ?? '',
                implode(',', (array) ($row['labelsOrTypes'] ?? [])),
                implode(',', (array) ($row['properties'] ?? [])),
                $row['state'] ?? '',
            ],
            $indexStats['rows']
        ));

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
