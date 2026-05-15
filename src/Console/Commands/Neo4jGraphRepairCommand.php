<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;

class Neo4jGraphRepairCommand extends Command
{
    protected $signature = 'ai:neo4j-repair
                            {--apply : Apply repairs instead of dry-run diagnostics}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Repair common Neo4j graph integrity issues such as orphan chunks and missing scope memberships';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        $this->applyOverrides();

        $transport = app(Neo4jHttpTransport::class);
        $apply = (bool) $this->option('apply');

        $operations = [
            'orphan_chunks' => [
                'check' => <<<'CYPHER'
MATCH (c:Chunk)
WHERE NOT EXISTS { MATCH (:Entity)-[:HAS_CHUNK]->(c) }
RETURN count(c) AS total
CYPHER,
                'repair' => <<<'CYPHER'
MATCH (c:Chunk)
WHERE NOT EXISTS { MATCH (:Entity)-[:HAS_CHUNK]->(c) }
WITH collect(c) AS chunks
FOREACH (chunk IN chunks | DETACH DELETE chunk)
RETURN size(chunks) AS total
CYPHER,
            ],
            'dangling_scopes' => [
                'check' => <<<'CYPHER'
MATCH (s:Scope)
WHERE NOT EXISTS { MATCH (:Entity)-[:BELONGS_TO]->(s) }
  AND NOT EXISTS { MATCH (:User)-[:CAN_ACCESS]->(s) }
RETURN count(s) AS total
CYPHER,
                'repair' => <<<'CYPHER'
MATCH (s:Scope)
WHERE NOT EXISTS { MATCH (:Entity)-[:BELONGS_TO]->(s) }
  AND NOT EXISTS { MATCH (:User)-[:CAN_ACCESS]->(s) }
WITH collect(s) AS scopes
FOREACH (scope IN scopes | DETACH DELETE scope)
RETURN size(scopes) AS total
CYPHER,
            ],
            'missing_scope_memberships' => [
                'check' => <<<'CYPHER'
MATCH (u:User)-[:CAN_ACCESS]->(e:Entity)-[:BELONGS_TO]->(s:Scope)
WHERE NOT EXISTS { MATCH (u)-[:CAN_ACCESS]->(s) }
RETURN count(DISTINCT s.scope_key + ':' + coalesce(u.canonical_user_id, u.user_email_normalized, 'anonymous')) AS total
CYPHER,
                'repair' => <<<'CYPHER'
MATCH (u:User)-[:CAN_ACCESS]->(e:Entity)-[:BELONGS_TO]->(s:Scope)
WHERE NOT EXISTS { MATCH (u)-[:CAN_ACCESS]->(s) }
WITH collect(DISTINCT [u, s]) AS pairs
FOREACH (pair IN pairs | MERGE (pair[0])-[:CAN_ACCESS]->(pair[1]))
RETURN size(pairs) AS total
CYPHER,
            ],
        ];

        $rows = [];
        foreach ($operations as $name => $operation) {
            $result = $transport->executeStatement([
                'statement' => $apply ? $operation['repair'] : $operation['check'],
                'parameters' => [],
            ]);

            if (!$result['success']) {
                $this->error("Repair step failed: {$name}");

                return self::FAILURE;
            }

            $rows[] = [
                'operation' => $name,
                'count' => $result['rows'][0]['total'] ?? 0,
                'mode' => $apply ? 'repaired' : 'detected',
            ];
        }

        $this->table(['Operation', 'Count', 'Mode'], array_map(
            static fn (array $row): array => [$row['operation'], $row['count'], $row['mode']],
            $rows
        ));

        if ($apply) {
            app(GraphKnowledgeBaseService::class)->bumpGraphVersion();
            $this->info('Graph repair pass completed.');
        } else {
            $this->info('Dry-run only. Use --apply to execute repairs.');
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
