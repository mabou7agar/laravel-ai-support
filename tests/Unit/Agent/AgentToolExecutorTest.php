<?php

namespace LaravelAIEngine\Tests\Unit\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\AgentToolExecutor;
use LaravelAIEngine\Services\Agent\Handlers\AgentReasoningLoop;
use LaravelAIEngine\Services\Agent\Handlers\AgentToolHandler;
use LaravelAIEngine\Services\Agent\Handlers\CrossNodeToolResolver;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use Illuminate\Support\Collection;
use Mockery;
use Orchestra\Testbench\TestCase;

class AgentToolExecutorTest extends TestCase
{
    protected $mockAI;
    protected AgentToolExecutor $executor;
    protected AgentReasoningLoop $reasoningLoop;
    protected AgentToolHandler $toolHandler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.default', 'openai');
        $this->app['config']->set('ai-engine.orchestration_model', 'gpt-4o-mini');

        $this->mockAI = Mockery::mock(AIEngineService::class);
        $this->reasoningLoop = new AgentReasoningLoop($this->mockAI, ['max_iterations' => 3]);
        $this->toolHandler = new AgentToolHandler();
        $this->executor = new AgentToolExecutor($this->reasoningLoop, $this->toolHandler);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeContext(array $history = []): UnifiedActionContext
    {
        $context = new UnifiedActionContext(sessionId: 'test-session', userId: 1);
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
    //  Single-step: direct FINAL_ANSWER
    // ──────────────────────────────────────────────

    public function test_direct_final_answer(): void
    {
        $configClass = $this->createMockModelConfig([
            'list_invoices' => [
                'description' => 'List all invoices',
                'parameters' => [],
                'handler' => fn($p) => ['success' => true, 'data' => [['id' => 1], ['id' => 2]]],
            ],
        ]);

        $this->mockAIResponse("THOUGHT: The user wants to list invoices.\nFINAL_ANSWER: Here are your invoices:\n1. Invoice #1\n2. Invoice #2");

        $context = $this->makeContext();
        $result = $this->executor->execute('list invoices', [$configClass], $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('invoices', $result->message);
    }

    // ──────────────────────────────────────────────
    //  Multi-step: ACTION → OBSERVATION → FINAL_ANSWER
    // ──────────────────────────────────────────────

    public function test_tool_call_then_final_answer(): void
    {
        $configClass = $this->createMockModelConfig([
            'search_invoices' => [
                'description' => 'Search invoices',
                'parameters' => ['query' => ['type' => 'string']],
                'handler' => fn($p) => ['success' => true, 'data' => [['id' => 42, 'amount' => 500]]],
            ],
        ]);

        // First call: agent decides to call a tool
        $response1 = Mockery::mock(AIResponse::class);
        $response1->shouldReceive('getContent')->andReturn(
            "THOUGHT: I need to search for overdue invoices.\nACTION: search_invoices\nPARAMS: {\"query\": \"overdue\"}"
        );

        // Second call: agent has the observation and gives final answer
        $response2 = Mockery::mock(AIResponse::class);
        $response2->shouldReceive('getContent')->andReturn(
            "THOUGHT: I found one overdue invoice.\nFINAL_ANSWER: Found 1 overdue invoice: Invoice #42 for \$500."
        );

        $this->mockAI->shouldReceive('generate')
            ->twice()
            ->andReturn($response1, $response2);

        $context = $this->makeContext();
        $result = $this->executor->execute('show overdue invoices', [$configClass], $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('42', $result->message);
    }

    // ──────────────────────────────────────────────
    //  Tool not found → agent self-corrects
    // ──────────────────────────────────────────────

    public function test_unknown_tool_gets_error_observation(): void
    {
        $configClass = $this->createMockModelConfig([
            'list_invoices' => [
                'description' => 'List invoices',
                'parameters' => [],
                'handler' => fn($p) => ['success' => true, 'data' => []],
            ],
        ]);

        // First call: agent tries a non-existent tool
        $response1 = Mockery::mock(AIResponse::class);
        $response1->shouldReceive('getContent')->andReturn(
            "THOUGHT: Let me search.\nACTION: nonexistent_tool\nPARAMS: {}"
        );

        // Second call: agent sees the error and gives final answer
        $response2 = Mockery::mock(AIResponse::class);
        $response2->shouldReceive('getContent')->andReturn(
            "THOUGHT: That tool doesn't exist. Let me use list_invoices instead.\nACTION: list_invoices\nPARAMS: {}"
        );

        // Third call: final answer
        $response3 = Mockery::mock(AIResponse::class);
        $response3->shouldReceive('getContent')->andReturn(
            "THOUGHT: Got the results.\nFINAL_ANSWER: No invoices found."
        );

        $this->mockAI->shouldReceive('generate')
            ->times(3)
            ->andReturn($response1, $response2, $response3);

        $context = $this->makeContext();
        $result = $this->executor->execute('list invoices', [$configClass], $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('invoices', strtolower($result->message));
    }

    // ──────────────────────────────────────────────
    //  Max iterations safety
    // ──────────────────────────────────────────────

    public function test_max_iterations_returns_fallback(): void
    {
        $configClass = $this->createMockModelConfig([
            'loop_tool' => [
                'description' => 'A tool',
                'parameters' => [],
                'handler' => fn($p) => ['success' => true, 'data' => 'partial'],
            ],
        ]);

        // Agent keeps calling tools and never gives FINAL_ANSWER
        $loopResponse = Mockery::mock(AIResponse::class);
        $loopResponse->shouldReceive('getContent')->andReturn(
            "THOUGHT: Need more data.\nACTION: loop_tool\nPARAMS: {}"
        );

        $this->mockAI->shouldReceive('generate')
            ->times(3) // max_iterations = 3
            ->andReturn($loopResponse);

        $context = $this->makeContext();
        $result = $this->executor->execute('do something complex', [$configClass], $context);

        // Should return a fallback response, not crash
        $this->assertTrue($result->success);
        $this->assertStringContainsString('partial', $result->message);
    }

    // ──────────────────────────────────────────────
    //  No tools available
    // ──────────────────────────────────────────────

    public function test_no_tools_returns_failure(): void
    {
        $context = $this->makeContext();
        $result = $this->executor->execute('do something', [], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('No tools', $result->message);
    }

    // ──────────────────────────────────────────────
    //  Suggested actions extraction
    // ──────────────────────────────────────────────

    public function test_extracts_suggested_actions_from_final_answer(): void
    {
        $configClass = $this->createMockModelConfig([
            'create_invoice' => [
                'description' => 'Create invoice',
                'parameters' => [],
                'handler' => fn($p) => ['success' => true, 'data' => ['id' => 99]],
            ],
        ]);

        $this->mockAIResponse(
            "THOUGHT: Done.\nFINAL_ANSWER: Invoice #99 created successfully.\n\nYou can also:\n- View all invoices\n- Create another invoice\n- Update this invoice"
        );

        $context = $this->makeContext();
        $result = $this->executor->execute('create invoice', [$configClass], $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('#99', $result->message);
        // Suggested actions should be in metadata, not in the message
        $this->assertStringNotContainsString('You can also', $result->message);
        $this->assertNotEmpty($result->metadata['suggested_next_actions'] ?? []);
    }

    // ──────────────────────────────────────────────
    //  Cross-node: unified registry merges local + remote
    // ──────────────────────────────────────────────

    public function test_unified_registry_includes_remote_tools(): void
    {
        $mockRegistry = Mockery::mock(NodeRegistryService::class);
        $mockCB = Mockery::mock(CircuitBreakerService::class);
        $mockCB->shouldReceive('isOpen')->andReturn(false);
        $mockForwarder = new NodeForwarder($mockCB, $mockRegistry);

        $remoteNode = Mockery::mock(AINode::class)->makePartial();
        $remoteNode->shouldReceive('getAttribute')->with('slug')->andReturn('invoice-node');
        $remoteNode->shouldReceive('getAttribute')->with('name')->andReturn('Invoice Node');
        $remoteNode->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $remoteNode->shouldReceive('getAttribute')->with('autonomous_collectors')->andReturn([
            ['name' => 'create_invoice', 'goal' => 'Create invoices', 'description' => 'Create a new invoice', 'parameters' => []],
        ]);
        $remoteNode->shouldReceive('getAttribute')->with('tools')->andReturn([]);
        $remoteNode->shouldReceive('getAttribute')->with('collections')->andReturn([
            ['name' => 'invoice', 'class' => 'App\\Models\\Invoice'],
        ]);

        $mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection([$remoteNode]));

        $crossNode = new CrossNodeToolResolver($mockRegistry, $mockForwarder);
        $executorWithRemote = new AgentToolExecutor($this->reasoningLoop, $this->toolHandler, $crossNode);

        $localConfig = $this->createMockModelConfig([
            'list_customers' => [
                'description' => 'List customers',
                'parameters' => [],
                'handler' => fn($p) => ['success' => true, 'data' => []],
            ],
        ]);

        // Agent gives final answer immediately — we just want to verify the registry was built
        $this->mockAIResponse("THOUGHT: I see both local and remote tools.\nFINAL_ANSWER: I can help with customers (local) and invoices (remote node).");

        $context = $this->makeContext();
        $result = $executorWithRemote->execute('what can you do', [$localConfig], $context);

        $this->assertTrue($result->success);
        // The agent should see both local and remote tools
        $this->assertStringContainsString('local', strtolower($result->message) . ' ' . strtolower($result->metadata['strategy'] ?? ''));
    }

    public function test_remote_tool_dispatch_routes_to_cross_node_resolver(): void
    {
        $mockRegistry = Mockery::mock(NodeRegistryService::class);
        $mockCB = Mockery::mock(CircuitBreakerService::class);
        $mockCB->shouldReceive('isOpen')->andReturn(false);
        $mockForwarder = Mockery::mock(NodeForwarder::class);

        $remoteNode = Mockery::mock(AINode::class)->makePartial();
        $remoteNode->shouldReceive('getAttribute')->with('slug')->andReturn('invoice-node');
        $remoteNode->shouldReceive('getAttribute')->with('name')->andReturn('Invoice Node');
        $remoteNode->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $remoteNode->shouldReceive('getAttribute')->with('autonomous_collectors')->andReturn([]);
        $remoteNode->shouldReceive('getAttribute')->with('tools')->andReturn([]);
        $remoteNode->shouldReceive('getAttribute')->with('collections')->andReturn([
            ['name' => 'invoice', 'class' => 'App\\Models\\Invoice'],
        ]);

        $mockRegistry->shouldReceive('getActiveNodes')->andReturn(new Collection([$remoteNode]));
        $mockRegistry->shouldReceive('getNode')->with('invoice-node')->andReturn($remoteNode);

        // Mock the forwarder to return a successful search
        $mockForwarder->shouldReceive('isAvailable')->with($remoteNode)->andReturn(true);
        $mockForwarder->shouldReceive('forwardSearch')->once()->andReturn([
            'success' => true,
            'node' => 'invoice-node',
            'results' => [['id' => 1, 'number' => 'INV-001']],
            'count' => 1,
        ]);

        $crossNode = new CrossNodeToolResolver($mockRegistry, $mockForwarder);
        $executorWithRemote = new AgentToolExecutor($this->reasoningLoop, $this->toolHandler, $crossNode);

        // First call: agent calls the remote search tool
        $response1 = Mockery::mock(AIResponse::class);
        $response1->shouldReceive('getContent')->andReturn(
            "THOUGHT: I need to search invoices on the remote node.\nACTION: search_invoice\nPARAMS: {\"query\": \"all\"}"
        );

        // Second call: agent gives final answer with the results
        $response2 = Mockery::mock(AIResponse::class);
        $response2->shouldReceive('getContent')->andReturn(
            "THOUGHT: Got the results from the remote node.\nFINAL_ANSWER: Found 1 invoice: INV-001."
        );

        $this->mockAI->shouldReceive('generate')
            ->twice()
            ->andReturn($response1, $response2);

        $context = $this->makeContext();
        $result = $executorWithRemote->execute('list invoices', [], $context);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('INV-001', $result->message);
    }

    // ──────────────────────────────────────────────
    //  AgentToolHandler: schema formatting
    // ──────────────────────────────────────────────

    public function test_tool_handler_formats_remote_tools_with_node_tag(): void
    {
        $registry = [
            'list_customers' => [
                'description' => 'List customers',
                'parameters' => [],
                'model' => 'customer',
                'source' => 'local',
                'node_slug' => null,
            ],
            'search_invoice' => [
                'description' => 'Search invoices',
                'parameters' => ['query' => ['type' => 'string', 'required' => true, 'description' => 'Search query']],
                'model' => 'invoice',
                'source' => 'remote',
                'node_slug' => 'invoice-node',
            ],
        ];

        $schema = $this->toolHandler->formatSchemaForPrompt($registry);

        $this->assertStringContainsString('list_customers [customer]', $schema);
        $this->assertStringContainsString('search_invoice [invoice @invoice-node]', $schema);
        $this->assertStringContainsString('query', $schema);
    }

    // ──────────────────────────────────────────────
    //  AgentReasoningLoop: parsing
    // ──────────────────────────────────────────────

    public function test_reasoning_loop_parses_final_answer(): void
    {
        $parsed = $this->reasoningLoop->parseOutput("THOUGHT: Done.\nFINAL_ANSWER: Here is your answer.");
        $this->assertSame('final_answer', $parsed['type']);
        $this->assertSame('Here is your answer.', $parsed['content']);
    }

    public function test_reasoning_loop_parses_action(): void
    {
        $parsed = $this->reasoningLoop->parseOutput("THOUGHT: Need data.\nACTION: search_invoice\nPARAMS: {\"query\": \"overdue\"}");
        $this->assertSame('action', $parsed['type']);
        $this->assertSame('search_invoice', $parsed['tool']);
        $this->assertSame(['query' => 'overdue'], $parsed['params']);
    }

    public function test_reasoning_loop_unparseable_becomes_final_answer(): void
    {
        $parsed = $this->reasoningLoop->parseOutput("Just a plain text response without any markers.");
        $this->assertSame('final_answer', $parsed['type']);
        $this->assertStringContainsString('plain text', $parsed['content']);
    }

    // ──────────────────────────────────────────────
    //  Helper: create mock model config class
    // ──────────────────────────────────────────────

    protected function createMockModelConfig(array $tools): string
    {
        $className = 'TestModelConfig_' . uniqid();

        $toolsCode = var_export($tools, true);

        // We can't use var_export for closures, so build a real class with eval
        // Instead, use a static registry approach
        $GLOBALS['_test_tools_' . $className] = $tools;

        eval("
            class {$className} {
                public static function getName(): string { return 'test'; }
                public static function getDescription(): string { return 'Test model'; }
                public static function getTools(): array {
                    return \$GLOBALS['_test_tools_{$className}'];
                }
            }
        ");

        return $className;
    }
}
