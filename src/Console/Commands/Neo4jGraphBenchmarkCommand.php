<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;
use LaravelAIEngine\Services\Graph\GraphQueryPlanner;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;

class Neo4jGraphBenchmarkCommand extends Command
{
    protected $signature = 'ai:neo4j-benchmark
                            {query : Natural-language query to benchmark}
                            {--collection=* : Restrict retrieval to one or more model classes}
                            {--iterations= : Number of benchmark iterations}
                            {--max-results= : Maximum results returned per iteration}
                            {--canonical-user-id= : Explicit canonical user id for scoped retrieval}
                            {--email= : Explicit normalized user email for scoped retrieval}
                            {--user= : Authenticated user id for retrieval}
                            {--warm-cache : Prime the KB/result cache before measurement}
                            {--index=}
                            {--property=}
                            {--scope-node=}
                            {--scope-tenant=}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Benchmark central Neo4j graph planning and retrieval latency';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);

        $this->applyOverrides();

        $planner = app(GraphQueryPlanner::class);
        $retrieval = app(Neo4jRetrievalService::class);

        $query = trim((string) $this->argument('query'));
        $collections = array_values(array_filter(array_map('strval', (array) $this->option('collection'))));
        $iterations = max(1, (int) ($this->option('iterations') ?: config('ai-engine.graph.benchmark.default_iterations', 5)));
        $maxResults = max(1, (int) ($this->option('max-results') ?: config('ai-engine.graph.benchmark.default_max_results', 5)));
        $userId = ($this->option('user') !== null && $this->option('user') !== '') ? (int) $this->option('user') : null;
        $options = $this->scope() === [] ? [] : ['access_scope' => $this->scope()];

        if ((bool) $this->option('warm-cache')) {
            $retrieval->retrieveRelevantContext([$query], $collections, $maxResults, $options, $userId);
        }

        $plannerDurations = [];
        $retrievalDurations = [];
        $cacheHits = 0;
        $resultCounts = [];
        $lastPlan = [];

        for ($i = 0; $i < $iterations; $i++) {
            $plannerStarted = microtime(true);
            $lastPlan = $planner->plan($query, $collections, $options, $maxResults);
            $plannerDurations[] = (microtime(true) - $plannerStarted) * 1000;

            $retrievalStarted = microtime(true);
            $results = $retrieval->retrieveRelevantContext([$query], $collections, $maxResults, $options, $userId);
            $retrievalDurations[] = (microtime(true) - $retrievalStarted) * 1000;

            $resultCounts[] = $results->count();
            if ($results->contains(fn ($item) => (bool) (($item->vector_metadata['graph_kb_cache_hit'] ?? false) === true))) {
                $cacheHits++;
            }
        }

        $this->table(['Metric', 'Value'], [
            ['query', $query],
            ['collections', $collections === [] ? 'all' : implode(', ', $collections)],
            ['iterations', $iterations],
            ['planner_strategy', $lastPlan['strategy'] ?? 'n/a'],
            ['planner_query_kind', $lastPlan['query_kind'] ?? 'n/a'],
            ['planner_template', $lastPlan['cypher_template'] ?? 'n/a'],
            ['avg_planner_ms', number_format($this->average($plannerDurations), 2)],
            ['avg_retrieval_ms', number_format($this->average($retrievalDurations), 2)],
            ['min_retrieval_ms', number_format(min($retrievalDurations), 2)],
            ['max_retrieval_ms', number_format(max($retrievalDurations), 2)],
            ['avg_results', number_format($this->average($resultCounts), 2)],
            ['kb_cache_hit_iterations', $cacheHits],
        ]);

        app(GraphBenchmarkHistoryService::class)->record('retrieval', [
            'query' => $query,
            'avg_ms' => round($this->average($retrievalDurations), 2),
            'details' => sprintf(
                'strategy=%s kind=%s template=%s results=%.2f cache_hits=%d',
                $lastPlan['strategy'] ?? 'n/a',
                $lastPlan['query_kind'] ?? 'n/a',
                $lastPlan['cypher_template'] ?? 'n/a',
                $this->average($resultCounts),
                $cacheHits
            ),
        ]);

        $this->info('Neo4j graph benchmark completed.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    protected function scope(): array
    {
        return array_filter([
            'canonical_user_id' => $this->option('canonical-user-id') ?: null,
            'user_email_normalized' => $this->option('email') ? strtolower(trim((string) $this->option('email'))) : null,
        ], static fn ($value) => $value !== null && $value !== '');
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
