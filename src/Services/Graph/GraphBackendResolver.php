<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Graph;

use LaravelAIEngine\Services\Vector\VectorDriverManager;

class GraphBackendResolver
{
    public function __construct(
        protected ?VectorDriverManager $vectorDriverManager = null
    ) {
        if ($this->vectorDriverManager === null && app()->bound(VectorDriverManager::class)) {
            $this->vectorDriverManager = app(VectorDriverManager::class);
        }
    }

    public function graphEnabledRequested(): bool
    {
        return (bool) config('ai-engine.graph.enabled', false);
    }

    public function graphBackend(): string
    {
        return (string) config('ai-engine.graph.backend', 'neo4j');
    }

    public function centralGraphRequested(): bool
    {
        return $this->graphEnabledRequested()
            && $this->graphBackend() === 'neo4j'
            && (bool) config('ai-engine.graph.reads_prefer_central_graph', false);
    }

    public function neo4jConfigured(): bool
    {
        $url = trim((string) config('ai-engine.graph.neo4j.url', ''));
        $database = trim((string) config('ai-engine.graph.neo4j.database', ''));
        $username = trim((string) config('ai-engine.graph.neo4j.username', ''));
        $password = trim((string) config('ai-engine.graph.neo4j.password', ''));

        return $url !== '' && $database !== '' && $username !== '' && $password !== '';
    }

    public function graphReadPathActive(): bool
    {
        return $this->centralGraphRequested() && $this->neo4jConfigured();
    }

    public function effectiveReadBackend(): string
    {
        if ($this->graphReadPathActive()) {
            return 'neo4j_graph';
        }

        return sprintf('vector_%s', $this->vectorDefaultDriver());
    }

    public function vectorDefaultDriver(): string
    {
        return $this->vectorDriverManager?->getDefaultDriver()
            ?: (string) config('ai-engine.vector.default_driver', 'qdrant');
    }

    public function fallbackReason(): ?string
    {
        if (! $this->centralGraphRequested()) {
            return null;
        }

        if (! $this->neo4jConfigured()) {
            return 'neo4j_not_configured';
        }

        return null;
    }
}
