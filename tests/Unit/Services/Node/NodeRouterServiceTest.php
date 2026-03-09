<?php

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeOwnershipResolver;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;
use PHPUnit\Framework\TestCase;

class NodeRouterServiceTest extends TestCase
{
    public function test_route_prefers_explicit_collection_ownership(): void
    {
        $node = $this->getMockBuilder(AINode::class)
            ->onlyMethods(['isHealthy', 'isRateLimited'])
            ->getMock();
        $node->slug = 'billing';
        $node->name = 'Billing';
        $node->type = 'child';
        $node->method('isHealthy')->willReturn(true);
        $node->method('isRateLimited')->willReturn(false);

        $registry = $this->createMock(NodeRegistryService::class);
        $breaker = $this->createMock(CircuitBreakerService::class);
        $breaker->method('isOpen')->with($node)->willReturn(false);

        $resolver = $this->createMock(NodeOwnershipResolver::class);
        $resolver->expects($this->once())
            ->method('resolveForCollections')
            ->with(['invoice'])
            ->willReturn($node);

        $service = new NodeRouterService($registry, $breaker, $resolver);

        $result = $service->route('list invoices', ['invoice']);

        $this->assertFalse($result['is_local']);
        $this->assertSame($node, $result['node']);
    }

    public function test_route_without_explicit_ownership_stays_local(): void
    {
        $service = new NodeRouterService(
            $this->createMock(NodeRegistryService::class),
            $this->createMock(CircuitBreakerService::class),
            $this->createMock(NodeOwnershipResolver::class)
        );

        $result = $service->route('what should i do next');

        $this->assertTrue($result['is_local']);
        $this->assertNull($result['node']);
        $this->assertSame('No explicit node ownership match found', $result['reason']);
    }
}
