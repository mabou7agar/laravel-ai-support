<?php

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeOwnershipResolver;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use PHPUnit\Framework\TestCase;

class NodeOwnershipResolverTest extends TestCase
{
    public function test_resolve_for_collection_checks_normalized_candidates(): void
    {
        $node = new AINode();
        $node->slug = 'billing';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->expects($this->exactly(3))
            ->method('findNodeForCollection')
            ->willReturnCallback(function (string $candidate) use ($node) {
                return $candidate === 'invoice' ? $node : null;
            });

        $resolver = new NodeOwnershipResolver($registry);

        $resolved = $resolver->resolveForCollection('App\\Models\\Invoice');

        $this->assertSame($node, $resolved);
    }

    public function test_resolve_for_collections_returns_first_owned_node(): void
    {
        $node = new AINode();
        $node->slug = 'crm';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('findNodeForCollection')
            ->willReturnCallback(function (string $candidate) use ($node) {
                return in_array($candidate, ['contact', 'contacts'], true) ? $node : null;
            });

        $resolver = new NodeOwnershipResolver($registry);

        $resolved = $resolver->resolveForCollections(['invoice', 'contact']);

        $this->assertSame($node, $resolved);
    }
}
