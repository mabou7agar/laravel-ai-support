<?php

namespace LaravelAIEngine\Tests\Unit\Node;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeAuthService;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Illuminate\Support\Collection;
use Mockery;
use Orchestra\Testbench\TestCase;

class NodeForwarderTest extends TestCase
{
    protected $mockCB;
    protected $mockRegistry;
    protected NodeForwarder $forwarder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.nodes.forwarding.max_retries', 1);
        $this->app['config']->set('ai-engine.nodes.forwarding.backoff_base_ms', 1); // fast for tests
        $this->app['config']->set('ai-engine.nodes.request_timeout', 5);
        $this->app['config']->set('ai-engine.nodes.verify_ssl', false);

        // Mock NodeAuthService so NodeHttpClient doesn't need real JWT
        $mockAuth = Mockery::mock(NodeAuthService::class);
        $mockAuth->shouldReceive('generateToken')->andReturn('test-jwt-token');
        $this->app->instance(NodeAuthService::class, $mockAuth);

        $this->mockCB = Mockery::mock(CircuitBreakerService::class);
        $this->mockRegistry = Mockery::mock(NodeRegistryService::class);

        $this->forwarder = new NodeForwarder($this->mockCB, $this->mockRegistry);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeNode(string $slug = 'test-node', array $extra = []): AINode
    {
        $node = Mockery::mock(AINode::class)->makePartial();
        $node->shouldReceive('getAttribute')->with('id')->andReturn($extra['id'] ?? rand(1, 9999));
        $node->shouldReceive('getAttribute')->with('slug')->andReturn($slug);
        $node->shouldReceive('getAttribute')->with('name')->andReturn($extra['name'] ?? ucfirst($slug));
        $node->shouldReceive('getAttribute')->with('url')->andReturn($extra['url'] ?? "http://{$slug}.local");
        $node->shouldReceive('getAttribute')->with('type')->andReturn('child');
        $node->shouldReceive('getAttribute')->with('status')->andReturn('active');
        $node->shouldReceive('getAttribute')->with('collections')->andReturn($extra['collections'] ?? []);
        $node->shouldReceive('getApiUrl')->andReturnUsing(fn($ep) => "http://{$slug}.local/api/ai-engine/{$ep}");
        $node->shouldReceive('incrementConnections')->andReturnNull();
        $node->shouldReceive('decrementConnections')->andReturnNull();
        $node->shouldReceive('isHealthy')->andReturn(true);
        $node->shouldReceive('isRateLimited')->andReturn(false);
        return $node;
    }

    // ──────────────────────────────────────────────
    //  isAvailable()
    // ──────────────────────────────────────────────

    public function test_is_available_when_healthy(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->with($node)->andReturn(false);

        $this->assertTrue($this->forwarder->isAvailable($node));
    }

    public function test_is_not_available_when_circuit_open(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->with($node)->andReturn(true);

        $this->assertFalse($this->forwarder->isAvailable($node));
    }

    public function test_is_not_available_when_unhealthy(): void
    {
        $node = Mockery::mock(AINode::class)->makePartial();
        $node->shouldReceive('isHealthy')->andReturn(false);
        $this->mockCB->shouldReceive('isOpen')->with($node)->andReturn(false);

        $this->assertFalse($this->forwarder->isAvailable($node));
    }

    // ──────────────────────────────────────────────
    //  forwardChat() — success
    // ──────────────────────────────────────────────

    public function test_forward_chat_success(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordSuccess');
        $this->mockCB->shouldReceive('recordFailure');

        Http::fake([
            '*' => Http::response([
                'response' => 'Hello from remote node',
                'metadata' => ['entity_ids' => [1, 2]],
            ], 200),
        ]);

        $result = $this->forwarder->forwardChat($node, 'hello', 'session-1');

        $this->assertTrue($result['success']);
        $this->assertSame('test-node', $result['node']);
        $this->assertSame('Hello from remote node', $result['response']);
    }

