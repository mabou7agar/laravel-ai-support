<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService;

class Neo4jKnowledgeBaseBuildCommand extends Command
{
    protected $signature = 'ai:neo4j-kb-build
                            {--canonical-user-id= : Explicit canonical user id for scoped KB building}
                            {--email= : Explicit normalized user email for scoped KB building}
                            {--user= : Authenticated user id for scoped result warming}
                            {--profiles-limit=25 : Maximum number of profiled queries to warm}
                            {--entity-limit=25 : Maximum number of entity snapshots to build}
                            {--max-results=5 : Maximum results per warmed query}
                            {--skip-profiles : Skip warming query profiles}
                            {--skip-entities : Skip building hot entity snapshots}
                            {--plan-only : Warm plans without warming retrieval results}
                            {--url=}
                            {--database=}
                            {--username=}
                            {--password=}
                            {--timeout=}';

    protected $description = 'Build the central graph knowledge base for background acceleration and repeated chat speedups';

    public function handle(): int
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);

        $this->applyOverrides();

        $builder = app(GraphKnowledgeBaseBuilderService::class);
        $scope = $this->scope();
        $profilesLimit = max(1, (int) $this->option('profiles-limit'));
        $entityLimit = max(1, (int) $this->option('entity-limit'));
        $maxResults = max(1, (int) $this->option('max-results'));
        $userId = ($this->option('user') !== null && $this->option('user') !== '') ? (int) $this->option('user') : null;
        $planOnly = (bool) $this->option('plan-only');

        $planCount = 0;
        $resultCount = 0;
        $snapshotCount = 0;

        if (!(bool) $this->option('skip-profiles')) {
            $warmed = $builder->warmFromProfiles($scope, $profilesLimit, $maxResults, $planOnly, $userId);
            $planCount = $warmed['plans'];
            $resultCount = $warmed['results'];
        }

        if (!(bool) $this->option('skip-entities')) {
            $built = $builder->buildEntitySnapshots($scope, $entityLimit);
            $snapshotCount = $built['snapshots'];
        }

        $this->table(['Artifact', 'Count'], [
            ['plans_warmed', $planCount],
            ['results_warmed', $resultCount],
            ['entity_snapshots', $snapshotCount],
        ]);

        $this->info('Graph knowledge-base build completed.');

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
