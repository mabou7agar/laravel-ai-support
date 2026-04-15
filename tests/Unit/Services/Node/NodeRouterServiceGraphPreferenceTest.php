<?php

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeOwnershipResolver;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;
use LaravelAIEngine\Tests\UnitTestCase;

class NodeRouterServiceGraphPreferenceTest extends UnitTestCase
{
    public function test_route_stays_local_when_central_graph_reads_are_enabled(): void
    {
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);

        $resolver = $this->createMock(NodeOwnershipResolver::class);
        $resolver->expects($this->never())->method('resolveForCollections');

        $service = new NodeRouterService(
            $this->createMock(NodeRegistryService::class),
            $this->createMock(CircuitBreakerService::class),
            $resolver
        );

        $result = $service->route('list invoices', ['invoice']);

        $this->assertTrue($result['is_local']);
        $this->assertSame('Central graph read model is enabled for retrieval', $result['reason']);
    }
}
