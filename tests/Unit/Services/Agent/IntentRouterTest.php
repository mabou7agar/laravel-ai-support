<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Collection;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
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
