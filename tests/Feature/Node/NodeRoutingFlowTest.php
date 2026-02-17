<?php

namespace LaravelAIEngine\Tests\Feature\Node;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\AgentPolicyService;
use LaravelAIEngine\Services\Agent\NodeRoutingCoordinator;
use LaravelAIEngine\Services\Agent\RoutedSessionPolicyService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeNameMatcher;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRoutingDigestService;
use Mockery;
use Orchestra\Testbench\TestCase;

/**
 * Integration test: simulates a multi-turn conversation that routes to
 * different nodes, follows up, and switches context mid-conversation.
 *
 * Mocks: AIEngineService (LLM calls), HTTP (node forwarding)
 * Real:  RoutedSessionPolicyService, NodeRoutingCoordinator, NodeForwarder,
 *        NodeNameMatcher, NodeRoutingDigestService
 */
class NodeRoutingFlowTest extends TestCase
{
    protected $mockAI;
    protected $mockCB;
    protected $mockRegistry;
    protected NodeForwarder $forwarder;
    protected RoutedSessionPolicyService $routedPolicy;
    protected NodeRoutingCoordinator $coordinator;
    protected NodeRoutingDigestService $digestService;

    protected AINode $invoicingNode;
    protected AINode $emailNode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.default', 'openai');
        $this->app['config']->set('ai-engine.orchestration_model', 'gpt-4o-mini');
        $this->app['config']->set('ai-engine.nodes.forwarding.max_retries', 0);
        $this->app['config']->set('ai-engine.nodes.forwarding.backoff_base_ms', 1);
        $this->app['config']->set('ai-engine.nodes.digest_mode', 'template');
        $this->app['config']->set('ai-engine.nodes.request_timeout', 5);
        $this->app['config']->set('ai-engine.nodes.verify_ssl', false);

        // Mock NodeAuthService so NodeHttpClient doesn't need real JWT
        $mockAuth = Mockery::mock(\LaravelAIEngine\Services\Node\NodeAuthService::class);
        $mockAuth->shouldReceive('generateToken')->andReturn('test-jwt-token');
        $this->app->instance(\LaravelAIEngine\Services\Node\NodeAuthService::class, $mockAuth);

        // Create mock nodes
        $this->invoicingNode = $this->makeNode('invoicing-node', [
            'id' => 1,
            'name' => 'Invoicing Node',
            'collections' => [
                ['name' => 'invoice', 'class' => 'App\\Models\\Invoice'],
                ['name' => 'payment', 'class' => 'App\\Models\\Payment'],
            ],
            'domains' => ['finance', 'accounting'],
            'capabilities' => ['search', 'rag'],
            'autonomous_collectors' => [
                ['name' => 'create_invoice', 'goal' => 'create invoices'],
            ],
        ]);

        $this->emailNode = $this->makeNode('email-node', [
            'id' => 2,
            'name' => 'Email Node',
            'collections' => [
                ['name' => 'email', 'class' => 'App\\Models\\Email'],
            ],
            'domains' => ['communication', 'messaging'],
            'capabilities' => ['search', 'rag'],
            'autonomous_collectors' => [],
        ]);

        // Mock services
        $this->mockAI = Mockery::mock(AIEngineService::class);
        $this->mockCB = Mockery::mock(CircuitBreakerService::class);
        $this->mockCB->shouldReceive('isOpen')->andReturn(false);
        $this->mockCB->shouldReceive('recordSuccess');
        $this->mockCB->shouldReceive('recordFailure');

