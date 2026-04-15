<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphVectorNamingService;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphVectorNamingServiceTest extends UnitTestCase
{
    public function test_it_uses_static_names_by_default(): void
    {
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'chunk_embedding_index');
        config()->set('ai-engine.graph.neo4j.chunk_vector_property', 'embedding');
        config()->set('ai-engine.graph.neo4j.shared_deployment', false);
        config()->set('ai-engine.graph.neo4j.vector_naming.strategy', '');

        $service = new GraphVectorNamingService();

        $this->assertSame('chunk_embedding_index', $service->indexName());
        $this->assertSame('embedding', $service->propertyName());
    }

    public function test_it_appends_node_slug_for_shared_deployments(): void
    {
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'chunk_embedding_index');
        config()->set('ai-engine.graph.neo4j.chunk_vector_property', 'embedding');
        config()->set('ai-engine.graph.neo4j.shared_deployment', true);
        config()->set('ai-engine.graph.neo4j.vector_naming.strategy', '');
        config()->set('ai-engine.nodes.local.slug', 'billing-app');

        $service = new GraphVectorNamingService();

        $this->assertSame('chunk_embedding_index_billing_app', $service->indexName());
        $this->assertSame('embedding_billing_app', $service->propertyName());
    }

    public function test_it_supports_node_tenant_strategy(): void
    {
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'chunk_embedding_index');
        config()->set('ai-engine.graph.neo4j.chunk_vector_property', 'embedding');
        config()->set('ai-engine.graph.neo4j.vector_naming.strategy', 'node_tenant');
        config()->set('ai-engine.graph.neo4j.vector_naming.node_slug', 'dash');
        config()->set('ai-engine.graph.neo4j.vector_naming.tenant_key', 'tenant-7');

        $service = new GraphVectorNamingService();

        $this->assertSame('chunk_embedding_index_dash_tenant_7', $service->indexName());
        $this->assertSame('embedding_dash_tenant_7', $service->propertyName());
    }
}