    // ──────────────────────────────────────────────
    //  forwardChat() — retry on failure
    // ──────────────────────────────────────────────

    public function test_forward_chat_retries_on_failure(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordFailure');
        $this->mockCB->shouldReceive('recordSuccess');

        $callCount = 0;
        Http::fake(function () use (&$callCount) {
            $callCount++;
            if ($callCount <= 1) {
                return Http::response(['error' => 'timeout'], 500);
            }
            return Http::response([
                'response' => 'Success on retry',
                'metadata' => [],
            ], 200);
        });

        $result = $this->forwarder->forwardChat($node, 'hello', 'session-1');

        // First attempt fails (500), retry succeeds
        $this->assertTrue($result['success'], 'Expected success after retry. Call count: ' . $callCount);
        $this->assertSame('Success on retry', $result['response']);
    }

    // ──────────────────────────────────────────────
    //  forwardChat() — failover
    // ──────────────────────────────────────────────

    public function test_forward_chat_failover_to_alternate_node(): void
    {
        $primaryNode = $this->makeNode('primary', ['id' => 1]);
        $altNode = $this->makeNode('alternate', ['id' => 2]);

        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordFailure');
        $this->mockCB->shouldReceive('recordSuccess');

        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$primaryNode, $altNode]));
        $this->mockRegistry->shouldReceive('nodeOwnsCollection')
            ->andReturnUsing(function ($node, $collection) use ($altNode) {
                return $node === $altNode;
            });

        $callCount = 0;
        Http::fake(function ($request) use (&$callCount) {
            $callCount++;
            $url = $request->url();
            // Primary node always fails, alternate succeeds
            if (str_contains($url, 'primary')) {
                return Http::response(['error' => 'down'], 500);
            }
            return Http::response([
                'response' => 'Failover success',
                'metadata' => [],
            ], 200);
        });

        $result = $this->forwarder->forwardChat(
            $primaryNode, 'hello', 'session-1', [], null, 'App\\Models\\Invoice'
        );

        $this->assertTrue($result['success'], 'Expected failover success');
        $this->assertSame('alternate', $result['node']);
        $this->assertSame('primary', $result['failover_from']);
    }

    // ──────────────────────────────────────────────
    //  forwardSearch() — success
    // ──────────────────────────────────────────────

    public function test_forward_search_success(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordSuccess');
        $this->mockCB->shouldReceive('recordFailure');

        Http::fake([
            '*' => Http::response([
                'results' => [
                    ['id' => 1, 'content' => 'Invoice #1'],
                    ['id' => 2, 'content' => 'Invoice #2'],
                ],
            ], 200),
        ]);

        $result = $this->forwarder->forwardSearch(
            $node, 'invoices', ['App\\Models\\Invoice'], 10
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['count']);
    }

    // ──────────────────────────────────────────────
    //  forwardAction() — success
    // ──────────────────────────────────────────────

    public function test_forward_action_success(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordSuccess');
        $this->mockCB->shouldReceive('recordFailure');

        Http::fake([
            '*' => Http::response([
                'status' => 'ok',
                'data' => ['id' => 42],
            ], 200),
        ]);

        $result = $this->forwarder->forwardAction($node, 'create_invoice', ['amount' => 100]);

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status_code']);
    }

    // ──────────────────────────────────────────────
    //  forwardAction() — no failover for actions
    // ──────────────────────────────────────────────

    public function test_forward_action_no_failover(): void
    {
        $node = $this->makeNode();
        $this->mockCB->shouldReceive('isOpen')->andReturn(true); // circuit open, no retry
        $this->mockCB->shouldReceive('recordFailure');
        $this->mockCB->shouldReceive('recordSuccess');

        Http::fake([
            '*' => Http::response(['error' => 'down'], 500),
        ]);

        $result = $this->forwarder->forwardAction($node, 'create_invoice', []);

        $this->assertFalse($result['success']);
    }
}
