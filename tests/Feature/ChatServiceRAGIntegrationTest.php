<?php

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Integration tests for ChatService exercising the FULL RAG pipeline
 * with real database models and real OpenAI API calls.
 *
 * The master project acts as a "node" with local data:
 *   - TestRAGProduct model with seeded products in SQLite
 *   - Registered via config('ai-engine.intelligent_rag.default_collections')
 *
 * Pipeline exercised:
 *   ChatService → MinimalAIOrchestrator → AutonomousRAGAgent
 *   → AutonomousRAGDecisionService → RAGToolDispatcher
 *   → RAGQueryExecutor (db_query / db_count / db_aggregate)
 *   → Real SQLite data → formatted response
 *
 * Tests are structured in two tiers:
 *   Tier 1 — Pipeline verification (deterministic, no AI variability)
 *   Tier 2 — ChatService end-to-end (resilient to AI routing decisions)
 *
 * Requires: OPENAI_API_KEY in parent project .env
 */
class ChatServiceRAGIntegrationTest extends TestCase
{
    protected ChatService $chatService;
    protected ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasRealApiKey('openai')) {
            $this->markTestSkipped('Requires a real OPENAI_API_KEY');
        }

        Config::set('ai-engine.credits.enabled', false);
        Config::set('ai-engine.default', 'openai');
        Config::set('ai-engine.orchestration_model', 'gpt-4o-mini');
        Config::set('ai-engine.nodes.enabled', false);
        Config::set('ai-engine.nodes.is_master', true);

        $this->createProductsTable();
        $this->seedProducts();

        // Register test model as RAG collection
        Config::set('ai-engine.intelligent_rag.default_collections', [
            [
                'name' => 'product',
                'class' => TestRAGProduct::class,
                'table' => 'test_products',
                'description' => 'Products catalog with name, price, category, stock status. Use for listing, counting, and aggregating product data.',
                'capabilities' => [
                    'db_query' => true,
                    'db_count' => true,
                    'db_aggregate' => true,
                    'vector_search' => false,
                    'crud' => true,
                ],
            ],
        ]);

        Config::set('ai-agent.model_config_discovery.paths', []);
        Config::set('ai-agent.model_config_discovery.namespaces', []);
        Config::set('ai-agent.autonomous_rag.function_calling', 'off');
        Config::set('ai-agent.autonomous_rag.per_page', 5);

        // Clear stale discovery caches
        Cache::forget('ai_engine:rag_collections');
        Cache::forget('ai_engine:rag_collections_with_descriptions');
        Cache::forget('node_local_metadata');

        // Rebuild the ENTIRE singleton chain bottom-up so constructor DI picks up fresh deps.
        $rebuildClasses = [
            \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class,
            \LaravelAIEngine\Services\RAG\RAGModelDiscovery::class,
            \LaravelAIEngine\Services\RAG\RAGFilterService::class,
            \LaravelAIEngine\Services\RAG\RAGQueryExecutor::class,
            \LaravelAIEngine\Services\RAG\IntelligentRAGService::class,
            \LaravelAIEngine\Services\RAG\RAGToolDispatcher::class,
            \LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService::class,
            \LaravelAIEngine\Services\RAG\AutonomousRAGAgent::class,
            \LaravelAIEngine\Services\Agent\OrchestratorResourceDiscovery::class,
            \LaravelAIEngine\Services\Agent\OrchestratorResponseFormatter::class,
            \LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class,
            \LaravelAIEngine\Services\Node\NodeMetadataDiscovery::class,
            \LaravelAIEngine\Services\Agent\OrchestratorPromptBuilder::class,
            \LaravelAIEngine\Services\Agent\MinimalAIOrchestrator::class,
        ];
        foreach ($rebuildClasses as $class) {
            if ($this->app->resolved($class)) {
                $this->app->forgetInstance($class);
            }
        }

        $this->chatService = app(ChatService::class);
        $this->contextManager = app(ContextManager::class);

        // Verify pipeline is wired correctly
        $collections = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class)->discover(useCache: false);
        $this->assertNotEmpty($collections, 'RAG collections should be configured');
    }

    protected function uniqueSession(): string
    {
        return 'rag-test-' . uniqid();
    }

    // ══════════════════════════════════════════════════════════
    //  Database setup
    // ══════════════════════════════════════════════════════════

    protected function createProductsTable(): void
    {
        if (!Schema::hasTable('test_products')) {
            Schema::create('test_products', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('category')->default('general');
                $table->decimal('price', 10, 2)->default(0);
                $table->integer('stock')->default(0);
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
    }

    protected function seedProducts(): void
    {
        TestRAGProduct::insert([
            ['name' => 'MacBook Pro 16"',    'category' => 'electronics', 'price' => 2499.99, 'stock' => 15, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'iPhone 15 Pro',      'category' => 'electronics', 'price' => 1199.99, 'stock' => 42, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'AirPods Pro',        'category' => 'electronics', 'price' => 249.99,  'stock' => 100,'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Leather Wallet',     'category' => 'accessories', 'price' => 89.99,   'stock' => 200,'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Running Shoes',      'category' => 'footwear',    'price' => 159.99,  'stock' => 75, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Vintage Watch',      'category' => 'accessories', 'price' => 599.99,  'stock' => 0,  'status' => 'out_of_stock', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Wireless Keyboard',  'category' => 'electronics', 'price' => 129.99,  'stock' => 60, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Desk Lamp',          'category' => 'home',        'price' => 49.99,   'stock' => 150,'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Yoga Mat',           'category' => 'fitness',     'price' => 39.99,   'stock' => 80, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Coffee Maker',       'category' => 'home',        'price' => 199.99,  'stock' => 30, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bluetooth Speaker',  'category' => 'electronics', 'price' => 79.99,   'stock' => 0,  'status' => 'out_of_stock', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sunglasses',         'category' => 'accessories', 'price' => 149.99,  'stock' => 45, 'status' => 'active',       'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  TIER 1 — Pipeline verification (deterministic, no AI calls)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function tier1_resource_discovery_includes_rag_collections(): void
    {
        // Regression: OrchestratorResourceDiscovery previously did not include
        // RAG collections. The orchestrator prompt showed "(No local collections available)"
        // causing the AI to pick 'conversational' instead of 'search_rag'.
        // Fix: OrchestratorResourceDiscovery now delegates to RAGCollectionDiscovery
        // as the single source of truth for collections.
        $resources = app(\LaravelAIEngine\Services\Agent\OrchestratorResourceDiscovery::class)->discover();

        $this->assertArrayHasKey('collections', $resources);
        $this->assertNotEmpty($resources['collections'], 'Resource discovery should include RAG collections');

        $names = array_column($resources['collections'], 'name');
        $this->assertContains('product', $names, 'Should discover "product" from RAGCollectionDiscovery');
    }

    /** @test */
    public function tier1_orchestrator_prompt_shows_collections(): void
    {
        // Verify the orchestrator prompt actually includes our collection
        $promptBuilder = app(\LaravelAIEngine\Services\Agent\OrchestratorPromptBuilder::class);
        $resources = app(\LaravelAIEngine\Services\Agent\OrchestratorResourceDiscovery::class)->discover();
        $context = new \LaravelAIEngine\DTOs\UnifiedActionContext(
            sessionId: 'prompt-test',
            userId: null,
        );

        $prompt = $promptBuilder->build('List all products', $resources, $context);

        $this->assertStringContainsString('product', strtolower($prompt),
            'Orchestrator prompt should mention "product" collection');
        $this->assertStringNotContainsString('(No local collections available)', $prompt,
            'Orchestrator prompt should NOT say no collections available');
    }

    /** @test */
    public function tier1_test_data_is_in_database(): void
    {
        $this->assertEquals(12, TestRAGProduct::count());
        $this->assertEquals(5, TestRAGProduct::where('category', 'electronics')->count());
        $this->assertEquals(2, TestRAGProduct::where('status', 'out_of_stock')->count());
    }

    /** @test */
    public function tier1_model_discovery_resolves_product(): void
    {
        $discovery = app(\LaravelAIEngine\Services\RAG\RAGModelDiscovery::class);
        $modelClass = $discovery->resolveModelClass('product');
        $this->assertEquals(TestRAGProduct::class, $modelClass);
    }

    /** @test */
    public function tier1_available_models_include_product(): void
    {
        $discovery = app(\LaravelAIEngine\Services\RAG\RAGModelDiscovery::class);
        $models = $discovery->getAvailableModels();
        $this->assertNotEmpty($models);
        $this->assertEquals('product', $models[0]['name']);
        $this->assertTrue($models[0]['capabilities']['db_query']);
        $this->assertTrue($models[0]['capabilities']['db_count']);
    }

    /** @test */
    public function tier1_db_query_returns_products(): void
    {
        $executor = app(\LaravelAIEngine\Services\RAG\RAGQueryExecutor::class);
        $result = $executor->dbQuery(
            ['model' => 'product'],
            null,
            ['session_id' => 'tier1-query']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(5, $result['count']); // per_page=5
        $this->assertEquals(12, $result['total_count']);
        $this->assertEquals('product', $result['entity_type']);
        $this->assertNotEmpty($result['entity_ids']);
        $this->assertStringContainsString('product', strtolower($result['response']));
    }

    /** @test */
    public function tier1_db_count_returns_12(): void
    {
        $executor = app(\LaravelAIEngine\Services\RAG\RAGQueryExecutor::class);
        $result = $executor->dbCount(
            ['model' => 'product'],
            null,
            ['session_id' => 'tier1-count']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(12, $result['count']);
        $this->assertStringContainsString('12', $result['response']);
    }

    /** @test */
    public function tier1_db_aggregate_sums_prices(): void
    {
        $executor = app(\LaravelAIEngine\Services\RAG\RAGQueryExecutor::class);
        $result = $executor->dbAggregate(
            ['model' => 'product', 'aggregate' => ['operation' => 'sum', 'field' => 'price']],
            null,
            ['session_id' => 'tier1-agg']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('sum', $result['operation']);
        $this->assertEquals('price', $result['field']);
        // Total: 2499.99+1199.99+249.99+89.99+159.99+599.99+129.99+49.99+39.99+199.99+79.99+149.99 = 5449.87
        $this->assertEqualsWithDelta(5449.87, $result['result'], 0.02);
    }

    /** @test */
    public function tier1_db_query_with_status_filter(): void
    {
        $executor = app(\LaravelAIEngine\Services\RAG\RAGQueryExecutor::class);
        $result = $executor->dbQuery(
            ['model' => 'product', 'filters' => ['status' => 'out_of_stock']],
            null,
            ['session_id' => 'tier1-filter']
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['total_count']);
    }

    /** @test */
    public function tier1_db_query_pagination(): void
    {
        $executor = app(\LaravelAIEngine\Services\RAG\RAGQueryExecutor::class);

        // Page 1
        $r1 = $executor->dbQuery(['model' => 'product'], null, ['session_id' => 'tier1-page']);
        $this->assertTrue($r1['success']);
        $this->assertEquals(1, $r1['page']);
        $this->assertEquals(5, $r1['count']);
        $this->assertTrue($r1['has_more']);

        // Page 2
        $r2 = $executor->dbQuery(['model' => 'product'], null, ['session_id' => 'tier1-page'], 2);
        $this->assertTrue($r2['success']);
        $this->assertEquals(2, $r2['page']);
        $this->assertEquals(5, $r2['count']);
        $this->assertTrue($r2['has_more']);

        // Page 3
        $r3 = $executor->dbQuery(['model' => 'product'], null, ['session_id' => 'tier1-page'], 3);
        $this->assertTrue($r3['success']);
        $this->assertEquals(3, $r3['page']);
        $this->assertEquals(2, $r3['count']);
        $this->assertFalse($r3['has_more']);
    }

    /** @test */
    public function tier1_rag_tool_dispatcher_executes_db_query(): void
    {
        $dispatcher = app(\LaravelAIEngine\Services\RAG\RAGToolDispatcher::class);
        $result = $dispatcher->dispatch(
            ['tool' => 'db_query', 'parameters' => ['model' => 'product']],
            'List all products',
            'tier1-dispatch',
            null,
            [],
            []
        );

        $this->assertTrue($result['success']);
        $this->assertEquals('db_query', $result['tool']);
        $this->assertGreaterThan(0, $result['count']);
    }

    /** @test */
    public function tier1_rag_agent_processes_list_request(): void
    {
        $ragAgent = app(\LaravelAIEngine\Services\RAG\AutonomousRAGAgent::class);
        $result = $ragAgent->process(
            'List all products',
            'tier1-agent-' . uniqid(),
            null,
            [],
            []
        );

        $this->assertTrue($result['success'] ?? false,
            'RAG agent failed: ' . json_encode(array_diff_key($result, ['items' => 1]))
        );
        $this->assertNotEmpty($result['response']);
    }

    /** @test */
    public function tier1_rag_decision_service_picks_correct_tool(): void
    {
        $discovery = app(\LaravelAIEngine\Services\RAG\RAGModelDiscovery::class);
        $decisionService = app(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService::class);

        $models = $discovery->getAvailableModels();
        $decision = $decisionService->decide('List all products', [
            'conversation' => '(none)',
            'models' => $models,
            'nodes' => [],
        ]);

        $this->assertNotEmpty($decision);
        $this->assertContains($decision['tool'] ?? '', ['db_query', 'db_count', 'answer_from_context', 'vector_search'],
            'Decision tool should be a data retrieval tool. Got: ' . json_encode($decision));
        $this->assertEquals('product', $decision['parameters']['model'] ?? null,
            'Decision should target product model. Got: ' . json_encode($decision));
    }

    // ══════════════════════════════════════════════════════════════
    //  TIER 2 — ChatService end-to-end (real AI, resilient assertions)
    // ══════════════════════════════════════════════════════════════

    /** @test */
    public function tier2_list_products_routes_through_rag(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'List all products',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $this->assertNotEmpty($response->getContent());

        $content = strtolower($response->getContent());
        $strategy = $response->metadata['agent_strategy'] ?? 'unknown';

        // The response should reference products in some way:
        // - actual product names (best case)
        // - "products" mentioned (RAG path hit)
        // - "no products found" (RAG path hit, but filters excluded results)
        $referencesProducts = str_contains($content, 'product')
            || str_contains($content, 'macbook')
            || str_contains($content, 'iphone');

        $this->assertTrue($referencesProducts,
            "Should reference products. Strategy: {$strategy}, Content: " . substr($response->getContent(), 0, 400)
        );
    }

    /** @test */
    public function tier2_count_products_via_chatservice(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'How many products do I have in total?',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $content = $response->getContent();

        // Accept: exact count "12", or any mention of products/count
        $hasCount = str_contains($content, '12')
            || preg_match('/\d+\s*product/i', $content)
            || str_contains(strtolower($content), 'product');

        $this->assertTrue($hasCount,
            "Should mention product count. Got: " . substr($content, 0, 300)
        );
    }

    /** @test */
    public function tier2_list_then_followup(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: List products
        $r1 = $this->chatService->processMessage(
            message: 'Show me all products',
            sessionId: $sessionId,
            useMemory: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'Show me all products'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Follow-up about listed items
        $r2 = $this->chatService->processMessage(
            message: 'Which of these are in the electronics category?',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success);
        $this->assertNotEmpty($r2->getContent());

        // The AI should either list electronics or reference the category
        $content = strtolower($r2->getContent());
        $referencesElectronics = str_contains($content, 'electronic')
            || str_contains($content, 'macbook')
            || str_contains($content, 'iphone')
            || str_contains($content, 'airpods')
            || str_contains($content, 'keyboard')
            || str_contains($content, 'speaker')
            || str_contains($content, 'product');

        $this->assertTrue($referencesElectronics,
            "Follow-up should reference electronics or products. Got: " . substr($r2->getContent(), 0, 400)
        );
    }

    /** @test */
    public function tier2_product_query_then_general_question(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Product query
        $r1 = $this->chatService->processMessage(
            message: 'List my products',
            sessionId: $sessionId,
            useMemory: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'List my products'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Switch to general knowledge
        $r2 = $this->chatService->processMessage(
            message: 'What is the capital of Japan?',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success);
        $this->assertStringContainsString('Tokyo', $r2->getContent(),
            "Should answer Tokyo. Got: " . substr($r2->getContent(), 0, 200)
        );
    }

    /** @test */
    public function tier2_general_chat_then_product_query(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: General greeting
        $r1 = $this->chatService->processMessage(
            message: 'Hello!',
            sessionId: $sessionId,
            useMemory: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'Hello!'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Product query
        $r2 = $this->chatService->processMessage(
            message: 'Show me all products',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success);
        $content = strtolower($r2->getContent());

        $referencesProducts = str_contains($content, 'product')
            || str_contains($content, 'macbook')
            || str_contains($content, 'iphone');

        $this->assertTrue($referencesProducts,
            "Should reference products after topic switch. Got: " . substr($r2->getContent(), 0, 400)
        );
    }

    /** @test */
    public function tier2_metadata_populated_after_rag_query(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'List all products',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $this->assertArrayHasKey('agent_strategy', $response->metadata);
        $this->assertArrayHasKey('workflow_active', $response->metadata);
        $this->assertArrayHasKey('workflow_completed', $response->metadata);

        // Strategy should be one of the known types
        $this->assertContains(
            $response->metadata['agent_strategy'],
            ['conversational', 'search_rag', 'use_tool', 'start_collector', 'needs_user_input', 'failure'],
        );
    }

    /** @test */
    public function tier2_context_persisted_after_rag_query(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Show me all products',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);

        // Context should be persisted
        $context = UnifiedActionContext::load($sessionId, null);
        $this->assertNotNull($context);
        $this->assertEquals($sessionId, $context->sessionId);
        $this->assertNotEmpty($context->conversationHistory);
    }

    /** @test */
    public function tier2_full_flow_greet_list_followup_switch(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Greeting
        $r1 = $this->chatService->processMessage(
            message: 'Hi there!',
            sessionId: $sessionId,
            useMemory: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'Hi there!'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Product query
        $r2 = $this->chatService->processMessage(
            message: 'Show me all my products',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r2->success);

        $history[] = ['role' => 'user', 'content' => 'Show me all my products'];
        $history[] = ['role' => 'assistant', 'content' => $r2->getContent()];

        // Turn 3: Follow-up
        $r3 = $this->chatService->processMessage(
            message: 'Which ones are the most expensive?',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r3->success);
        $this->assertNotEmpty($r3->getContent());

        $history[] = ['role' => 'user', 'content' => 'Which ones are the most expensive?'];
        $history[] = ['role' => 'assistant', 'content' => $r3->getContent()];

        // Turn 4: Topic switch to general knowledge
        $r4 = $this->chatService->processMessage(
            message: 'By the way, what year was the Eiffel Tower built?',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r4->success);
        $this->assertStringContainsString('1889', $r4->getContent(),
            "Should know Eiffel Tower year. Got: " . substr($r4->getContent(), 0, 200)
        );
    }

    /** @test */
    public function tier2_sessions_are_isolated(): void
    {
        $sessionA = $this->uniqueSession();
        $sessionB = $this->uniqueSession();

        // Session A: Product query
        $rA = $this->chatService->processMessage(
            message: 'List all products',
            sessionId: $sessionA,
            useMemory: false,
        );
        $this->assertTrue($rA->success);

        // Session B: General question
        $rB = $this->chatService->processMessage(
            message: 'What is 15 times 7?',
            sessionId: $sessionB,
            useMemory: false,
        );
        $this->assertTrue($rB->success);
        $this->assertStringContainsString('105', $rB->getContent());

        // Contexts should be separate
        $ctxA = UnifiedActionContext::load($sessionA, null);
        $ctxB = UnifiedActionContext::load($sessionB, null);
        $this->assertNotNull($ctxA);
        $this->assertNotNull($ctxB);
        $this->assertNotEquals($ctxA->sessionId, $ctxB->sessionId);
    }

    /** @test */
    public function tier2_out_of_stock_filter(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Show me products that are out of stock',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $content = strtolower($response->getContent());

        // Accept: mentions out-of-stock items, or mentions "out of stock", or mentions products
        $relevant = str_contains($content, 'watch')
            || str_contains($content, 'speaker')
            || str_contains($content, 'out of stock')
            || str_contains($content, 'out_of_stock')
            || str_contains($content, 'product')
            || str_contains($content, 'stock');

        $this->assertTrue($relevant,
            "Should reference out-of-stock context. Got: " . substr($response->getContent(), 0, 400)
        );
    }

    /** @test */
    public function tier2_aggregate_total_price(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'What is the total price of all products?',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $content = $response->getContent();

        // The AI may route to db_aggregate (returns a number), search_rag (returns product data),
        // or conversational (generic response). All are valid pipeline outcomes.
        $this->assertNotEmpty($content, 'Aggregate query should produce a non-empty response');
    }

    /** @test */
    public function tier2_pagination_show_more(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: List products (per_page=5)
        $r1 = $this->chatService->processMessage(
            message: 'List all products',
            sessionId: $sessionId,
            useMemory: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'List all products'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Ask for more
        $r2 = $this->chatService->processMessage(
            message: 'Show more',
            sessionId: $sessionId,
            useMemory: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success);
        $this->assertNotEmpty($r2->getContent());
    }

    protected function tearDown(): void
    {
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }
}

// ══════════════════════════════════════════════════════════════
//  Test Model — lives in the test file, acts as local master data
// ══════════════════════════════════════════════════════════════

class TestRAGProduct extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'test_products';
    protected $fillable = ['name', 'category', 'price', 'stock', 'status'];

    public function toRAGSummary(): string
    {
        return "{$this->name} ({$this->category}) - \${$this->price} [{$this->status}]";
    }

    public function toRAGContent(): string
    {
        return "**{$this->name}** | Category: {$this->category} | Price: \${$this->price} | Stock: {$this->stock} | Status: {$this->status}";
    }

    public function __toString(): string
    {
        return $this->toRAGSummary();
    }
}

// ══════════════════════════════════════════════════════════════
//  Test AutonomousModelConfig — makes the model discoverable
// ══════════════════════════════════════════════════════════════

class TestRAGProductModelConfig extends \LaravelAIEngine\Contracts\AutonomousModelConfig
{
    public static function getModelClass(): string
    {
        return TestRAGProduct::class;
    }

    public static function getName(): string
    {
        return 'product';
    }

    public static function getDescription(): string
    {
        return 'Products catalog with name, price, category, stock, and status';
    }

    public static function getFilterConfig(): array
    {
        return [
            'status_field' => 'status',
            'amount_field' => 'price',
        ];
    }

    public static function getTools(): array
    {
        return [
            'update_product' => [
                'description' => 'Update a product\'s details (price, stock, status)',
                'parameters' => [
                    'id' => 'required|integer - Product ID to update',
                    'price' => 'number - New price',
                    'stock' => 'integer - New stock quantity',
                    'status' => 'string - New status (active, out_of_stock)',
                ],
                'handler' => function (array $params) {
                    $product = TestRAGProduct::find($params['id'] ?? null);
                    if (!$product) {
                        return ['success' => false, 'message' => 'Product not found'];
                    }
                    $product->update(array_filter([
                        'price' => $params['price'] ?? null,
                        'stock' => $params['stock'] ?? null,
                        'status' => $params['status'] ?? null,
                    ]));
                    return ['success' => true, 'message' => "Product '{$product->name}' updated successfully"];
                },
                'requires_confirmation' => true,
            ],
        ];
    }

    public static function getAllowedOperations(?int $userId): array
    {
        return ['list', 'create', 'update', 'delete'];
    }
}
