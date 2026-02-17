<?php

namespace LaravelAIEngine\Tests\Unit\Node;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\RoutedSessionPolicyService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Illuminate\Support\Collection;
use Mockery;
use Orchestra\Testbench\TestCase;

class RoutedSessionPolicyServiceTest extends TestCase
{
    protected $mockAI;
    protected $mockRegistry;
    protected RoutedSessionPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.default', 'openai');
        $this->app['config']->set('ai-engine.orchestration_model', 'gpt-4o-mini');

        $this->mockAI = Mockery::mock(AIEngineService::class);
        $this->mockRegistry = Mockery::mock(NodeRegistryService::class);

        $this->service = new RoutedSessionPolicyService(
            $this->mockAI,
            $this->mockRegistry,
            ['history_window' => 3]
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeNode(string $slug = 'invoicing-node', array $extra = []): AINode
    {
        $node = new AINode();
        $node->forceFill(array_merge([
            'id' => rand(1, 9999),
            'slug' => $slug,
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'type' => 'child',
            'url' => "http://{$slug}.local",
            'status' => 'active',
            'collections' => [['name' => 'invoice', 'class' => 'App\\Models\\Invoice']],
            'domains' => ['finance', 'accounting'],
            'capabilities' => ['search'],
            'autonomous_collectors' => [],
            'workflows' => [],
            'keywords' => [],
        ], $extra));
        return $node;
    }

    protected function makeContext(string $nodeSlug = 'invoicing-node', array $history = []): UnifiedActionContext
    {
        $context = new UnifiedActionContext(
            sessionId: 'test-session',
            userId: 1
        );
        $context->set('routed_to_node', [
            'node_slug' => $nodeSlug,
            'node_name' => ucfirst(str_replace('-', ' ', $nodeSlug)),
        ]);
        $context->conversationHistory = $history;
        return $context;
    }

    protected function mockAIResponse(string $content): void
    {
        $response = Mockery::mock(AIResponse::class);
        $response->shouldReceive('getContent')->andReturn($content);
        $this->mockAI->shouldReceive('generate')->once()->andReturn($response);
    }

    // ──────────────────────────────────────────────
    //  Fast-path: short follow-ups (no AI call)
    // ──────────────────────────────────────────────

    public function test_number_is_follow_up(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);

        $context = $this->makeContext();
        $result = $this->service->evaluate('1', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
        $this->assertSame('invoicing-node', $result['node_slug']);
        $this->assertStringContainsString('follow-up', $result['reason']);
    }

    public function test_yes_is_follow_up(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);

        $context = $this->makeContext();
        $result = $this->service->evaluate('yes', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
    }

    public function test_next_page_is_follow_up(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);

        $context = $this->makeContext();
        $result = $this->service->evaluate('next page', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
    }

    public function test_ordinal_is_follow_up(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);

        $context = $this->makeContext();
        $result = $this->service->evaluate('the second one', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
    }

    // ──────────────────────────────────────────────
    //  AI-based: CONTINUE
    // ──────────────────────────────────────────────

    public function test_ai_continue(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAIResponse('CONTINUE');

        $context = $this->makeContext('invoicing-node', [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'Here are your invoices...'],
        ]);

        $result = $this->service->evaluate('show me the overdue ones', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
        $this->assertSame('invoicing-node', $result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  AI-based: RE_ROUTE
    // ──────────────────────────────────────────────

    public function test_ai_reroute(): void
    {
        $invoiceNode = $this->makeNode('invoicing-node');
        $emailNode = $this->makeNode('email-node', [
            'collections' => [['name' => 'email', 'class' => 'App\\Models\\Email']],
            'domains' => ['communication'],
        ]);

        $this->mockRegistry->shouldReceive('getNode')
            ->with('invoicing-node')->andReturn($invoiceNode);
        $this->mockRegistry->shouldReceive('getNode')
            ->with('email-node')->andReturn($emailNode);
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$invoiceNode, $emailNode]));

        $this->mockAIResponse('RE_ROUTE:email-node');

        $context = $this->makeContext('invoicing-node', [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'Here are your invoices...'],
        ]);

        $result = $this->service->evaluate('list my emails', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_RE_ROUTE, $result['action']);
        $this->assertSame('email-node', $result['node_slug']);
    }

    public function test_ai_reroute_unknown_slug_falls_to_local(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')
            ->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getNode')
            ->with('nonexistent-node')->andReturn(null);
        $this->mockRegistry->shouldReceive('getActiveNodes')
            ->andReturn(new Collection([$node]));

        $this->mockAIResponse('RE_ROUTE:nonexistent-node');

        $context = $this->makeContext();
        $result = $this->service->evaluate('show me something weird', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
        $this->assertNull($result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  AI-based: LOCAL
    // ──────────────────────────────────────────────

    public function test_ai_local(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAIResponse('LOCAL');

        $context = $this->makeContext();
        $result = $this->service->evaluate('what is the weather today', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
        $this->assertNull($result['node_slug']);
    }

    // ──────────────────────────────────────────────
    //  Legacy compatibility
    // ──────────────────────────────────────────────

    public function test_legacy_related_maps_to_continue(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAIResponse('RELATED');

        $context = $this->makeContext();
        $result = $this->service->evaluate('show invoice details', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
    }

    public function test_legacy_different_maps_to_local(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAIResponse('DIFFERENT');

        $context = $this->makeContext();
        $result = $this->service->evaluate('list emails', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
    }

    // ──────────────────────────────────────────────
    //  Backward-compatible shouldContinue()
    // ──────────────────────────────────────────────

    public function test_should_continue_true_on_follow_up(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);

        $context = $this->makeContext();
        $this->assertTrue($this->service->shouldContinue('1', $context));
    }

    public function test_should_continue_false_on_local(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAIResponse('LOCAL');

        $context = $this->makeContext();
        $this->assertFalse($this->service->shouldContinue('what is the weather', $context));
    }

    // ──────────────────────────────────────────────
    //  Edge cases
    // ──────────────────────────────────────────────

    public function test_no_routed_node_returns_local(): void
    {
        $context = new UnifiedActionContext(sessionId: 'test', userId: 1);
        $result = $this->service->evaluate('hello', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
    }

    public function test_node_not_found_returns_local(): void
    {
        $this->mockRegistry->shouldReceive('getNode')->with('gone-node')->andReturn(null);

        $context = $this->makeContext('gone-node');
        $result = $this->service->evaluate('hello', $context);

        $this->assertSame(RoutedSessionPolicyService::DECISION_LOCAL, $result['action']);
    }

    public function test_ai_failure_defaults_to_continue(): void
    {
        $node = $this->makeNode();
        $this->mockRegistry->shouldReceive('getNode')->with('invoicing-node')->andReturn($node);
        $this->mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection());

        $this->mockAI->shouldReceive('generate')->once()->andThrow(new \Exception('AI down'));

        $context = $this->makeContext();
        $result = $this->service->evaluate('some ambiguous message here', $context);

        // Default fallback is CONTINUE
        $this->assertSame(RoutedSessionPolicyService::DECISION_CONTINUE, $result['action']);
    }
}
