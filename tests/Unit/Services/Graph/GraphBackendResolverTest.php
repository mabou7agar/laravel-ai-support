<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphBackendResolver;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphBackendResolverTest extends UnitTestCase
{
    public function test_resolver_falls_back_to_vector_driver_when_neo4j_is_not_fully_configured(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://localhost:7474');
        config()->set('ai-engine.graph.neo4j.database', 'neo4j');
        config()->set('ai-engine.graph.neo4j.username', 'neo4j');
        config()->set('ai-engine.graph.neo4j.password', '');
        config()->set('ai-engine.vector.default_driver', 'qdrant');

        $resolver = app(GraphBackendResolver::class);

        $this->assertTrue($resolver->graphEnabledRequested());
        $this->assertTrue($resolver->centralGraphRequested());
        $this->assertFalse($resolver->neo4jConfigured());
        $this->assertFalse($resolver->graphReadPathActive());
        $this->assertSame('vector_qdrant', $resolver->effectiveReadBackend());
        $this->assertSame('neo4j_not_configured', $resolver->fallbackReason());
    }

    public function test_resolver_activates_neo4j_when_graph_is_requested_and_configured(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://localhost:7474');
        config()->set('ai-engine.graph.neo4j.database', 'neo4j');
        config()->set('ai-engine.graph.neo4j.username', 'neo4j');
        config()->set('ai-engine.graph.neo4j.password', 'secret');

        $resolver = app(GraphBackendResolver::class);

        $this->assertTrue($resolver->neo4jConfigured());
        $this->assertTrue($resolver->graphReadPathActive());
        $this->assertSame('neo4j_graph', $resolver->effectiveReadBackend());
        $this->assertNull($resolver->fallbackReason());
    }
}
