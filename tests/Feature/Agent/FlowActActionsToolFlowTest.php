<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * End-to-end "actions/tools" chat flow.
 *
 * Drives the REAL native agent runtime (RoutingPipeline -> AgentExecutionDispatcher ->
 * AgentActionExecutionService -> ToolRegistry) and asserts that a registered tool is
 * actually EXECUTED when the (mocked) AI router routes to use_tool, and that the routing
 * metadata + the tool's action result are carried back on the AgentResponse.
 *
 * Only the IntentRouter is mocked (no real AI/network); every other collaborator is the
 * real container-resolved service.
 */
class FlowActActionsToolFlowTest extends TestCase
{
    public function test_use_tool_routing_actually_executes_registered_tool_and_carries_result_and_routing_metadata(): void
    {
        $tool = new FlowActInvoiceTool();

        // Register the tool on the REAL registry the runtime resolves.
        $registry = $this->app->make(ToolRegistry::class);
        $registry->register('flowact_create_invoice', $tool);

        // Route the message to use_tool, targeting our registered tool. The message starts
        // with "Create" so MessageClassificationStage classifies it as an action_request
        // (route=ask_ai) and abstains, letting the AIRouterStage (this mock) decide.
        $router = Mockery::mock(IntentRouter::class);
        $router->shouldReceive('route')
            ->once()
            ->withArgs(function (string $message, UnifiedActionContext $context, array $options): bool {
                return $message === 'Create an invoice for Ahmed for 250.';
            })
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'flowact_create_invoice',
                'params' => ['customer' => 'Ahmed', 'amount' => 250],
                'reasoning' => 'invoice creation tool',
                'decision_source' => 'router_ai',
            ]);
        $this->app->instance(IntentRouter::class, $router);

        /** @var LaravelAgentProcessor $processor */
        $processor = $this->app->make(LaravelAgentProcessor::class);

        $response = $processor->process(
            'Create an invoice for Ahmed for 250.',
            'flowact-session-tool',
            'flowact-user',
            ['use_actions' => true, 'use_rag' => false]
        );

        // The tool actually executed.
        $this->assertSame(1, $tool->executions, 'Registered tool execute() must run exactly once.');
        $this->assertSame(['customer' => 'Ahmed', 'amount' => 250], $tool->receivedParameters);

        // Response is a success and carries the tool's action result payload.
        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Invoice INV-FLOWACT-1 created for Ahmed.', $response->message);
        $this->assertSame('INV-FLOWACT-1', $response->data['invoice_id'] ?? null);
        $this->assertSame('Ahmed', $response->data['customer'] ?? null);
        $this->assertSame('flowact_create_invoice', $response->metadata['tool_name'] ?? null);

        // Routing metadata reflects the use_tool decision.
        $this->assertSame(RoutingDecisionAction::USE_TOOL, $response->metadata['routing_decision']['action'] ?? null);
        $this->assertSame(RoutingDecisionAction::USE_TOOL, $response->metadata['route_explanation']['action'] ?? null);
    }

    public function test_use_tool_with_missing_required_parameter_returns_needs_user_input_without_executing(): void
    {
        $tool = new FlowActInvoiceTool();
        $registry = $this->app->make(ToolRegistry::class);
        $registry->register('flowact_create_invoice', $tool);

        $router = Mockery::mock(IntentRouter::class);
        $router->shouldReceive('route')
            ->once()
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'flowact_create_invoice',
                // 'customer' required parameter intentionally omitted.
                'params' => ['amount' => 250],
                'decision_source' => 'router_ai',
            ]);
        $this->app->instance(IntentRouter::class, $router);

        /** @var LaravelAgentProcessor $processor */
        $processor = $this->app->make(LaravelAgentProcessor::class);

        $response = $processor->process(
            'Create an invoice please.',
            'flowact-session-missing',
            'flowact-user',
            ['use_actions' => true, 'use_rag' => false]
        );

        // Validation short-circuits before execute(): the tool never runs.
        $this->assertSame(0, $tool->executions);
        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('customer', strtolower($response->message));
        $this->assertSame(RoutingDecisionAction::USE_TOOL, $response->metadata['route_explanation']['action'] ?? null);
    }
}

/**
 * Self-contained AgentTool fixture. No network, no DB.
 */
class FlowActInvoiceTool extends AgentTool
{
    public int $executions = 0;

    /** @var array<string, mixed> */
    public array $receivedParameters = [];

    public function getName(): string
    {
        return 'flowact_create_invoice';
    }

    public function getDescription(): string
    {
        return 'Creates an invoice for a customer (test fixture).';
    }

    public function getParameters(): array
    {
        return [
            'customer' => ['type' => 'string', 'required' => true, 'description' => 'Customer name'],
            'amount' => ['type' => 'number', 'required' => false, 'description' => 'Invoice amount'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $this->executions++;
        $this->receivedParameters = $parameters;

        $customer = (string) ($parameters['customer'] ?? 'unknown');

        return ActionResult::success(
            message: "Invoice INV-FLOWACT-1 created for {$customer}.",
            data: [
                'invoice_id' => 'INV-FLOWACT-1',
                'customer' => $customer,
                'amount' => $parameters['amount'] ?? null,
            ],
            metadata: ['agent_strategy' => 'tool']
        );
    }
}
