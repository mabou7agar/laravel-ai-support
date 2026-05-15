<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;

class Neo4jGraphDiagnoseCommand extends Command
{
    protected $signature = 'ai:neo4j-diagnose
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Run integrity checks against the central Neo4j graph';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        $this->applyOverrides();

        $transport = app(Neo4jHttpTransport::class);
        $checks = [
            'orphan_chunks' => <<<'CYPHER'
MATCH (c:Chunk)
WHERE NOT EXISTS { MATCH (:Entity)-[:HAS_CHUNK]->(c) }
RETURN count(c) AS total
CYPHER,
            'entities_without_chunks' => <<<'CYPHER'
MATCH (e:Entity)
WHERE NOT EXISTS { MATCH (e)-[:HAS_CHUNK]->(:Chunk) }
RETURN count(e) AS total
CYPHER,
            'entities_without_source_app' => <<<'CYPHER'
MATCH (e:Entity)
WHERE NOT EXISTS { MATCH (e)-[:SOURCE_APP]->(:App) }
RETURN count(e) AS total
CYPHER,
            'scopes_without_members' => <<<'CYPHER'
MATCH (s:Scope)
WHERE NOT EXISTS { MATCH (:User)-[:CAN_ACCESS]->(s) }
RETURN count(s) AS total
CYPHER,
        ];

        $rows = [];
        foreach ($checks as $name => $statement) {
            $result = $transport->executeStatement([
                'statement' => $statement,
                'parameters' => [],
            ]);

            if (!$result['success']) {
                $this->error("Check failed: {$name}");

                return self::FAILURE;
            }

            $rows[] = [
                'check' => $name,
                'count' => $result['rows'][0]['total'] ?? 0,
            ];
        }

        $this->table(['Check', 'Count'], array_map(
            static fn (array $row): array => [$row['check'], $row['count']],
            $rows
        ));

        $hasIssue = collect($rows)->contains(static fn (array $row): bool => (int) $row['count'] > 0);
        if ($hasIssue) {
            $this->warn('Graph integrity issues were detected.');

            return self::FAILURE;
        }

        $this->info('No graph integrity issues detected.');

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