        $this->mockRegistry = Mockery::mock(NodeRegistryService::class);
        $this->mockRegistry->shouldReceive('getNode')
            ->with('invoicing-node')->andReturn($this->invoicingNode);
        $this->mockRegistry->shouldReceive('getNode')
            ->with('email-node')->andReturn($this->emailNode);
        $this->mockRegistry->shouldReceive('getNode')
            ->andReturnUsing(function ($slug) {
                if ($slug === 'invoicing-node') return $this->invoicingNode;
                if ($slug === 'email-node') return $this->emailNode;
                return null;
            });
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$this->invoicingNode, $this->emailNode]));
        $this->mockRegistry->shouldReceive('getAllNodes')
            ->andReturn(new Collection([$this->invoicingNode, $this->emailNode]));
        $this->mockRegistry->shouldReceive('findNodeForCollection')
            ->andReturn(null);

        // Build real services
        $this->forwarder = new NodeForwarder($this->mockCB, $this->mockRegistry);
        $this->digestService = new NodeRoutingDigestService($this->mockRegistry);
        $this->routedPolicy = new RoutedSessionPolicyService(
            $this->mockAI,
            $this->mockRegistry,
            ['history_window' => 4]
        );
        $this->coordinator = new NodeRoutingCoordinator(
            $this->mockRegistry,
            $this->forwarder,
            new AgentPolicyService()
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeNode(string $slug, array $attrs = []): AINode
    {
        $node = Mockery::mock(AINode::class)->makePartial();
        $node->shouldReceive('getAttribute')->with('id')->andReturn($attrs['id'] ?? rand(1, 9999));
        $node->shouldReceive('getAttribute')->with('slug')->andReturn($slug);
        $node->shouldReceive('getAttribute')->with('name')->andReturn($attrs['name'] ?? ucfirst($slug));
        $node->shouldReceive('getAttribute')->with('url')->andReturn("http://{$slug}.local");
        $node->shouldReceive('getAttribute')->with('type')->andReturn('child');
        $node->shouldReceive('getAttribute')->with('status')->andReturn('active');
        $node->shouldReceive('getAttribute')->with('collections')->andReturn($attrs['collections'] ?? []);
        $node->shouldReceive('getAttribute')->with('domains')->andReturn($attrs['domains'] ?? []);
        $node->shouldReceive('getAttribute')->with('capabilities')->andReturn($attrs['capabilities'] ?? []);
        $node->shouldReceive('getAttribute')->with('autonomous_collectors')->andReturn($attrs['autonomous_collectors'] ?? []);
        $node->shouldReceive('getAttribute')->with('workflows')->andReturn($attrs['workflows'] ?? []);
        $node->shouldReceive('getAttribute')->with('keywords')->andReturn($attrs['keywords'] ?? []);
        $node->shouldReceive('getApiUrl')->andReturnUsing(fn($ep) => "http://{$slug}.local/api/ai-engine/{$ep}");
        $node->shouldReceive('incrementConnections')->andReturnNull();
        $node->shouldReceive('decrementConnections')->andReturnNull();
        $node->shouldReceive('isHealthy')->andReturn(true);
        $node->shouldReceive('isRateLimited')->andReturn(false);
        return $node;
    }

    protected function mockAIResponse(string $content): void
    {
        $response = Mockery::mock(AIResponse::class);
        $response->shouldReceive('getContent')->andReturn($content);
        $this->mockAI->shouldReceive('generate')->once()->andReturn($response);
    }

    // ──────────────────────────────────────────────
    //  Scenario 1: Route → Follow-up → Same node
    // ──────────────────────────────────────────────

    public function test_follow_up_stays_on_same_node(): void
    {
        $context = new UnifiedActionContext(sessionId: 'sess-1', userId: 1);
        $context->set('routed_to_node', [
            'node_slug' => 'invoicing-node',
            'node_name' => 'Invoicing Node',
        ]);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => '1. Invoice #101\n2. Invoice #102\n3. Invoice #103'],
        ];

        // "1" is a short follow-up → fast-path CONTINUE (no AI call needed)
        $result = $this->routedPolicy->evaluate('1', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
        $this->assertSame('invoicing-node', $result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  Scenario 2: Route → Context switch to different node
    // ──────────────────────────────────────────────

    public function test_context_switch_reroutes_to_different_node(): void
    {
        $context = new UnifiedActionContext(sessionId: 'sess-2', userId: 1);
        $context->set('routed_to_node', [
            'node_slug' => 'invoicing-node',
            'node_name' => 'Invoicing Node',
        ]);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'Here are your invoices...'],
        ];

        // AI should detect "list my emails" belongs to email-node
        $this->mockAIResponse('RE_ROUTE:email-node');

        $result = $this->routedPolicy->evaluate('list my emails', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_RE_ROUTE, $result['action']);
        $this->assertSame('email-node', $result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  Scenario 3: Route → Unrelated topic → LOCAL
    // ──────────────────────────────────────────────

    public function test_unrelated_topic_goes_local(): void
    {
        $context = new UnifiedActionContext(sessionId: 'sess-3', userId: 1);
        $context->set('routed_to_node', [
            'node_slug' => 'invoicing-node',
            'node_name' => 'Invoicing Node',
        ]);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'Here are your invoices...'],
        ];

        // AI detects "what's the weather" doesn't match any node
        $this->mockAIResponse('LOCAL');

        $result = $this->routedPolicy->evaluate("what's the weather today", $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
        $this->assertNull($result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  Scenario 4: Node resolution via NodeNameMatcher
    // ──────────────────────────────────────────────

    public function test_node_resolution_by_slug(): void
    {
        $node = $this->coordinator->resolveNodeForRouting('invoicing-node');
        $this->assertNotNull($node);
        $this->assertSame('invoicing-node', $node->slug);
    }

    public function test_node_resolution_by_fuzzy_name(): void
    {
        $node = $this->coordinator->resolveNodeForRouting('Invoicing Node');
        $this->assertNotNull($node);
        $this->assertSame('invoicing-node', $node->slug);
    }

    public function test_node_resolution_unknown_returns_null(): void
    {
        $node = $this->coordinator->resolveNodeForRouting('nonexistent');
        $this->assertNull($node);
    }

    // ──────────────────────────────────────────────
    //  Scenario 5: Routing digest generation
    // ──────────────────────────────────────────────

    public function test_digest_contains_all_nodes(): void
    {
        $localMeta = [
            'slug' => 'local',
            'description' => 'Local app',
            'collections' => [['name' => 'customer']],
            'domains' => ['crm'],
        ];

        $digest = $this->digestService->getFullDigest($localMeta);

        $this->assertStringContainsString('invoicing-node', $digest);
        $this->assertStringContainsString('email-node', $digest);
        $this->assertStringContainsString('local', $digest);
        $this->assertStringContainsString('invoice', $digest);
        $this->assertStringContainsString('email', $digest);
    }

    // ──────────────────────────────────────────────
    //  Scenario 6: Fast-path patterns
    // ──────────────────────────────────────────────

    /**
     * @dataProvider followUpMessages
     */
    public function test_fast_path_follow_ups(string $message): void
    {
        $context = new UnifiedActionContext(sessionId: 'sess-fp', userId: 1);
        $context->set('routed_to_node', [
            'node_slug' => 'invoicing-node',
            'node_name' => 'Invoicing Node',
        ]);

        $result = $this->routedPolicy->evaluate($message, $context);

        $this->assertSame(
            RoutedSessionPolicyService::DECISION_CONTINUE,
            $result['action'],
            "Expected CONTINUE for fast-path message: '{$message}'"
        );
    }

    public static function followUpMessages(): array
    {
        return [
            'number' => ['1'],
            'two digits' => ['42'],
            'yes' => ['yes'],
            'no' => ['no'],
            'ok' => ['ok'],
            'next' => ['next'],
            'next page' => ['next page'],
            'show more' => ['show more'],
            'the first one' => ['the first one'],
            'second' => ['second'],
            'thanks' => ['thanks'],
            'details' => ['details'],
        ];
    }

    // ──────────────────────────────────────────────
    //  Scenario 7: NodeNameMatcher edge cases
    // ──────────────────────────────────────────────

    public function test_name_matcher_class_resolution(): void
    {
        $this->assertTrue(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'invoice'));
        $this->assertTrue(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'invoices'));
        $this->assertFalse(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'email'));
    }

    public function test_name_matcher_scoring(): void
    {
        // Exact match should score highest
        $exact = NodeNameMatcher::score('invoice', 'invoice');
        $plural = NodeNameMatcher::score('invoice', 'invoices');
        $unrelated = NodeNameMatcher::score('invoice', 'weather');

        $this->assertGreaterThan($plural, $exact);
        $this->assertGreaterThan($unrelated, $plural);
        $this->assertSame(0, $unrelated);
    }

    // ──────────────────────────────────────────────
    //  Scenario 8: Forwarding via NodeForwarder
    // ──────────────────────────────────────────────

    public function test_forward_chat_to_node(): void
    {
        Http::fake([
            'invoicing-node.local/*' => Http::response([
                'response' => 'Here are your invoices: 1. INV-001, 2. INV-002',
                'metadata' => ['entity_ids' => [1, 2]],
            ], 200),
        ]);

        $result = $this->forwarder->forwardChat(
            $this->invoicingNode, 'list invoices', 'sess-8', [], 1
        );

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('invoices', $result['response']);
    }
}
