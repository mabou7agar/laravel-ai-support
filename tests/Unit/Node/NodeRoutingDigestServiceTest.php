<?php

namespace LaravelAIEngine\Tests\Unit\Node;

use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRoutingDigestService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Orchestra\Testbench\TestCase;

class NodeRoutingDigestServiceTest extends TestCase
{
    protected NodeRoutingDigestService $service;
    protected $mockRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.nodes.digest_mode', 'template');
        $this->app['config']->set('ai-engine.nodes.digest_cache_ttl_minutes', 60);

        $this->mockRegistry = Mockery::mock(NodeRegistryService::class);
        $this->service = new NodeRoutingDigestService($this->mockRegistry);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeNode(array $attrs = []): AINode
    {
        $node = new AINode();
        $node->forceFill(array_merge([
            'id' => rand(1, 9999),
            'slug' => 'test-node',
            'name' => 'Test Node',
            'type' => 'child',
            'url' => 'http://test-node.local',
            'status' => 'active',
            'collections' => [
                ['name' => 'invoice', 'class' => 'App\\Models\\Invoice'],
                ['name' => 'payment', 'class' => 'App\\Models\\Payment'],
            ],
            'domains' => ['finance', 'accounting'],
            'capabilities' => ['search', 'rag'],
            'autonomous_collectors' => [
                ['name' => 'create_invoice', 'goal' => 'create invoices'],
            ],
            'workflows' => [],
            'keywords' => [],
        ], $attrs));
        return $node;
    }

    // ──────────────────────────────────────────────
    //  Template digest
    // ──────────────────────────────────────────────

    public function test_template_digest_contains_slug_and_collections(): void
    {
        $node = $this->makeNode();
        $digest = $this->service->getNodeDigest($node);

        $this->assertStringContainsString('test-node', $digest);
        $this->assertStringContainsString('invoice', $digest);
        $this->assertStringContainsString('payment', $digest);
    }

    public function test_template_digest_contains_domains(): void
    {
        $node = $this->makeNode();
        $digest = $this->service->getNodeDigest($node);

        $this->assertStringContainsString('finance', $digest);
    }

    public function test_template_digest_contains_actions(): void
    {
        $node = $this->makeNode();
        $digest = $this->service->getNodeDigest($node);

        $this->assertStringContainsString('create invoices', $digest);
    }

    // ──────────────────────────────────────────────
    //  Full digest
    // ──────────────────────────────────────────────

    public function test_full_digest_includes_remote_and_local(): void
    {
        $node = $this->makeNode(['slug' => 'invoicing-node']);
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$node]));

        $localMeta = [
            'slug' => 'local',
            'description' => 'Local node',
            'collections' => [['name' => 'email']],
            'domains' => ['communication'],
        ];

        $digest = $this->service->getFullDigest($localMeta);

        $this->assertStringContainsString('REMOTE NODES:', $digest);
        $this->assertStringContainsString('invoicing-node', $digest);
        $this->assertStringContainsString('LOCAL NODE:', $digest);
        $this->assertStringContainsString('email', $digest);
    }

    public function test_full_digest_no_nodes(): void
    {
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection());

        $digest = $this->service->getFullDigest(null);
        $this->assertStringContainsString('No nodes available', $digest);
    }

    // ──────────────────────────────────────────────
    //  Caching
    // ──────────────────────────────────────────────

    public function test_digest_is_cached(): void
    {
        $node = $this->makeNode();
        $digest1 = $this->service->getNodeDigest($node);
        $digest2 = $this->service->getNodeDigest($node);

        $this->assertSame($digest1, $digest2);
    }

    public function test_refresh_invalidates_cache(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$node]));

        $digest1 = $this->service->getNodeDigest($node);

        // Change node data
        $node->domains = ['healthcare'];
        $digest2 = $this->service->refreshNodeDigest($node);

        $this->assertNotSame($digest1, $digest2);
        $this->assertStringContainsString('healthcare', $digest2);
    }

    // ──────────────────────────────────────────────
    //  Local digest
    // ──────────────────────────────────────────────

    public function test_local_digest_from_metadata(): void
    {
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection());

        $localMeta = [
            'slug' => 'main-app',
            'description' => 'Main application',
            'collections' => [
                ['name' => 'customer'],
                ['name' => 'order'],
            ],
            'domains' => ['crm', 'e-commerce'],
        ];

        $digest = $this->service->getFullDigest($localMeta);

        $this->assertStringContainsString('main-app', $digest);
        $this->assertStringContainsString('customer', $digest);
        $this->assertStringContainsString('order', $digest);
    }
}
