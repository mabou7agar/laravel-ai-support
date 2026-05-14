<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Collection;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIPromptPolicyVersion;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\RAG\RAGPromptPolicyService;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use Mockery;
use Orchestra\Testbench\TestCase;

class IntentRouterTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('ai-engine.default', 'openai');
        $app['config']->set('ai-engine.orchestration_model', 'gpt-4o-mini');
        $app['config']->set('ai-engine.nodes.enabled', true);
    }

    public function test_route_parses_structured_ai_decision(): void
    {
        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (AIRequest $request) use (&$capturedPrompt) {
                $capturedPrompt = $request->getPrompt();
                return true;
            }))
            ->andReturn(AIResponse::success(
                '{"action":"route_to_node","resource_name":"billing","reasoning":"invoice data belongs to that node"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->twice()->andReturn(new Collection());

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $context = new UnifiedActionContext(
            sessionId: 'session-1',
            userId: null,
            conversationHistory: [
                ['role' => 'assistant', 'content' => '1. Invoice INV-1', 'metadata' => []],
            ],
            metadata: [
                'selected_entity_context' => [
                    'entity_id' => 42,
                    'entity_type' => 'invoice',
                ],
            ]
        );

        $decision = $router->route('show me invoice details', $context, [
            'model_configs' => [FakeInvoiceModelConfig::class],
        ]);

        $this->assertSame('route_to_node', $decision['action']);
        $this->assertSame('billing', $decision['resource_name']);
        $this->assertSame('invoice data belongs to that node', $decision['reasoning']);
        $this->assertStringContainsString('show_invoice', $capturedPrompt);
        $this->assertStringContainsString('"entity_id": 42', $capturedPrompt);
        $this->assertStringContainsString('Respond with JSON ONLY', $capturedPrompt);
    }

    public function test_route_includes_remote_collectors_and_nodes_in_prompt(): void
    {
        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (AIRequest $request) use (&$capturedPrompt) {
                $capturedPrompt = $request->getPrompt();
                return true;
            }))
            ->andReturn(AIResponse::success(
                '{"action":"start_collector","resource_name":"create_invoice","reasoning":"this is a create flow"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->twice()->andReturn(collect([
            [
                'slug' => 'billing',
                'name' => 'Billing',
                'description' => 'Handles invoices',
                'domains' => ['invoices', 'payments'],
                'autonomous_collectors' => [
                    [
                        'name' => 'create_invoice',
                        'goal' => 'Create invoice',
                        'description' => 'Collect invoice data',
                    ],
                ],
            ],
        ]));

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $context = new UnifiedActionContext('session-2', null);

        $decision = $router->route('create a new invoice', $context);

        $this->assertSame('start_collector', $decision['action']);
        $this->assertSame('create_invoice', $decision['resource_name']);
        $this->assertStringContainsString('create_invoice', $capturedPrompt);
        $this->assertStringContainsString('billing: Handles invoices [Domains: invoices, payments]', $capturedPrompt);
    }

    public function test_route_defaults_when_ai_response_is_unstructured(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(
                'just answer normally',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->twice()->andReturn(new Collection());

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $decision = $router->route('hello', new UnifiedActionContext('session-3', null));

        $this->assertSame('conversational', $decision['action']);
        $this->assertNull($decision['resource_name']);
        $this->assertSame('Heuristic fallback: greeting or general chat', $decision['reasoning']);
    }

    public function test_use_tool_without_resource_falls_back_to_safe_route(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(
                '{"action":"use_tool","resource_name":null,"params":{"query":"show me recent updates"},"reasoning":"needs data"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->twice()->andReturn(new Collection());

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $decision = $router->route('show me recent updates', new UnifiedActionContext('session-use-tool-null', null));

        $this->assertSame('conversational', $decision['action']);
        $this->assertNull($decision['resource_name']);
        $this->assertSame('show me recent updates', $decision['params']['query']);
        $this->assertSame('heuristic_fallback', $decision['decision_source']);
    }

    public function test_forwarded_requests_cannot_reroute_to_another_node(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(
                '{"action":"route_to_node","resource_name":"crm","reasoning":"customer data belongs there"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->once()->andReturn(new Collection());

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $decision = $router->route('show customer profile', new UnifiedActionContext('session-4', null), [
            'is_forwarded' => true,
        ]);

        $this->assertSame('search_rag', $decision['action']);
        $this->assertNull($decision['resource_name']);
        $this->assertStringContainsString('forwarded request cannot re-route nodes', $decision['reasoning']);
    }

    public function test_local_only_mode_hides_remote_nodes_from_prompt(): void
    {
        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (AIRequest $request) use (&$capturedPrompt) {
                $capturedPrompt = $request->getPrompt();
                return true;
            }))
            ->andReturn(AIResponse::success(
                '{"action":"search_rag","resource_name":null,"reasoning":"local mode"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->never();

        $router = new IntentRouter($ai, $nodes, new SelectedEntityContextService());
        $decision = $router->route('list invoices', new UnifiedActionContext('session-5', null), [
            'local_only' => true,
        ]);

        $this->assertSame('search_rag', $decision['action']);
        $this->assertStringContainsString('Remote Nodes', $capturedPrompt);
        $this->assertStringContainsString('(No nodes available)', $capturedPrompt);
    }

    public function test_enabled_skill_match_bypasses_ai_router_and_returns_existing_plan(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->never();

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->never();

        $skillRegistry = Mockery::mock(AgentSkillRegistry::class);
        $skillRegistry->shouldReceive('skills')->twice()->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices.',
                triggers: ['create invoice'],
                actions: ['invoices.create']
            ),
        ]);

        $router = new IntentRouter(
            $ai,
            $nodes,
            new SelectedEntityContextService(),
            null,
            null,
            null,
            $skillRegistry,
            new AgentSkillMatcher($skillRegistry),
            new AgentSkillExecutionPlanner()
        );

        $decision = $router->route('create invoice for ACME', new UnifiedActionContext('session-skill', null), [
            'local_only' => true,
        ]);

        $this->assertSame('use_tool', $decision['action']);
        $this->assertSame('update_action_draft', $decision['resource_name']);
        $this->assertSame('invoices.create', $decision['params']['action_id']);
        $this->assertSame('skill_match', $decision['decision_source']);
    }

    public function test_route_reuses_prompt_policy_service_for_decision_prompt(): void
    {
        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function (AIRequest $request) use (&$capturedPrompt) {
                $capturedPrompt = $request->getPrompt();

                return true;
            }))
            ->andReturn(AIResponse::success(
                '{"action":"conversational","resource_name":null,"reasoning":"policy-aware route"}',
                EngineEnum::from('openai'),
                EntityEnum::from('gpt-4o-mini')
            ));

        $nodes = Mockery::mock(NodeRegistryService::class);
        $nodes->shouldReceive('getActiveNodes')->twice()->andReturn(new Collection());

        $policy = new AIPromptPolicyVersion([
            'policy_key' => 'agent-router',
            'version' => 3,
            'status' => 'active',
            'scope_key' => 'tenant-scope',
            'template' => 'Prefer local deterministic tools before remote routing.',
            'rules' => ['remote_routing' => 'last_resort'],
        ]);
        $policy->id = 44;

        $promptPolicies = Mockery::mock(RAGPromptPolicyService::class);
        $promptPolicies->shouldReceive('resolveForRuntime')
            ->once()
            ->with(Mockery::on(fn (array $runtime): bool => ($runtime['tenant_id'] ?? null) === 'tenant-policy'), null)
            ->andReturn([
                'selected' => $policy,
                'active' => $policy,
                'canary' => null,
                'shadow' => null,
                'selection' => 'active',
            ]);

        $router = new IntentRouter(
            $ai,
            $nodes,
            new SelectedEntityContextService(),
            null,
            null,
            null,
            null,
            null,
            null,
            $promptPolicies
        );

        $decision = $router->route('hello', new UnifiedActionContext('session-policy', null), [
            'tenant_id' => 'tenant-policy',
        ]);

        $this->assertSame('conversational', $decision['action']);
        $this->assertStringContainsString('DECISION PROMPT POLICY', $capturedPrompt);
        $this->assertStringContainsString('Prefer local deterministic tools before remote routing.', $capturedPrompt);
        $this->assertSame('agent-router', $decision['metadata']['prompt_policy']['policy_key']);
        $this->assertSame(3, $decision['metadata']['prompt_policy']['version']);
    }
}

class FakeInvoiceModelConfig
{
    public static function getName(): string
    {
        return 'Invoice';
    }

    public static function getTools(): array
    {
        return [
            'show_invoice' => [
                'description' => 'Show invoice details',
            ],
        ];
    }
}
