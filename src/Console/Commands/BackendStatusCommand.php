<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Graph\GraphBackendResolver;

class BackendStatusCommand extends Command
{
    protected $signature = 'ai-engine:backend-status';

    protected $description = 'Show the currently configured vector and graph retrieval backends';

    public function handle(GraphBackendResolver $resolver): int
    {
        $graphEnabled = $resolver->graphEnabledRequested();
        $graphBackend = $resolver->graphBackend();
        $preferCentralGraph = $resolver->centralGraphRequested();
        $neo4jConfigured = $resolver->neo4jConfigured();
        $vectorDriver = $resolver->vectorDefaultDriver();
        $effectiveReadBackend = $resolver->effectiveReadBackend();
        $fallbackReason = $resolver->fallbackReason();

        $this->table(['Setting', 'Value'], [
            ['effective_read_backend', $effectiveReadBackend],
            ['graph_enabled', $graphEnabled ? 'true' : 'false'],
            ['graph_backend', $graphBackend],
            ['graph_reads_prefer_central', $preferCentralGraph ? 'true' : 'false'],
            ['neo4j_configured', $neo4jConfigured ? 'true' : 'false'],
            ['fallback_reason', $fallbackReason ?? '(none)'],
            ['vector_default_driver', $vectorDriver],
            ['neo4j_url', $this->configured('ai-engine.graph.neo4j.url')],
            ['neo4j_database', $this->configured('ai-engine.graph.neo4j.database')],
            ['qdrant_host', $this->configured('ai-engine.vector.drivers.qdrant.host')],
        ]);

        $this->newLine();
        $this->line('Interpretation:');
        $this->line(sprintf('- Reads currently prefer: %s', $effectiveReadBackend));
        $this->line(sprintf('- Graph path is %s.', $graphEnabled ? 'enabled' : 'disabled'));
        if ($fallbackReason !== null) {
            $this->line(sprintf('- Neo4j fallback reason: %s.', $fallbackReason));
        }
        $this->line(sprintf('- Vector default driver is %s.', $vectorDriver));

        return self::SUCCESS;
    }

    protected function configured(string $key): string
    {
        $value = config($key);

        if ($value === null || $value === '') {
            return '(not set)';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '(complex)';
    }
}
