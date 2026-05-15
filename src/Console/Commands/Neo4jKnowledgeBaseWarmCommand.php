<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService;
use LaravelAIEngine\Services\Graph\GraphQueryPlanner;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;

class Neo4jKnowledgeBaseWarmCommand extends Command
{
    protected $signature = 'ai:neo4j-kb-warm
                            {query?* : Query strings to warm}
                            {--from-profiles : Warm from recorded graph query profiles}
                            {--collection=* : Restrict warmup to specific model classes}
                            {--user= : Authenticated user id for scoped result warming}
                            {--canonical-user-id= : Explicit canonical user id for scoped result warming}
                            {--email= : Explicit normalized user email for scoped result warming}
                            {--max-results=5 : Number of results to warm per query}
                            {--plan-only : Warm planner cache only}
                            {--limit=25 : Maximum number of profiled queries to warm}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Warm the central graph knowledge-base plan and retrieval caches';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);

        $this->applyOverrides();

        $kb = app(GraphKnowledgeBaseService::class);
        $planner = app(GraphQueryPlanner::class);
        $retrieval = app(Neo4jRetrievalService::class);

        $entries = $this->queriesToWarm($kb);
        if ($entries === []) {
            $this->warn('No graph knowledge-base queries available to warm.');

            return self::SUCCESS;
        }

        $scope = $this->warmScope();
        $userId = $this->option('user');
        $maxResults = max(1, (int) $this->option('max-results'));
        $planOnly = (bool) $this->option('plan-only');

        $planCount = 0;
        $resultCount = 0;

        foreach ($entries as $entry) {
            $query = trim((string) ($entry['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            $collections = $entry['collections'] ?? [];
            $signals = $entry['signals'] ?? [];
            $options = ['access_scope' => $scope];

            $kb->rememberPlan(
                $query,
                $collections,
                $scope,
                $signals,
                fn (): array => $planner->plan($query, $collections, $options, $maxResults)
            );
            $planCount++;

            if ($planOnly || $scope === []) {
                continue;
            }

            $retrieval->retrieveRelevantContext([$query], $collections, $maxResults, $options, $userId);
            $resultCount++;
        }

        $this->info("Warmed {$planCount} graph plan cache entr" . ($planCount === 1 ? 'y' : 'ies') . '.');
        if ($scope === [] && !$planOnly) {
            $this->line('Skipped result warmup because no scoped user/access context was provided.');
        } elseif (!$planOnly) {
            $this->info("Warmed {$resultCount} scoped retrieval cache entr" . ($resultCount === 1 ? 'y' : 'ies') . '.');
        }

        return self::SUCCESS;
    }

    /**
     * @param GraphKnowledgeBaseService $kb
     * @return array<int, array<string, mixed>>
     */
    protected function queriesToWarm(GraphKnowledgeBaseService $kb): array
    {
        $collections = array_values(array_filter((array) $this->option('collection'), static fn ($value): bool => is_string($value) && trim($value) !== ''));
        $explicitQueries = array_values(array_filter((array) $this->argument('query'), static fn ($value): bool => is_string($value) && trim($value) !== ''));

        if ($explicitQueries !== []) {
            return array_map(static fn (string $query): array => [
                'query' => trim($query),
                'collections' => $collections,
                'signals' => [],
            ], $explicitQueries);
        }

        if (!(bool) $this->option('from-profiles')) {
            return [];
        }

        $profiles = $kb->listQueryProfiles(max(
            1,
            (int) ($this->option('limit') ?: config('ai-engine.graph.knowledge_base.warm_default_limit', 25))
        ));

        return array_map(function (array $profile) use ($collections): array {
            return [
                'query' => (string) ($profile['query'] ?? ''),
                'collections' => $collections !== [] ? $collections : (array) ($profile['collections'] ?? []),
                'signals' => (array) ($profile['signals'] ?? []),
            ];
        }, $profiles);
    }

    /**
     * @return array<string, string>
     */
    protected function warmScope(): array
    {
        return array_filter([
            'canonical_user_id' => $this->option('canonical-user-id') ?: null,
            'user_email_normalized' => $this->option('email') ? strtolower(trim((string) $this->option('email'))) : null,
        ], static fn ($value) => $value !== null && $value !== '');
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
