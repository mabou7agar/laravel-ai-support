<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Execution;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\RoutingStageContract;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentExecutionDispatcherTest extends UnitTestCase
{
    public function test_dispatches_conversational_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::conversational('Hello.', $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('executeConversational')
            ->once()
            ->with('hello', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::CONVERSATIONAL)))
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
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
        $execution = Mockery::mock(AgentExecutionFacade::class);
        $reroute = static fn (): AgentResponse => AgentResponse::failure('rerouted', context: $context);

        $execution->shouldReceive('executeSearchRag')
            ->once()
            ->with(
                'search docs',
                $context,
                Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::SEARCH_RAG)),
                Mockery::on(fn ($callback): bool => $callback === $reroute)
            )
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
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

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldNotReceive('executeSearchRag');

        $response = $this->dispatcher($execution)->dispatch(
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
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('executeSearchRag')
            ->once()
            ->with(
                'lookup after tool',
                $context,
                ['from_tool' => true],
                Mockery::on(static fn ($callback): bool => is_callable($callback))
            )
            ->andReturn($expected);

        $execution->shouldReceive('executeUseTool')
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

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, [
                'resource_name' => 'data_query',
                'params' => ['status' => 'open'],
            ]),
            'list tasks',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_blocked_tool_returns_policy_failure_without_execution(): void
    {
        config()->set('ai-agent.execution_policy.tool_deny', ['dangerous_tool']);

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldNotReceive('executeUseTool');

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::USE_TOOL, ['tool_name' => 'dangerous_tool']),
            'run dangerous tool',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
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

    public function test_dispatches_start_collector_decision_and_exposes_node_route_callback(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Collector started.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('routeToNode')
            ->once()
            ->with('invoice', 'route invoice', $context, ['source' => 'collector'])
            ->andReturn($expected);

        $execution->shouldReceive('executeStartCollector')
            ->once()
            ->withArgs(function (string $collectorName, string $message, UnifiedActionContext $ctx, array $options, $routeToNode) use ($context, $expected): bool {
                $this->assertSame('invoice_collector', $collectorName);
                $this->assertSame('create invoice', $message);
                $this->assertSame($context, $ctx);
                $this->assertTrue($this->hasDecisionMetadata($options, RoutingDecisionAction::START_COLLECTOR));
                $this->assertSame($expected, $routeToNode('invoice', 'route invoice', $context, ['source' => 'collector']));

                return true;
            })
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::START_COLLECTOR, [
                'resource_name' => 'invoice_collector',
            ]),
            'create invoice',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_dispatches_continue_collector_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::needsUserInput('Next field.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('continueCollectorSession')
            ->once()
            ->with('yes', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::CONTINUE_COLLECTOR)))
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::CONTINUE_COLLECTOR),
            'yes',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_dispatches_continue_node_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Node continued.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('continueRoutedSession')
            ->once()
            ->with('next', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::CONTINUE_NODE)))
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::CONTINUE_NODE),
            'next',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_continue_node_decision_fails_when_no_session_exists(): void
    {
        $context = $this->context();
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('continueRoutedSession')
            ->once()
            ->andReturnNull();

        $response = $this->dispatcher($execution)->dispatch(
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
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('routeToNode')
            ->once()
            ->with('invoice', 'show invoice 5', $context, Mockery::on(fn (array $options): bool => $this->hasDecisionMetadata($options, RoutingDecisionAction::ROUTE_TO_NODE)))
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
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

        $execution = Mockery::mock(AgentExecutionFacade::class);
        $execution->shouldNotReceive('routeToNode');

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::ROUTE_TO_NODE, ['node_slug' => 'invoice']),
            'show invoice 5',
            $this->context()
        );

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_dispatches_continue_run_decision(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Run resumed.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('executeResumeSession')
            ->once()
            ->with($context)
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::CONTINUE_RUN),
            'continue',
            $context
        );

        $this->assertSame($expected, $response);
    }

    public function test_dispatches_pause_and_handle_decision_and_exposes_rag_callback(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Paused and searched.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);

        $execution->shouldReceive('executeSearchRag')
            ->once()
            ->with(
                'search after pause',
                $context,
                ['paused' => true],
                Mockery::on(static fn ($callback): bool => is_callable($callback))
            )
            ->andReturn($expected);

        $execution->shouldReceive('executePauseAndHandle')
            ->once()
            ->withArgs(function (string $message, UnifiedActionContext $ctx, array $options, $searchRag) use ($context, $expected): bool {
                $this->assertSame('new question', $message);
                $this->assertSame($context, $ctx);
                $this->assertTrue($this->hasDecisionMetadata($options, RoutingDecisionAction::PAUSE_AND_HANDLE));
                $this->assertSame($expected, $searchRag('search after pause', $context, ['paused' => true]));

                return true;
            })
            ->andReturn($expected);

        $response = $this->dispatcher($execution)->dispatch(
            $this->decision(RoutingDecisionAction::PAUSE_AND_HANDLE),
            'new question',
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

    public function test_pipeline_decision_maps_to_expected_dispatcher_handler(): void
    {
        $context = $this->context();
        $expected = AgentResponse::success('Pipeline search executed.', context: $context);
        $execution = Mockery::mock(AgentExecutionFacade::class);
        $pipeline = new RoutingPipeline([
            new class implements RoutingStageContract {
                public function name(): string
                {
                    return 'test_pipeline_stage';
                }

                public function decide(string $message, UnifiedActionContext $context, array $options = []): ?RoutingDecision
                {
                    return new RoutingDecision(
                        action: RoutingDecisionAction::SEARCH_RAG,
                        source: RoutingDecisionSource::CLASSIFIER,
                        confidence: 'high',
                        reason: 'Pipeline selected RAG.'
                    );
                }
            },
        ]);

        $execution->shouldReceive('executeSearchRag')
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

        $trace = $pipeline->decide('find invoice context', $context);
        $response = $this->dispatcher($execution)->dispatch($trace->selected, 'find invoice context', $context);

        $this->assertSame($expected, $response);
    }

    private function dispatcher(
        ?AgentExecutionFacade $execution = null,
        ?GoalAgentService $goalAgent = null
    ): AgentExecutionDispatcher {
        return new AgentExecutionDispatcher(
            $execution ?? Mockery::mock(AgentExecutionFacade::class),
            $goalAgent ?? Mockery::mock(GoalAgentService::class)
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
