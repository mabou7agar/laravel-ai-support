<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\Contracts\RAG\FederatedModelRouter;
use LaravelAIEngine\Contracts\Federation\NodeMetadataProvider;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\ContinueNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\RouteToNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\Tools\RouteToNodeTool;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;
use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGPlannerService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;
use Illuminate\Support\Collection;
use Mockery;

/**
 * Self-contained coverage for the Federation routing surface:
 *   - route_to_node tool + RouteToNode/ContinueNode action handlers
 *   - CONTINUE_NODE continuation in LaravelAgentProcessor
 *   - degraded local-RAG fallback on remote failure
 *   - should_route_to_node structured-query remote routing into FederatedModelRouter
 *
 * Every collaborator that would otherwise make a network/LLM call (AIEngineService,
 * NodeRouterService, AiNativeRuntime, AgentExecutionDispatcher) is mocked. No live calls.
 */
class FederationFlowTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    private const UNREACHABLE = "Sorry, I couldn't reach remote node 'sales' (HTTP 503).";

    // ---------------------------------------------------------------------
    // ContinueNodeActionHandler: degraded local-RAG fallback (priority 5)
    // ---------------------------------------------------------------------

    public function test_continue_node_handler_degraded_fallback_fires_end_to_end(): void
    {
        $context = new UnifiedActionContext('sess-cn-fb', 7);
        $context->set('routed_to_node', ['node_slug' => 'sales']);
        $context->set('remote_pending_action', ['status' => 'awaiting_input', 'node_slug' => 'sales']);
        $context->pendingAction = ['type' => 'remote_node_session'];

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('continueSession')
            ->once()
            ->andReturn(AgentResponse::failure(self::UNREACHABLE, context: $context));

        $localSuccess = AgentResponse::success('Here are local sales docs.', context: $context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('searchRag')
            ->once()
            ->with(
                'follow up question',
                $context,
                Mockery::on(fn (array $o): bool => ($o['local_only'] ?? null) === true),
                Mockery::any()
            )
            ->andReturn($localSuccess);

        $handler = new ContinueNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $decision = $this->decision(RoutingDecisionAction::CONTINUE_NODE);
        $response = $handler->handle($decision, 'follow up question', $context, [
            'allow_local_fallback_on_node_failure' => true,
        ]);

        $this->assertTrue($response->success);
        $this->assertStringStartsWith('Remote node is unavailable. Showing local results only (degraded mode).', $response->message);
        $this->assertStringContainsString('Here are local sales docs.', $response->message);
        $this->assertTrue($response->metadata['fallback_mode'] ?? false);
        $this->assertSame('remote_node_unreachable', $response->metadata['fallback_reason'] ?? null);

        // Context side-effects: routed + pending cleared, remote pending action nulled.
        $this->assertFalse($context->has('routed_to_node'));
        $this->assertFalse($context->has('remote_pending_action'));
        $this->assertNull($context->pendingAction);
    }

    // ---------------------------------------------------------------------
    // ContinueNode fallback default-OFF matrix (priority 5)
    // ---------------------------------------------------------------------

    public function test_continue_node_fallback_skipped_when_dispatcher_null(): void
    {
        $context = $this->routedContext('cn-off-a');

        $nodes = Mockery::mock(NodeSessionManager::class);
        $original = AgentResponse::failure(self::UNREACHABLE, context: $context);
        $nodes->shouldReceive('continueSession')->once()->andReturn($original);

        // No dispatcher => fallback branch is short-circuited entirely.
        $handler = new ContinueNodeActionHandler($nodes, null);

        $response = $handler->handle($this->decision(RoutingDecisionAction::CONTINUE_NODE), 'msg', $context, []);

        $this->assertSame($original, $response);
        $this->assertFalse($response->success);
        $this->assertArrayNotHasKey('fallback_mode', $response->metadata ?? []);
        $this->assertTrue($context->has('routed_to_node'));
        $this->assertTrue($context->has('remote_pending_action'));
    }

    public function test_continue_node_fallback_skipped_when_config_off(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', false);

        $context = $this->routedContext('cn-off-b');
        $nodes = Mockery::mock(NodeSessionManager::class);
        $original = AgentResponse::failure(self::UNREACHABLE, context: $context);
        $nodes->shouldReceive('continueSession')->once()->andReturn($original);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('searchRag');

        $handler = new ContinueNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle($this->decision(RoutingDecisionAction::CONTINUE_NODE), 'msg', $context, []);

        $this->assertSame($original, $response);
        $this->assertFalse($response->success);
        $this->assertTrue($context->has('routed_to_node'));
        $this->assertTrue($context->has('remote_pending_action'));
    }

    public function test_continue_node_fallback_skipped_for_non_unreachable_message(): void
    {
        $context = $this->routedContext('cn-off-c');
        $nodes = Mockery::mock(NodeSessionManager::class);
        // Message does NOT contain "couldn't reach remote node".
        $original = AgentResponse::failure('No routed node session is available to continue.', context: $context);
        $nodes->shouldReceive('continueSession')->once()->andReturn($original);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('searchRag');

        $handler = new ContinueNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle($this->decision(RoutingDecisionAction::CONTINUE_NODE), 'msg', $context, [
            'allow_local_fallback_on_node_failure' => true,
        ]);

        $this->assertSame($original, $response);
        $this->assertFalse($response->success);
        $this->assertTrue($context->has('routed_to_node'));
        $this->assertTrue($context->has('remote_pending_action'));
    }

    // ---------------------------------------------------------------------
    // RouteToNodeActionHandler guard matrix (priority 5)
    // ---------------------------------------------------------------------

    public function test_route_to_node_handler_local_only_short_circuits_to_rag(): void
    {
        $context = new UnifiedActionContext('rtn-a', 1);
        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $expected = AgentResponse::success('local rag', context: $context);
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('searchRag')->once()->andReturn($expected);
        $dispatcher->shouldNotReceive('policyService');

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'sales']),
            'm',
            $context,
            ['local_only' => true]
        );

        $this->assertSame($expected, $response);
    }

    public function test_route_to_node_handler_empty_resource_falls_back_to_rag(): void
    {
        $context = new UnifiedActionContext('rtn-b', 1);
        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $expected = AgentResponse::success('rag b', context: $context);
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('searchRag')->once()->andReturn($expected);
        $dispatcher->shouldNotReceive('policyService');

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => '']),
            'm',
            $context,
            []
        );

        $this->assertSame($expected, $response);
    }

    public function test_route_to_node_handler_local_resource_falls_back_to_rag(): void
    {
        $context = new UnifiedActionContext('rtn-c', 1);
        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $expected = AgentResponse::success('rag c', context: $context);
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('searchRag')->once()->andReturn($expected);
        $dispatcher->shouldNotReceive('policyService');

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'local']),
            'm',
            $context,
            []
        );

        $this->assertSame($expected, $response);
    }

    public function test_route_to_node_handler_failure_with_fallback_off_returns_original(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', false);

        $context = new UnifiedActionContext('rtn-d', 1);
        $original = AgentResponse::failure(self::UNREACHABLE, context: $context);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')->once()->andReturn($original);

        $policy = Mockery::mock(AgentExecutionPolicyService::class);
        $policy->shouldReceive('canRouteToNode')->andReturn(true);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('policyService')->andReturn($policy);
        $dispatcher->shouldNotReceive('searchRag');

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'sales']),
            'm',
            $context,
            []
        );

        $this->assertSame($original, $response);
        $this->assertFalse($response->success);
        $this->assertArrayNotHasKey('fallback_mode', $response->metadata ?? []);
    }

    public function test_route_to_node_handler_fallback_on_with_option_override_fires(): void
    {
        $context = new UnifiedActionContext('rtn-e', 1);
        $original = AgentResponse::failure(self::UNREACHABLE, context: $context);
        $local = AgentResponse::success('local fallback result', context: $context);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')->once()->andReturn($original);

        $policy = Mockery::mock(AgentExecutionPolicyService::class);
        $policy->shouldReceive('canRouteToNode')->andReturn(true);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('policyService')->andReturn($policy);
        $dispatcher->shouldReceive('searchRag')
            ->once()
            ->with('m', $context, Mockery::on(fn (array $o): bool => ($o['local_only'] ?? null) === true), Mockery::any())
            ->andReturn($local);

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'sales']),
            'm',
            $context,
            ['allow_local_fallback_on_node_failure' => true]
        );

        $this->assertTrue($response->success);
        $this->assertStringContainsString('local fallback result', $response->message);
        $this->assertTrue($response->metadata['fallback_mode'] ?? false);
        $this->assertSame('remote_node_unreachable', $response->metadata['fallback_reason'] ?? null);
        $this->assertSame('sales', $response->metadata['original_resource'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Node policy gating delegation (priority 3)
    // ---------------------------------------------------------------------

    public function test_route_to_node_handler_blocked_by_node_policy(): void
    {
        $context = new UnifiedActionContext('rtn-policy', 1);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $policy = Mockery::mock(AgentExecutionPolicyService::class);
        $policy->shouldReceive('canRouteToNode')->once()->with('sales', Mockery::any())->andReturn(false);

        $blocked = AgentResponse::failure('blocked by execution policy', context: $context, metadata: [
            'policy_blocked' => true,
            'blocked_type' => 'node',
            'blocked_resource' => 'sales',
        ]);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('policyService')->andReturn($policy);
        $dispatcher->shouldReceive('blockedByPolicy')
            ->once()
            ->with('node', 'sales', $context, Mockery::any())
            ->andReturn($blocked);
        $dispatcher->shouldNotReceive('searchRag');

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'sales']),
            'm',
            $context,
            []
        );

        $this->assertSame($blocked, $response);
        $this->assertSame('node', $response->metadata['blocked_type'] ?? null);
    }

    public function test_route_to_node_handler_allow_path_proceeds_to_route(): void
    {
        $context = new UnifiedActionContext('rtn-allow', 1);
        $expected = AgentResponse::success('routed', context: $context);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')->once()->with('sales', 'm', $context, Mockery::any())->andReturn($expected);

        $policy = Mockery::mock(AgentExecutionPolicyService::class);
        $policy->shouldReceive('canRouteToNode')->once()->andReturn(true);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('policyService')->andReturn($policy);
        $dispatcher->shouldReceive('blockedByPolicy')->never();

        $handler = new RouteToNodeActionHandler($nodes, fn (): AgentExecutionDispatcher => $dispatcher);

        $response = $handler->handle(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['resource_name' => 'sales']),
            'm',
            $context,
            []
        );

        $this->assertSame($expected, $response);
    }

    // ---------------------------------------------------------------------
    // NodeSessionManager: the REAL unreachable-node failure string (priority 5)
    // ---------------------------------------------------------------------

    public function test_node_session_manager_emits_real_unreachable_failure_string(): void
    {
        $node = new AINode();
        $node->slug = 'sales';
        $node->name = 'Sales';
        $node->url = 'https://sales.example';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('sales')->willReturn($node);

        $router = $this->createMock(NodeRouterService::class);
        $router->method('forwardChat')->willReturn([
            'success' => false,
            'error' => "Connection refused at https://sales.example  \n  cURL 7",
        ]);

        $finalizer = $this->createMock(AgentResponseFinalizer::class);
        $finalizer->expects($this->once())->method('persistMessage');

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $finalizer
        );

        // continueSession path
        $context = new UnifiedActionContext('nsm-fail-continue', 5);
        $context->set('routed_to_node', ['node_slug' => 'sales']);

        $response = $manager->continueSession('hi', $context, []);

        $this->assertNotNull($response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString(
            "I couldn't reach remote node 'sales' at https://sales.example (Connection refused at https://sales.example cURL 7)",
            $response->message
        );
        // Whitespace collapsed: no double-spaces / newlines remain in the summary.
        $this->assertStringNotContainsString("\n", $response->message);
        $this->assertStringNotContainsString('  ', $response->message);
        // This is the exact key the degraded-fallback chain matches on.
        $this->assertStringContainsString("couldn't reach remote node", strtolower($response->message));
        $this->assertSame(false, $response->data['success']);
    }

    public function test_node_session_manager_route_to_node_emits_real_unreachable_failure_string(): void
    {
        $node = new AINode();
        $node->slug = 'sales';
        $node->name = 'Sales';
        $node->url = 'https://sales.example';

        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->with('sales')->willReturn($node);

        $router = $this->createMock(NodeRouterService::class);
        $router->method('forwardChat')->willReturn([
            'success' => false,
            'error' => "Connection refused at https://sales.example  \n  cURL 7",
        ]);

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('nsm-fail-route', 5);
        $response = $manager->routeToNode('sales', 'hi', $context, []);

        $this->assertFalse($response->success);
        $this->assertStringContainsString(
            "I couldn't reach remote node 'sales' at https://sales.example (Connection refused at https://sales.example cURL 7)",
            $response->message
        );
    }

    // ---------------------------------------------------------------------
    // NodeSessionManager::continueSession null-resolution edges (priority 4)
    // ---------------------------------------------------------------------

    public function test_continue_session_returns_null_when_node_slug_absent(): void
    {
        $router = $this->createMock(NodeRouterService::class);
        $router->expects($this->never())->method('forwardChat');

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $this->createMock(NodeRegistryService::class),
            $router,
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('nsm-null-a', 5);
        // routed_to_node present but no node_slug.
        $context->set('routed_to_node', ['something' => 'else']);

        $this->assertNull($manager->continueSession('hi', $context, []));
        $this->assertTrue($context->has('routed_to_node')); // untouched
    }

    public function test_continue_session_forgets_routed_node_when_resolution_misses(): void
    {
        $registry = $this->createMock(NodeRegistryService::class);
        $registry->method('getNode')->willReturn(null);
        $registry->method('getAllNodes')->willReturn(new Collection());
        $registry->method('findNodeForCollection')->willReturn(null);

        $router = $this->createMock(NodeRouterService::class);
        $router->expects($this->never())->method('forwardChat');

        $manager = new NodeSessionManager(
            $this->createMock(AIEngineService::class),
            $registry,
            $router,
            $this->createMock(AgentResponseFinalizer::class)
        );

        $context = new UnifiedActionContext('nsm-null-b', 5);
        $context->set('routed_to_node', ['node_slug' => 'ghosts']);

        $this->assertNull($manager->continueSession('hi', $context, []));
        $this->assertFalse($context->has('routed_to_node')); // forgotten on miss
    }

    // ---------------------------------------------------------------------
    // LaravelAgentProcessor CONTINUE_NODE branches (priority 5/4)
    // ---------------------------------------------------------------------

    public function test_processor_continue_node_happy_path_returns_remote_without_ainative(): void
    {
        $context = new UnifiedActionContext('proc-happy', 9);
        $context->set('routed_to_node', ['node_slug' => 'sales']);

        $remote = AgentResponse::success('remote answer', context: $context);

        $nodeSession = Mockery::mock(\LaravelAIEngine\Contracts\Federation\NodeSessionContract::class);
        $nodeSession->shouldReceive('shouldContinueSession')->once()->andReturn(true);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn($remote);

        $aiNative = Mockery::mock(AiNativeRuntime::class);
        $aiNative->shouldReceive('process')->never();

        $processor = $this->processor($context, $nodeSession, $dispatcher, $aiNative);

        $response = $processor->process('continue please', $context->sessionId, 9);

        $this->assertSame($remote, $response);
        $this->assertTrue($response->success);
        $this->assertTrue($context->has('routed_to_node')); // not cleared on success
        $this->assertArrayNotHasKey('fallback_mode', $response->metadata ?? []);
    }

    public function test_processor_continue_node_new_topic_clears_and_falls_to_ainative(): void
    {
        $context = new UnifiedActionContext('proc-newtopic', 9);
        $context->set('routed_to_node', ['node_slug' => 'sales']);
        $context->set('remote_pending_action', ['status' => 'awaiting_input']);
        $context->pendingAction = ['type' => 'remote_node_session'];

        $nodeSession = Mockery::mock(\LaravelAIEngine\Contracts\Federation\NodeSessionContract::class);
        $nodeSession->shouldReceive('shouldContinueSession')->once()->andReturn(false);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->never();

        $localAnswer = AgentResponse::success('fresh local answer', context: $context);
        $aiNative = Mockery::mock(AiNativeRuntime::class);
        $aiNative->shouldReceive('process')->once()->andReturn($localAnswer);

        $processor = $this->processor($context, $nodeSession, $dispatcher, $aiNative);

        $response = $processor->process('list invoices', $context->sessionId, 9);

        $this->assertSame($localAnswer, $response);
        $this->assertFalse($context->has('routed_to_node'));
        $this->assertFalse($context->has('remote_pending_action'));
        $this->assertNull($context->pendingAction);
    }

    public function test_processor_continue_node_null_session_sentinel_falls_through_to_ainative(): void
    {
        $context = new UnifiedActionContext('proc-sentinel', 9);
        $context->set('routed_to_node', ['node_slug' => 'sales']);

        $nodeSession = Mockery::mock(\LaravelAIEngine\Contracts\Federation\NodeSessionContract::class);
        $nodeSession->shouldReceive('shouldContinueSession')->once()->andReturn(true);

        $sentinel = AgentResponse::failure('No routed node session is available to continue.', context: $context);
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturn($sentinel);

        $localAnswer = AgentResponse::success('local handled it', context: $context);
        $aiNative = Mockery::mock(AiNativeRuntime::class);
        $aiNative->shouldReceive('process')->once()->andReturn($localAnswer);

        $processor = $this->processor($context, $nodeSession, $dispatcher, $aiNative);

        $response = $processor->process('hello there', $context->sessionId, 9);

        // Sentinel failure is NOT returned; falls through to AiNative.
        $this->assertSame($localAnswer, $response);
        $this->assertTrue($response->success);
    }

    public function test_processor_continue_node_fallback_but_local_rag_also_fails_returns_original(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', true);

        $context = new UnifiedActionContext('proc-doublefail', 9);
        $context->set('routed_to_node', ['node_slug' => 'sales']);
        $context->set('remote_pending_action', ['status' => 'awaiting_input']);

        $remoteFailure = AgentResponse::failure(self::UNREACHABLE, context: $context);
        $ragFailure = AgentResponse::failure('local rag empty', context: $context);

        $nodeSession = Mockery::mock(\LaravelAIEngine\Contracts\Federation\NodeSessionContract::class);
        $nodeSession->shouldReceive('shouldContinueSession')->once()->andReturn(true);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        // First dispatch = CONTINUE_NODE (unreachable). Second dispatch = SEARCH_RAG (also fails).
        $dispatcher->shouldReceive('dispatch')
            ->twice()
            ->andReturnValues([$remoteFailure, $ragFailure]);

        $aiNative = Mockery::mock(AiNativeRuntime::class);
        $aiNative->shouldReceive('process')->never();

        $processor = $this->processor($context, $nodeSession, $dispatcher, $aiNative);

        $response = $processor->process('follow up', $context->sessionId, 9);

        // Original remote failure returned (no fallback notice prepended).
        $this->assertSame($remoteFailure, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString("couldn't reach remote node", strtolower($response->message));
        $this->assertStringNotContainsString('degraded mode', strtolower($response->message));
        // Cleanup happened before the searchRag attempt.
        $this->assertFalse($context->has('routed_to_node'));
        $this->assertFalse($context->has('remote_pending_action'));
    }

    // ---------------------------------------------------------------------
    // RAGDecisionEngine -> FederatedModelRouter glue (priority 5)
    // ---------------------------------------------------------------------

    public function test_structured_query_routes_to_node_for_each_tool_when_router_bound(): void
    {
        foreach (['db_query', 'db_count', 'db_aggregate', 'model_tool'] as $tool) {
            $this->assertStructuredToolRoutes($tool);
        }
    }

    private function assertStructuredToolRoutes(string $tool): void
    {
        $routerResult = ['success' => true, 'response' => 'from remote node', 'tool' => 'route_to_node'];

        $federated = Mockery::mock(FederatedModelRouter::class);
        $federated->shouldReceive('routeForModel')
            ->once()
            ->withArgs(function (array $params, string $message, string $sessionId, $userId, array $history, array $options): bool {
                return ($params['model'] ?? null) === 'Invoice';
            })
            ->andReturn($routerResult);

        $engine = $this->ragEngineForTool($tool, [
            'success' => false,
            'should_route_to_node' => true,
            'error' => 'model not found locally',
        ], $federated);

        $result = $engine->process('show invoices', 'sq-' . $tool, 42, [], [
            'preclassified_route_mode' => 'structured_query',
        ]);

        $this->assertTrue($result['success'], "tool {$tool} should have routed to node");
        $this->assertSame('from remote node', $result['response']);
    }

    public function test_structured_query_returns_no_node_error_when_router_absent(): void
    {
        $engine = $this->ragEngineForTool('db_query', [
            'success' => false,
            'should_route_to_node' => true,
            'error' => 'model not found locally',
        ], null); // no federated router bound

        $result = $engine->process('show invoices', 'sq-norouter', 42, [], [
            'preclassified_route_mode' => 'structured_query',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('No node found with model Invoice', $result['error']);
    }

    public function test_structured_query_success_never_calls_router(): void
    {
        $federated = Mockery::mock(FederatedModelRouter::class);
        $federated->shouldReceive('routeForModel')->never();

        $engine = $this->ragEngineForTool('db_query', [
            'success' => true,
            'response' => 'local result',
            'tool' => 'db_query',
        ], $federated);

        $result = $engine->process('show invoices', 'sq-ok', 42, [], [
            'preclassified_route_mode' => 'structured_query',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('local result', $result['response']);
    }

    // ---------------------------------------------------------------------
    // RouteToNodeTool description + slug-omission + metadata (priority 3)
    // ---------------------------------------------------------------------

    public function test_route_to_node_tool_description_lists_dedup_nodes(): void
    {
        $metadata = Mockery::mock(NodeMetadataProvider::class);
        $metadata->shouldReceive('getActiveNodes')->andReturn([
            ['slug' => 'sales'],
            ['name' => 'crm'],
            ['slug' => 'sales'],
        ]);

        $tool = new RouteToNodeTool(Mockery::mock(NodeSessionManager::class), $metadata);

        $this->assertStringEndsWith('Available nodes: sales, crm.', $tool->getDescription());
    }

    public function test_route_to_node_tool_description_without_metadata_has_no_nodes_suffix(): void
    {
        $tool = new RouteToNodeTool(Mockery::mock(NodeSessionManager::class));

        $this->assertStringNotContainsString('Available nodes', $tool->getDescription());
    }

    public function test_route_to_node_tool_uses_latest_user_message_when_query_omitted(): void
    {
        $context = new UnifiedActionContext('tool-latest', 1, conversationHistory: [
            ['role' => 'assistant', 'content' => 'hi'],
            ['role' => 'user', 'content' => 'route this to sales'],
        ]);

        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')
            ->once()
            ->with('sales', 'route this to sales', $context, Mockery::any())
            ->andReturn(AgentResponse::success('done', context: $context));

        $tool = new RouteToNodeTool($nodes);
        $result = $tool->execute(['node' => 'sales'], $context);

        $this->assertTrue($result->success);
    }

    public function test_route_to_node_tool_empty_node_rejected_without_routing(): void
    {
        $context = new UnifiedActionContext('tool-empty', 1);
        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldNotReceive('routeToNode');

        $tool = new RouteToNodeTool($nodes);
        $result = $tool->execute(['node' => '  '], $context);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('A target node slug or name is required', $result->error);
    }

    public function test_route_to_node_tool_blank_failure_uses_default_message(): void
    {
        $context = new UnifiedActionContext('tool-blank', 1);
        $nodes = Mockery::mock(NodeSessionManager::class);
        $nodes->shouldReceive('routeToNode')->once()->andReturn(AgentResponse::failure('   ', context: $context));

        $tool = new RouteToNodeTool($nodes);
        $result = $tool->execute(['node' => 'ghost', 'query' => 'anything'], $context);

        $this->assertFalse($result->success);
        $this->assertSame("Could not route to node 'ghost'.", $result->error);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function decision(string $action, array $payload = []): RoutingDecision
    {
        return new RoutingDecision(
            action: $action,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: 'federation flow test decision',
            payload: $payload
        );
    }

    private function routedContext(string $sessionId): UnifiedActionContext
    {
        $context = new UnifiedActionContext($sessionId, 1);
        $context->set('routed_to_node', ['node_slug' => 'sales']);
        $context->set('remote_pending_action', ['status' => 'awaiting_input', 'node_slug' => 'sales']);

        return $context;
    }

    private function processor(
        UnifiedActionContext $context,
        $nodeSession,
        $dispatcher,
        $aiNative
    ): LaravelAgentProcessor {
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->andReturn($context);

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        // finalizeDirect + fallback finalize: return the response unchanged.
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $c, AgentResponse $r, array $o = []): AgentResponse => $r);

        return new LaravelAgentProcessor(
            $contextManager,
            $finalizer,
            $nodeSession,
            $dispatcher,
            $aiNative
        );
    }

    /**
     * Build a real RAGDecisionEngine wired to drive the given structured tool
     * through executeTool, with a mocked structured-data service returning $structuredResult.
     */
    private function ragEngineForTool(string $tool, array $structuredResult, ?FederatedModelRouter $federated): RAGDecisionEngine
    {
        $ai = Mockery::mock(AIEngineService::class);

        $stateService = Mockery::mock(RAGDecisionStateService::class);
        $stateService->shouldReceive('hydrateOptionsWithLastEntityList')->andReturnUsing(
            fn (string $sessionId, array $options): array => $options
        );

        $planner = Mockery::mock(RAGPlannerService::class);
        $planner->shouldReceive('fallbackDecisionForMessage')->andReturn([
            'tool' => $tool,
            'reasoning' => 'test',
            'parameters' => ['model' => 'Invoice'],
        ]);
        $planner->shouldReceive('recordExecutionOutcome');

        $contextService = Mockery::mock(RAGContextService::class);
        $contextService->shouldReceive('build')->andReturn([
            'models' => [['name' => 'Invoice', 'class' => 'App\\Models\\Invoice']],
            'selected_entity' => null,
            'last_entity_list' => null,
        ]);

        $structured = Mockery::mock(RAGStructuredDataService::class);
        $structured->shouldReceive('query')->andReturn($structuredResult);
        $structured->shouldReceive('count')->andReturn($structuredResult);
        $structured->shouldReceive('aggregate')->andReturn($structuredResult);
        $structured->shouldReceive('executeModelTool')->andReturn($structuredResult);

        return new RAGDecisionEngine(
            ai: $ai,
            stateService: $stateService,
            decisionService: $planner,
            structuredDataService: $structured,
            contextService: $contextService,
            federatedModelRouter: $federated
        );
    }
}
