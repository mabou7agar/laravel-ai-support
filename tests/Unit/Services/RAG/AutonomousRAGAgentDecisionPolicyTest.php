<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService;
use LaravelAIEngine\Services\RAG\RAGModelDiscovery;
use LaravelAIEngine\Services\RAG\RAGToolDispatcher;
use Mockery;
use Orchestra\Testbench\TestCase;

class AutonomousRAGAgentDecisionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: function calling
    // ──────────────────────────────────────────────

    public function test_function_calling_off_mode_disables_function_calling(): void
    {
        $service = $this->makeDecisionService([
            'function_calling' => 'off',
        ]);

        $result = $service->shouldUseFunctionCalling('gpt-4o-mini', []);
        $this->assertFalse($result);
    }

    public function test_function_calling_auto_mode_uses_supported_models_only(): void
    {
        $service = $this->makeDecisionService([
            'function_calling' => 'auto',
        ]);

        $this->assertTrue($service->shouldUseFunctionCalling('gpt-4o-mini', []));
        $this->assertFalse($service->shouldUseFunctionCalling('claude-3-5-sonnet', []));
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: fallback decisions
    // ──────────────────────────────────────────────

    public function test_fallback_uses_vector_search_as_default(): void
    {
        $service = $this->makeDecisionService([
            'decision_fallback_tool' => 'vector_search',
            'decision_fallback_limit' => 7,
        ]);

        $decision = $this->callProtected($service, 'buildFallbackDecision', [
            'show invoices',
            ['models' => [['name' => 'invoice']]],
            [],
        ]);

        $this->assertSame('vector_search', $decision['tool']);
        $this->assertSame('show invoices', $decision['parameters']['query']);
        $this->assertSame(7, $decision['parameters']['limit']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_fallback_heuristics_detects_aggregate_intent(): void
    {
        $service = $this->makeDecisionService([
            'decision_fallback_tool' => 'db_query',
        ]);

        $decision = $this->callProtected($service, 'buildFallbackDecision', [
            'what is total invoice amount?',
            ['models' => [['name' => 'invoice']]],
            [],
        ]);

        $this->assertSame('db_aggregate', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
        $this->assertSame('sum', $decision['parameters']['aggregate']['operation']);
    }

    public function test_fallback_heuristics_detects_count_intent(): void
    {
        $service = $this->makeDecisionService([]);

        $decision = $this->callProtected($service, 'buildFallbackDecision', [
            'how many invoices are there?',
            ['models' => [['name' => 'invoice']]],
            [],
        ]);

        $this->assertSame('db_count', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    public function test_fallback_heuristics_detects_pagination_intent(): void
    {
        $service = $this->makeDecisionService([]);

        $decision = $this->callProtected($service, 'buildFallbackDecision', [
            'show more',
            ['models' => []],
            [],
        ]);

        $this->assertSame('db_query_next', $decision['tool']);
    }

    public function test_fallback_uses_db_query_when_configured(): void
    {
        $service = $this->makeDecisionService([
            'decision_fallback_tool' => 'db_query',
        ]);

        $decision = $this->callProtected($service, 'buildFallbackDecision', [
            'find something',
            ['models' => [['name' => 'invoice']]],
            [],
        ]);

        $this->assertSame('db_query', $decision['tool']);
        $this->assertSame('invoice', $decision['parameters']['model']);
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: prompt building
    // ──────────────────────────────────────────────

    public function test_prompt_context_includes_entity_data_preview(): void
    {
        $service = $this->makeDecisionService(['context_preview_max_chars' => 2000]);
        $prompt = $this->callProtected($service, 'buildDecisionPrompt', [
            'what about invoice #2?',
            [
                'conversation' => 'Recent conversation...',
                'models' => [['name' => 'invoice']],
                'nodes' => [],
                'last_entity_list' => [
                    'entity_type' => 'invoice',
                    'entity_data' => [
                        ['id' => 1, 'number' => 'INV-001', 'amount' => 100],
                        ['id' => 2, 'number' => 'INV-002', 'amount' => 200],
                    ],
                    'entity_ids' => [1, 2],
                    'start_position' => 1,
                    'end_position' => 2,
                ],
            ],
        ]);

        $this->assertStringContainsString('CURRENTLY VISIBLE', $prompt);
        $this->assertStringContainsString('DATA PREVIEW', $prompt);
        $this->assertStringContainsString('INV-002', $prompt);
    }

    public function test_prompt_includes_model_info(): void
    {
        $service = $this->makeDecisionService([]);
        $prompt = $this->callProtected($service, 'buildDecisionPrompt', [
            'list invoices',
            [
                'conversation' => '(none)',
                'models' => [
                    ['name' => 'invoice', 'description' => 'Invoice records', 'table' => 'invoices'],
                    ['name' => 'customer', 'description' => 'Customer records', 'table' => 'customers'],
                ],
                'nodes' => [],
            ],
        ]);

        $this->assertStringContainsString('invoice', $prompt);
        $this->assertStringContainsString('customer', $prompt);
        $this->assertStringContainsString('list invoices', $prompt);
    }

    public function test_prompt_includes_selected_entity_context(): void
    {
        $service = $this->makeDecisionService([]);
        $prompt = $this->callProtected($service, 'buildDecisionPrompt', [
            'update the status',
            [
                'conversation' => '(none)',
                'models' => [],
                'nodes' => [],
                'selected_entity' => [
                    'id' => 42,
                    'name' => 'Test Invoice',
                    'status' => 'draft',
                ],
            ],
        ]);

        $this->assertStringContainsString('SELECTED ENTITY', $prompt);
        $this->assertStringContainsString('Test Invoice', $prompt);
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: response parsing
    // ──────────────────────────────────────────────

    public function test_parse_valid_json_decision(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'parseDecision', [
            '{"tool": "db_query", "reasoning": "User wants invoices", "parameters": {"model": "invoice"}}',
        ]);

        $this->assertSame('db_query', $result['tool']);
        $this->assertSame('invoice', $result['parameters']['model']);
    }

    public function test_parse_json_with_markdown_fences(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'parseDecision', [
            "```json\n{\"tool\": \"vector_search\", \"parameters\": {\"query\": \"test\"}}\n```",
        ]);

        $this->assertSame('vector_search', $result['tool']);
    }

    public function test_parse_returns_null_for_invalid_json(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'parseDecision', [
            'This is not JSON at all',
        ]);

        $this->assertNull($result);
    }

    public function test_parse_extracts_json_from_mixed_text(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'parseDecision', [
            "I think we should use:\n{\"tool\": \"db_count\", \"parameters\": {\"model\": \"invoice\"}}\nThat's my recommendation.",
        ]);

        $this->assertSame('db_count', $result['tool']);
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: model detection
    // ──────────────────────────────────────────────

    public function test_detect_model_from_message(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'detectModelFromMessage', [
            'show me all invoices',
            ['models' => [['name' => 'invoice'], ['name' => 'customer']]],
        ]);

        $this->assertSame('invoice', $result);
    }

    public function test_detect_model_single_model_shortcut(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'detectModelFromMessage', [
            'show me everything',
            ['models' => [['name' => 'invoice']]],
        ]);

        $this->assertSame('invoice', $result);
    }

    public function test_detect_model_returns_null_when_no_match(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'detectModelFromMessage', [
            'hello world',
            ['models' => [['name' => 'invoice'], ['name' => 'customer']]],
        ]);

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGAgent: delegates to decision service
    // ──────────────────────────────────────────────

    public function test_agent_delegates_decision_to_service(): void
    {
        $decisionService = Mockery::mock(AutonomousRAGDecisionService::class);
        $decisionService->shouldReceive('shouldUseFunctionCalling')
            ->once()
            ->with('gpt-4o-mini', [])
            ->andReturn(false);

        $decisionService->shouldReceive('decide')
            ->once()
            ->with('show invoices', Mockery::type('array'), 'gpt-4o-mini', Mockery::type('array'))
            ->andReturn(['tool' => 'db_query', 'parameters' => ['model' => 'invoice']]);

        $mockModelDiscovery = Mockery::mock(RAGModelDiscovery::class);
        $mockModelDiscovery->shouldReceive('getAvailableModels')->andReturn([]);
        $mockModelDiscovery->shouldReceive('getAvailableNodes')->andReturn([]);

        $mockToolDispatcher = Mockery::mock(RAGToolDispatcher::class);
        $mockToolDispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturn(['success' => true, 'response' => 'Found invoices', 'tool' => 'db_query']);

        $agent = new AutonomousRAGAgent(
            Mockery::mock(AIEngineManager::class),
            $mockModelDiscovery,
            $mockToolDispatcher,
            $decisionService
        );

        $result = $agent->process('show invoices', 'test-session', 1, [], []);

        $this->assertTrue($result['success']);
        $this->assertSame('db_query', $result['tool']);
    }

    // ──────────────────────────────────────────────
    //  AutonomousRAGDecisionService: config helpers
    // ──────────────────────────────────────────────

    public function test_fallback_tool_defaults_to_vector_search(): void
    {
        $service = $this->makeDecisionService([]);

        $result = $this->callProtected($service, 'decisionFallbackTool', [[]]);

        $this->assertSame('vector_search', $result);
    }

    public function test_fallback_limit_clamped_to_range(): void
    {
        $service = $this->makeDecisionService(['decision_fallback_limit' => 100]);

        $result = $this->callProtected($service, 'decisionFallbackLimit', [[]]);

        $this->assertSame(50, $result); // Clamped to max 50
    }

    public function test_function_calling_supports_gpt_models(): void
    {
        $service = $this->makeDecisionService([]);

        $this->assertTrue($this->callProtected($service, 'supportsOpenAIFunctions', ['gpt-4o-mini']));
        $this->assertTrue($this->callProtected($service, 'supportsOpenAIFunctions', ['gpt-4-turbo']));
        $this->assertFalse($this->callProtected($service, 'supportsOpenAIFunctions', ['claude-3-5-sonnet']));
        $this->assertFalse($this->callProtected($service, 'supportsOpenAIFunctions', ['gemini-pro']));
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    protected function makeDecisionService(array $settings): AutonomousRAGDecisionService
    {
        return new AutonomousRAGDecisionService(
            Mockery::mock(AIEngineManager::class),
            $settings
        );
    }

    protected function callProtected(object $instance, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}
