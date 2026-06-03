<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Execution;

use Illuminate\Support\Facades\Event;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\ContinueNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\ActionHandlers\RouteToNodeActionHandler;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\Execution\RoutingActionHandlerRegistry;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentExecutionDispatcherTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_dispatches_conversational_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::conversational('Hello.', $context);
        $conversation = Mockery::mock(AgentConversationService::class);

        $conversation->shouldReceive('executeConversational')
            ->once()
            ->with('hello', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::CONVERSATIONAL)))
            ->andReturn($expected);

        $response = $this->dispatcher(conversationService: $conversation)->dispatch(
            $this->decision(RoutingDecisionAction::CONVERSATIONAL),
            'hello',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_dispatches_rag_search_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Found.', context: $context);
        $conversation = Mockery::mock(AgentConversationService::class);
        $reroute = static fn (): AgentResponse => AgentResponse::failure('rerouted', context: $context);

        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->with(
                'search docs',
                $context,
                Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::SEARCH_RAG)),
                Mockery::on(fn ($callback): bool => $callback === $reroute)
            )
            ->andReturn($expected);

        $response = $this->dispatcher(conversationService: $conversation)->dispatch(
            $this->decision(RoutingDecisionAction::SEARCH_RAG),
            'search docs',
            $context,
            reroute: $reroute
        );

        $this->assertSame($expected, $response);
    }

    public function test_blocked_rag_collection_returns_policy_failure_without_execution(): void
    {
        config()->set('ai-agent.execution_policy.rag_collection_deny', ['private_docs']);

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldNotReceive('executeSearchRAG');

        $response = $this->dispatcher(conversationService: $conversation)->dispatch(
            $this->decision(RoutingDecisionAction::SEARCH_RAG),
            'search docs',
            $this->context(),
            ['rag_collection' => 'private_docs']
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_dispatches_tool_decision_and_exposes_rag_callback(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Tool used.', context: $context);
        $action = Mockery::mock(AgentActionExecutionService::class);
        $conversation = Mockery::mock(AgentConversationService::class);

        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->with(
                'lookup after tool',
                $context,
                ['from_tool' => true],
                Mockery::on(static fn ($callback): bool => is_callable($callback))
            )
            ->andReturn($expected);

        $action->shouldReceive('executeUseTool')
            ->once()
            ->withArgs(function (string $toolName, string $message, UnifiedActionContext $ctx, array $options, $searchRag) use ($context, $expected): bool {
                $this->assertSame('data_query', $toolName);
                $this->assertSame('list tasks', $message);
                $this->assertSame($context, $ctx);
                $this->assertSame(['status' => 'open'], $options['tool_params'] ?? null);
                $this->assertTrue($this->hasDecisionMetadata($options, RoutingDecisionAction::USE_TOOL));
                $this->assertSame($expected, $searchRag('lookup after tool', $context, ['from_tool' => true]));

                return true;
            })
            ->andReturn($expected);

        $response = $this->dispatcher(actionExecutionService: $action, conversationService: $conversation)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, [
                'resource_name' => 'data_query',
                'params' => ['status' => 'open'],
            ]),
            'list tasks',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_tool_needing_user_input_emits_progress_instead_of_failed(): void
    {
        Event::fake([AgentRunStreamed::class]);

        $context = $this->context();
        $expected = AgentResponse::fromActionResult(
            ActionResult::needsUserInput('Need customer email.', metadata: [
                'required_inputs' => ['customer_email'],
            ]),
            $context
        );
        $action = Mockery::mock(AgentActionExecutionService::class);
        $audit = Mockery::mock(ProviderToolAuditService::class);

        $action->shouldReceive('executeUseTool')
            ->once()
            ->andReturn($expected);

        $audit->shouldReceive('record')
            ->once()
            ->withArgs(static fn (string $event, mixed $_run, mixed $_approval, array $payload): bool => $event === 'agent_tool.started'
                && ($payload['tool_name'] ?? null) === 'create_customer');

        $audit->shouldReceive('record')
            ->once()
            ->withArgs(static fn (string $event, mixed $_run, mixed $_approval, array $payload): bool => $event === 'agent_tool.progress'
                && ($payload['tool_name'] ?? null) === 'create_customer'
                && ($payload['success'] ?? null) === false
                && ($payload['needs_user_input'] ?? null) === true);

        $response = $this->dispatcher(actionExecutionService: $action, audit: $audit)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, [
                'resource_name' => 'create_customer',
            ]),
            'create customer',
            $context
        );

        $this->assertSame($expected, $response);
        Event::assertDispatched(
            AgentRunStreamed::class,
            static fn (AgentRunStreamed $event): bool => ($event->event['name'] ?? null) === AgentRunEventStreamService::TOOL_PROGRESS
                && ($event->event['payload']['tool_name'] ?? null) === 'create_customer'
                && ($event->event['payload']['needs_user_input'] ?? null) === true
        );
        Event::assertNotDispatched(
            AgentRunStreamed::class,
            static fn (AgentRunStreamed $event): bool => ($event->event['name'] ?? null) === AgentRunEventStreamService::TOOL_FAILED
        );
    }

    public function test_blocked_tool_returns_policy_failure_without_execution(): void
    {
        config()->set('ai-agent.execution_policy.tool_deny', ['dangerous_tool']);

        $action = Mockery::mock(AgentActionExecutionService::class);
        $action->shouldNotReceive('executeUseTool');

        $response = $this->dispatcher(actionExecutionService: $action)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, ['tool_name' => 'dangerous_tool']),
            'run dangerous tool',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_blocked_tool_marks_policy_metadata_and_records_audit(): void
    {
        config()->set('ai-agent.execution_policy.tool_deny', ['dangerous_tool']);

        $action = Mockery::mock(AgentActionExecutionService::class);
        $action->shouldNotReceive('executeUseTool');

        $audit = Mockery::mock(ProviderToolAuditService::class);
        $audit->shouldReceive('record')
            ->once()
            ->withArgs(static fn (string $event, mixed $_run, mixed $_approval, array $payload): bool => $event === 'agent_policy.blocked'
                && ($payload['policy_blocked'] ?? null) === true
                && ($payload['blocked_type'] ?? null) === 'tool'
                && ($payload['blocked_resource'] ?? null) === 'dangerous_tool');

        $response = $this->dispatcher(actionExecutionService: $action, audit: $audit)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, ['tool_name' => 'dangerous_tool']),
            'run dangerous tool',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
        $this->assertTrue($response->metadata['policy_blocked'] ?? false);
        $this->assertSame('tool', $response->metadata['blocked_type'] ?? null);
        $this->assertSame('dangerous_tool', $response->metadata['blocked_resource'] ?? null);
    }

    public function test_blocked_node_marks_policy_metadata(): void
    {
        config()->set('ai-agent.execution_policy.node_deny', ['invoice']);

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldNotReceive('routeToNode');

        $response = $this->dispatcher(nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['node_slug' => 'invoice']),
            'show invoice 5',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertTrue($response->metadata['policy_blocked'] ?? false);
        $this->assertSame('node', $response->metadata['blocked_type'] ?? null);
        $this->assertSame('invoice', $response->metadata['blocked_resource'] ?? null);
    }

    public function test_dispatches_sub_agent_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Goal complete.', context: $context);
        $goalAgent = Mockery::mock(GoalAgentService::class);

        $goalAgent->shouldReceive('execute')
            ->once()
            ->withArgs(function (string $target, UnifiedActionContext $ctx, array $options) use ($context): bool {
                return $target === 'research customer churn'
                    && $ctx === $context
                    && ($options['agent_goal'] ?? null) === true
                    && ($options['target'] ?? null) === 'research customer churn'
                    && ($options['sub_agents'] ?? null) === ['researcher', 'analyst']
                    && $this->hasDecisionMetadata($options, RoutingDecisionAction::RUN_SUB_AGENT);
            })
            ->andReturn($expected);

        $response = $this->dispatcher(goalAgent: $goalAgent)->dispatch(
            $this->decision(RoutingDecisionAction::RUN_SUB_AGENT, [
                'target' => 'research customer churn',
                'sub_agents' => ['researcher', 'analyst'],
            ]),
            'run the team',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_blocked_sub_agent_returns_policy_failure_without_execution(): void
    {
        config()->set('ai-agent.execution_policy.sub_agent_deny', ['blocked_agent']);

        $goalAgent = Mockery::mock(GoalAgentService::class);
        $goalAgent->shouldNotReceive('execute');

        $response = $this->dispatcher(goalAgent: $goalAgent)->dispatch(
            $this->decision(RoutingDecisionAction::RUN_SUB_AGENT, [
                'target' => 'delegate',
                'sub_agents' => ['blocked_agent'],
            ]),
            'delegate',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_dispatches_continue_node_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Node continued.', context: $context);
        $node = Mockery::mock(NodeSessionManager::class);

        $node->shouldReceive('continueSession')
            ->once()
            ->with('next', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::CONTINUE_NODE)))
            ->andReturn($expected);

        $response = $this->dispatcher(nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::CONTINUE_NODE),
            'next',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_continue_node_decision_fails_when_no_session_exists(): void
    {
        $context = $this->context();
        $node = Mockery::mock(NodeSessionManager::class);

        $node->shouldReceive('continueSession')
            ->once()
            ->andReturnNull();

        $response = $this->dispatcher(nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::CONTINUE_NODE),
            'next',
            $context
        );

        $this->assertFalse($response->success);
        $this->assertSame('No routed node session is available to continue.', $response->message);
    }

    public function test_dispatches_route_to_node_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Node routed.', context: $context);
        $node = Mockery::mock(NodeSessionManager::class);

        $node->shouldReceive('routeToNode')
            ->once()
            ->with('invoice', 'show invoice 5', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::ROUTE_TO_NODE)))
            ->andReturn($expected);

        $response = $this->dispatcher(nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, [
                'node_slug' => 'invoice',
            ]),
            'show invoice 5',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_blocked_node_returns_policy_failure_without_execution(): void
    {
        config()->set('ai-agent.execution_policy.node_deny', ['invoice']);

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldNotReceive('routeToNode');

        $response = $this->dispatcher(nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['node_slug' => 'invoice']),
            'show invoice 5',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_node_fallback_sets_structured_fallback_metadata(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', true);

        $context = $this->context();
        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldReceive('routeToNode')
            ->once()
            ->andReturn(AgentResponse::failure("Sorry, I couldn't reach remote node right now.", context: $context));

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->andReturn(AgentResponse::success('Here are local results.', context: $context));

        $response = $this->dispatcher(conversationService: $conversation, nodeSessionManager: $node)->dispatch(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['node_slug' => 'invoice']),
            'show invoice 5',
            $context
        );

        $this->assertTrue($response->success);
        $this->assertTrue($response->metadata['fallback_mode'] ?? false);
        $this->assertSame('remote_node_unreachable', $response->metadata['fallback_reason'] ?? null);
        $this->assertSame('invoice', $response->metadata['original_resource'] ?? null);
        $this->assertStringContainsString('Here are local results.', $response->message);
    }

    public function test_audit_stream_emit_failure_does_not_halt_tool_execution(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Tool used.', context: $context);

        $action = Mockery::mock(AgentActionExecutionService::class);
        $action->shouldReceive('executeUseTool')->once()->andReturn($expected);

        $audit = Mockery::mock(ProviderToolAuditService::class);
        $audit->shouldReceive('record');

        $stream = Mockery::mock(AgentRunEventStreamService::class);
        $stream->shouldReceive('emit')->andThrow(new \RuntimeException('stream down'));
        $this->app->instance(AgentRunEventStreamService::class, $stream);

        \Illuminate\Support\Facades\Log::shouldReceive('channel')->andReturnSelf();
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->atLeast()->once();
        \Illuminate\Support\Facades\Log::shouldReceive('info');
        \Illuminate\Support\Facades\Log::shouldReceive('debug');
        \Illuminate\Support\Facades\Log::shouldReceive('error');

        $response = $this->dispatcher(actionExecutionService: $action, audit: $audit)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, ['resource_name' => 'data_query']),
            'list tasks',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_dispatches_need_user_input_decision(): void
    {
        $context = $this->context();

        $response = $this->dispatcher()->dispatch(
            $this->decision(RoutingDecisionAction::NEED_USER_INPUT, [
                'required_inputs' => ['email'],
            ], 'Need an email.'),
            'continue',
            $context
        );

        $this->assertTrue($response->success);
        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Need an email.', $response->message);
        $this->assertSame(['email'], $response->requiredInputs);
    }

    public function test_dispatches_failure_decision(): void
    {
        $context = $this->context();

        $response = $this->dispatcher()->dispatch(
            $this->decision(RoutingDecisionAction::FAIL, ['code' => 'blocked'], 'Blocked.'),
            'continue',
            $context
        );

        $this->assertFalse($response->success);
        $this->assertSame('Blocked.', $response->message);
        $this->assertSame(['code' => 'blocked'], $response->data);
    }

    public function test_unsupported_decision_action_returns_failure(): void
    {
        $context = $this->context();

        $response = $this->dispatcher()->dispatch(
            $this->decision(RoutingDecisionAction::ABSTAIN),
            'continue',
            $context
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('Unsupported routing decision action [abstain].', $response->message);
    }

    public function test_search_rag_decision_maps_to_expected_dispatcher_handler(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Pipeline search executed.', context: $context);
        $conversation = Mockery::mock(AgentConversationService::class);

        $decision = new RoutingDecision(
            action: RoutingDecisionAction::SEARCH_RAG,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: 'Pipeline selected RAG.'
        );

        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->with(
                'find invoice context',
                $context,
                Mockery::on(fn (array $options): bool => ($options['decision_action'] ?? null) === RoutingDecisionAction::SEARCH_RAG
                    && ($options['decision_source'] ?? null) === RoutingDecisionSource::CLASSIFIER
                    && ($options['decision_reason'] ?? null) === 'Pipeline selected RAG.'),
                Mockery::on(static fn ($callback): bool => is_callable($callback))
            )
            ->andReturn($expected);

        $response = $this->dispatcher(conversationService: $conversation)->dispatch($decision, 'find invoice context', $context);

        $this->assertSame($expected, $response);
    }

    private function dispatcher(
        ?AgentActionExecutionService $actionExecutionService = null,
        ?AgentConversationService $conversationService = null,
        ?NodeSessionManager $nodeSessionManager = null,
        ?GoalAgentService $goalAgent = null,
        ?ProviderToolAuditService $audit = null
    ): AgentExecutionDispatcher {
        $node = $nodeSessionManager ?? Mockery::mock(NodeSessionManager::class);
        $registry = new RoutingActionHandlerRegistry();
        $dispatcher = null;
        $registry->register(new ContinueNodeActionHandler($node));
        $registry->register(new RouteToNodeActionHandler($node, function () use (&$dispatcher): AgentExecutionDispatcher {
            return $dispatcher;
        }));

        return $dispatcher = new AgentExecutionDispatcher(
            $actionExecutionService ?? Mockery::mock(AgentActionExecutionService::class),
            $conversationService ?? Mockery::mock(AgentConversationService::class),
            $registry,
            $goalAgent ?? Mockery::mock(GoalAgentService::class),
            $audit
        );
    }

    private function decision(string $action, array $payload = [], string $reason = 'Matched test decision.'): RoutingDecision
    {
        return new RoutingDecision(
            action: $action,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: $reason,
            payload: $payload
        );
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext('dispatcher-test', 123);
    }

    private function hasDecisionMetadata(array $options, string $action): bool
    {
        return ($options['decision_action'] ?? null) === $action
            && ($options['decision_source'] ?? null) === RoutingDecisionSource::CLASSIFIER
            && ($options['decision_confidence'] ?? null) === 'high'
            && ($options['decision_reason'] ?? null) === 'Matched test decision.';
    }
}
