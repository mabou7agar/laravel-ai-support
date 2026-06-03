<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\Contracts\SubAgentHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentGoalPlan;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\SubAgents\CallableSubAgentHandler;
use LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentConversationService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Self-contained coverage for the Sub-agents / goal-agent surface:
 * planner capability matching, execution-service failure/skip semantics,
 * the three concrete sub-agent handlers, RunSubAgentTool delegation and
 * SubAgentConversationService.
 */
class SubAgentsFlowTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext('sub-agent-session', 1);
    }

    /**
     * Build a registry that the container will hand out when ToolCalling /
     * Conversational handlers (or the planner) resolve it.
     */
    private function registry(array $agents): SubAgentRegistry
    {
        $registry = new SubAgentRegistry($this->app, $agents);
        $this->app->instance(SubAgentRegistry::class, $registry);

        return $registry;
    }

    private function goalAgent(SubAgentRegistry $registry): GoalAgentService
    {
        return new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        );
    }

    // ------------------------------------------------------------------
    // Scenario: Capability-match planning end-to-end (no explicit sub_agents)
    // ------------------------------------------------------------------
    public function test_capability_match_planning_end_to_end(): void
    {
        $success = fn (SubAgentTask $task) => SubAgentResult::success($task->id, $task->agentId, 'ok');

        $registry = $this->registry([
            'researcher' => [
                'description' => 'Finds facts',
                'capabilities' => ['research'],
                'keywords' => ['facts', 'find'],
                'handler' => $success,
            ],
            'writer' => [
                'description' => 'Drafts copy',
                'capabilities' => ['write'],
                'keywords' => ['draft'],
                'handler' => $success,
            ],
            'painter' => [
                'capabilities' => ['image'],
                'enabled' => false,
                'handler' => $success,
            ],
        ]);

        $service = $this->goalAgent($registry);
        $target = 'Research and find facts to draft a write-up';
        $response = $service->execute($target, $this->context(), []);

        $this->assertTrue($response->success);
        $this->assertSame('goal_agent', $response->strategy);

        $payload = $response->data;
        $this->assertSame('capability_match', $payload['plan']['metadata']['source']);

        $tasks = $payload['plan']['tasks'];
        // researcher: capability 'research'(+2) + keywords facts/find(+2) = 4
        // writer: capability 'write'(+0, no substring) keyword draft(+1) = 1
        $this->assertSame('researcher', $tasks[0]['agent_id']);
        $this->assertSame('writer', $tasks[1]['agent_id']);
        $this->assertSame('task_1', $tasks[0]['id']);
        $this->assertSame('task_2', $tasks[1]['id']);

        // Disabled painter never planned.
        $agentIds = array_column($tasks, 'agent_id');
        $this->assertNotContains('painter', $agentIds);

        // Objective composed from description + "Target: ".
        $this->assertSame("Finds facts\nTarget: {$target}", $tasks[0]['objective']);

        $this->assertStringContainsString('Target completed.', $response->message);
    }

    // ------------------------------------------------------------------
    // Scenario: Capability-match falls back to 'general'
    // ------------------------------------------------------------------
    public function test_capability_match_falls_back_to_general(): void
    {
        $registry = $this->registry([
            'researcher' => [
                'capabilities' => ['research'],
                'handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'r'),
            ],
            'general' => [
                'handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'fallback'),
            ],
        ]);

        $response = $this->goalAgent($registry)->execute('xyzzy unrelated objective', $this->context(), []);

        $this->assertTrue($response->success);
        $tasks = $response->data['plan']['tasks'];
        $this->assertCount(1, $tasks);
        $this->assertSame('general', $tasks[0]['agent_id']);
        $this->assertSame('capability_match', $response->data['plan']['metadata']['source']);
    }

    public function test_capability_match_no_general_returns_empty_plan_failure(): void
    {
        $registry = $this->registry([
            'researcher' => [
                'capabilities' => ['research'],
                'handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'r'),
            ],
        ]);

        $response = $this->goalAgent($registry)->execute('xyzzy unrelated objective', $this->context(), []);

        $this->assertFalse($response->success);
        $this->assertSame(
            'No sub-agents matched this target. Register sub-agents in ai-agent.sub_agents or pass sub_agents in the request.',
            $response->message
        );
        $this->assertSame('xyzzy unrelated objective', $response->data['target']);
    }

    // ------------------------------------------------------------------
    // Scenario: max_sub_agents slicing
    // ------------------------------------------------------------------
    public function test_max_sub_agents_option_overrides_config_default(): void
    {
        $handler = fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'ok');
        $agents = [];
        foreach (range(1, 6) as $i) {
            $agents["agent{$i}"] = [
                // Each has 'broad' as a capability so all score > 0.
                'capabilities' => ['broad'],
                // Distinct extra keyword counts to force a stable ranking,
                // but all positive so all 6 are candidates.
                'keywords' => array_fill(0, $i, 'broad'),
                'handler' => $handler,
            ];
        }

        $registry = $this->registry($agents);
        $service = $this->goalAgent($registry);

        $sliced = $service->execute('broad target', $this->context(), ['max_sub_agents' => 2]);
        $this->assertTrue($sliced->success);
        $this->assertCount(2, $sliced->data['plan']['tasks']);
        $this->assertSame('capability_match', $sliced->data['plan']['metadata']['source']);

        // Default config max_sub_agents = 5 -> slices 6 down to 5.
        config()->set('ai-agent.goal_agent.max_sub_agents', 5);
        $defaulted = $service->execute('broad target', $this->context(), []);
        $this->assertCount(5, $defaulted->data['plan']['tasks']);
    }

    // ------------------------------------------------------------------
    // Scenario: empty-target guard
    // ------------------------------------------------------------------
    public function test_empty_target_guard(): void
    {
        $registry = $this->registry([
            'researcher' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'r')],
        ]);

        $response = $this->goalAgent($registry)->execute('   ', $this->context(), [
            'sub_agents' => [['agent_id' => 'researcher', 'objective' => 'go']],
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('Agent target is required.', $response->message);
        $this->assertNotSame('goal_agent', $response->strategy);
    }

    // ------------------------------------------------------------------
    // Scenario: CallableSubAgentHandler normalizeResult matrix
    // ------------------------------------------------------------------
    public function test_callable_handler_normalize_result_matrix(): void
    {
        $task = new SubAgentTask(id: 't1', agentId: 'a1', name: 'A1', objective: 'do');
        $ctx = $this->context();

        // (a) ActionResult success
        $r = (new CallableSubAgentHandler(fn () => ActionResult::success('ar-ok', ['k' => 'v'], ['m' => 1])))
            ->handle($task, $ctx);
        $this->assertTrue($r->success);
        $this->assertSame('ar-ok', $r->message);
        $this->assertSame(['k' => 'v'], $r->data);
        $this->assertSame(['m' => 1], $r->metadata);

        // (b) ActionResult failure
        $r = (new CallableSubAgentHandler(fn () => ActionResult::failure('ar-boom')))->handle($task, $ctx);
        $this->assertFalse($r->success);
        $this->assertSame('ar-boom', $r->error);

        // (c) ActionResult needsUserInput
        $r = (new CallableSubAgentHandler(fn () => ActionResult::needsUserInput('need', null, ['required_inputs' => ['x']])))
            ->handle($task, $ctx);
        $this->assertTrue($r->needsUserInput);
        $this->assertSame('need', $r->message);

        // (d) AgentResponse success
        $r = (new CallableSubAgentHandler(fn () => AgentResponse::success('resp-ok', ['d' => 1])))->handle($task, $ctx);
        $this->assertTrue($r->success);
        $this->assertSame('resp-ok', $r->message);

        // (e) raw array success=false with error
        $r = (new CallableSubAgentHandler(fn () => ['success' => false, 'error' => 'boom']))->handle($task, $ctx);
        $this->assertFalse($r->success);
        $this->assertSame('boom', $r->error);

        // (f) raw array needs_user_input
        $r = (new CallableSubAgentHandler(fn () => [
            'needs_user_input' => true,
            'message' => '?',
            'metadata' => ['required_inputs' => ['x']],
        ]))->handle($task, $ctx);
        $this->assertTrue($r->needsUserInput);
        $this->assertSame('?', $r->message);
        $this->assertSame(['x'], $r->metadata['required_inputs']);

        // (g) bare string -> success message + data = string
        $r = (new CallableSubAgentHandler(fn () => 'hello'))->handle($task, $ctx);
        $this->assertTrue($r->success);
        $this->assertSame('hello', $r->message);
        $this->assertSame('hello', $r->data);

        // (h) AgentResponse needsUserInput
        $r = (new CallableSubAgentHandler(fn () => AgentResponse::needsUserInput('which?')))->handle($task, $ctx);
        $this->assertTrue($r->needsUserInput);
        $this->assertSame('which?', $r->message);
    }

    // ------------------------------------------------------------------
    // Scenario: ToolCallingSubAgentHandler failure / needsUserInput matrix
    // ------------------------------------------------------------------
    public function test_tool_calling_handler_failure_and_needs_input_matrix(): void
    {
        $tools = new ToolRegistry();
        $registry = $this->registry([
            'noop' => ['tools' => []],
            'caller' => ['tools' => ['ghost_tool']],
            'validator' => ['tools' => ['validate_tool']],
            'ruimid' => ['tools' => ['rui_tool']],
            'failer' => ['tools' => ['fail_tool']],
        ]);

        $tools->register('validate_tool', $this->tool(validateErrors: ['target required']));
        $tools->register('rui_tool', $this->tool(executeResult: ActionResult::needsUserInput('need email', null, ['required_inputs' => ['email']])));
        $tools->register('fail_tool', $this->tool(executeResult: ActionResult::failure('tool exploded')));

        $handler = new ToolCallingSubAgentHandler($tools, $registry);
        $ctx = $this->context();

        // No tools.
        $r = $handler->handle(new SubAgentTask('t', 'noop', 'Noop', 'go'), $ctx);
        $this->assertFalse($r->success);
        $this->assertSame("Sub-agent 'noop' has no tools to execute.", $r->error);

        // Unregistered tool.
        $r = $handler->handle(new SubAgentTask('t', 'caller', 'Caller', 'go'), $ctx);
        $this->assertFalse($r->success);
        $this->assertSame("Tool 'ghost_tool' is not registered.", $r->error);
        $this->assertArrayHasKey('tool_results', $r->data);

        // validate() returns errors -> needsUserInput.
        $r = $handler->handle(new SubAgentTask('t', 'validator', 'Validator', 'go'), $ctx);
        $this->assertTrue($r->needsUserInput);
        $this->assertSame(['target required'], $r->metadata['required_inputs']);
        $this->assertSame('validate_tool', $r->metadata['tool_name']);

        // execute() requiresUserInput -> needsUserInput bubbles.
        $r = $handler->handle(new SubAgentTask('t', 'ruimid', 'Rui', 'go'), $ctx);
        $this->assertTrue($r->needsUserInput);
        $this->assertSame('need email', $r->message);
        $this->assertSame(['email'], $r->metadata['required_inputs']);

        // execute() failure -> failure.
        $r = $handler->handle(new SubAgentTask('t', 'failer', 'Failer', 'go'), $ctx);
        $this->assertFalse($r->success);
        $this->assertSame('tool exploded', $r->error);
        $this->assertSame('fail_tool', $r->metadata['tool_name']);
    }

    // ------------------------------------------------------------------
    // Scenario: ToolCalling multi-tool sequencing + parametersFor
    // ------------------------------------------------------------------
    public function test_tool_calling_multi_tool_sequencing_and_parameters(): void
    {
        $tools = new ToolRegistry();
        $registry = $this->registry([
            'multi' => ['tools' => ['tool_a', ['name' => 'tool_b', 'parameters' => ['k' => 1]]]],
            'defaulted' => ['tools' => ['tool_a']],
        ]);

        $capturedA = null;
        $capturedB = null;
        $tools->register('tool_a', $this->tool(executeResult: ActionResult::success('a'), onExecute: function ($p) use (&$capturedA) {
            $capturedA = $p;
        }));
        $tools->register('tool_b', $this->tool(executeResult: ActionResult::success('b'), onExecute: function ($p) use (&$capturedB) {
            $capturedB = $p;
        }));

        $handler = new ToolCallingSubAgentHandler($tools, $registry);
        $ctx = $this->context();

        $depResult = SubAgentResult::success('dep_task', 'dep', 'done', ['fact' => 1]);
        $otherResult = SubAgentResult::success('other_task', 'other', 'noise');

        $task = new SubAgentTask(
            id: 'main',
            agentId: 'multi',
            name: 'Multi',
            objective: 'do work',
            input: ['x' => 5],
            dependsOn: ['dep_task']
        );

        $r = $handler->handle($task, $ctx, ['dep_task' => $depResult, 'other_task' => $otherResult]);
        $this->assertTrue($r->success);
        $this->assertSame(2, $r->metadata['tool_count']);

        // tool_a: task input plus previous_results limited to dep_task.
        $this->assertSame(5, $capturedA['x']);
        $this->assertArrayHasKey('dep_task', $capturedA['previous_results']);
        $this->assertArrayNotHasKey('other_task', $capturedA['previous_results']);

        // tool_b: explicit parameters only.
        $this->assertSame(['k' => 1], $capturedB);

        // Variant: empty input, no params -> default {input: objective}.
        $capturedA = null;
        $r = $handler->handle(new SubAgentTask('d', 'defaulted', 'Defaulted', 'objective-text'), $ctx);
        $this->assertTrue($r->success);
        $this->assertSame(['input' => 'objective-text'], $capturedA);
    }

    // ------------------------------------------------------------------
    // Scenario: ConversationalSubAgentHandler conversational + rag modes
    // ------------------------------------------------------------------
    public function test_conversational_handler_conversational_mode(): void
    {
        $mock = Mockery::mock(AgentConversationService::class);
        $capturedPrompt = null;
        $mock->shouldReceive('executeConversational')
            ->once()
            ->andReturnUsing(function (string $prompt) use (&$capturedPrompt) {
                $capturedPrompt = $prompt;

                return AgentResponse::success('conversed');
            });

        $handler = new ConversationalSubAgentHandler($mock);
        $task = new SubAgentTask(
            id: 't1',
            agentId: 'chatter',
            name: 'Chatter',
            objective: 'Discuss the plan',
            input: ['topic' => 'pricing']
        );
        $prev = ['p1' => SubAgentResult::success('p1', 'prior', 'prior message')];

        $r = $handler->handle($task, $this->context(), $prev, ['sub_agent_mode' => 'conversational']);

        $this->assertTrue($r->success);
        $this->assertSame('conversed', $r->message);
        $this->assertStringContainsString('Sub-agent: Chatter', $capturedPrompt);
        $this->assertStringContainsString('Objective: Discuss the plan', $capturedPrompt);
        $this->assertStringContainsString('Input: {"topic":"pricing"}', $capturedPrompt);
        $this->assertStringContainsString('Previous sub-agent results:', $capturedPrompt);
    }

    public function test_conversational_handler_rag_mode_and_reroute_fallback(): void
    {
        $mock = Mockery::mock(AgentConversationService::class);
        $capturedReroute = null;
        $mock->shouldReceive('executeSearchRAG')
            ->once()
            ->andReturnUsing(function ($prompt, $context, $options, $reroute) use (&$capturedReroute) {
                $capturedReroute = $reroute;

                return AgentResponse::success('rag-result');
            });
        $mock->shouldNotReceive('executeConversational');

        $handler = new ConversationalSubAgentHandler($mock);
        // task.metadata.mode='rag' overrides options sub_agent_mode.
        $task = new SubAgentTask(
            id: 't1',
            agentId: 'rag',
            name: 'Rag',
            objective: 'Search docs',
            metadata: ['mode' => 'rag']
        );

        $r = $handler->handle($task, $this->context(), [], ['sub_agent_mode' => 'conversational']);
        $this->assertTrue($r->success);
        $this->assertSame('rag-result', $r->message);

        // The reroute closure exists and yields the reroute-failure response.
        $this->assertIsCallable($capturedReroute);
        $fallback = ($capturedReroute)();
        $this->assertInstanceOf(AgentResponse::class, $fallback);
        $this->assertFalse($fallback->success);
        $this->assertSame('Sub-agent reroute is not available.', $fallback->message);
    }

    public function test_conversational_handler_needs_input_and_failure_mapping(): void
    {
        $task = new SubAgentTask(id: 't1', agentId: 'chatter', name: 'Chatter', objective: 'go');

        $needs = Mockery::mock(AgentConversationService::class);
        $needs->shouldReceive('executeConversational')->andReturn(AgentResponse::needsUserInput('more?'));
        $r = (new ConversationalSubAgentHandler($needs))->handle($task, $this->context());
        $this->assertTrue($r->needsUserInput);
        $this->assertSame('more?', $r->message);

        $fail = Mockery::mock(AgentConversationService::class);
        $fail->shouldReceive('executeConversational')->andReturn(AgentResponse::failure('nope'));
        $r = (new ConversationalSubAgentHandler($fail))->handle($task, $this->context());
        $this->assertFalse($r->success);
        // failure() threads the response message into the SubAgentResult error.
        $this->assertSame('nope', $r->error);
    }

    // ------------------------------------------------------------------
    // Scenario: SubAgentRegistry::resolveHandler branch matrix
    // ------------------------------------------------------------------
    public function test_resolve_handler_branch_matrix(): void
    {
        $instance = new CallableSubAgentHandler(fn () => null);

        $registry = $this->registry([
            'literal_tool' => ['handler' => 'tool', 'tools' => ['t']],
            'literal_tools' => ['handler' => 'tools'],
            'instance' => ['handler' => $instance],
            'classstring' => ['handler' => InlineSubAgentHandlerStub::class],
            'not_a_handler' => ['handler' => InlineNotAHandlerStub::class],
            'null_no_tools' => ['handler' => null],
        ]);

        $this->assertInstanceOf(ToolCallingSubAgentHandler::class, $registry->resolveHandler('literal_tool'));
        $this->assertInstanceOf(ToolCallingSubAgentHandler::class, $registry->resolveHandler('literal_tools'));
        $this->assertSame($instance, $registry->resolveHandler('instance'));
        $this->assertInstanceOf(InlineSubAgentHandlerStub::class, $registry->resolveHandler('classstring'));
        $this->assertNull($registry->resolveHandler('not_a_handler'));
        $this->assertNull($registry->resolveHandler('null_no_tools'));
        $this->assertNull($registry->resolveHandler('unknown_agent'));
    }

    // ------------------------------------------------------------------
    // Scenario: Execution stop_on_failure x critical-flag matrix
    // ------------------------------------------------------------------
    public function test_execution_stop_on_failure_and_critical_matrix(): void
    {
        $registry = $this->registry([
            'failer' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::failure($t->id, $t->agentId, 'boom')],
            'winner' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'won')],
        ]);
        $executor = new SubAgentExecutionService($registry);
        $ctx = $this->context();

        // Plan A: t1 critical fails -> break, t2 never runs.
        $planA = new AgentGoalPlan('go', [
            new SubAgentTask('t1', 'failer', 'F', 'go', critical: true),
            new SubAgentTask('t2', 'winner', 'W', 'go'),
        ]);
        $resultsA = $executor->execute($planA, $ctx, ['stop_on_failure' => true]);
        $this->assertArrayHasKey('t1', $resultsA);
        $this->assertArrayNotHasKey('t2', $resultsA);

        // Plan B: t1 critical:false fails -> continue, t2 runs.
        $planB = new AgentGoalPlan('go', [
            new SubAgentTask('t1', 'failer', 'F', 'go', critical: false),
            new SubAgentTask('t2', 'winner', 'W', 'go'),
        ]);
        $resultsB = $executor->execute($planB, $ctx, ['stop_on_failure' => true]);
        $this->assertFalse($resultsB['t1']->success);
        $this->assertTrue($resultsB['t2']->success);

        // Plan C: t1 critical fails but stop_on_failure=false -> t2 still runs.
        $planC = new AgentGoalPlan('go', [
            new SubAgentTask('t1', 'failer', 'F', 'go', critical: true),
            new SubAgentTask('t2', 'winner', 'W', 'go'),
        ]);
        $resultsC = $executor->execute($planC, $ctx, ['stop_on_failure' => false]);
        $this->assertArrayHasKey('t2', $resultsC);
        $this->assertTrue($resultsC['t2']->success);

        // GoalAgentService wrapping Plan B style -> overall failure summary.
        $response = $this->goalAgent($registry)->execute('go', $this->context(), [
            'stop_on_failure' => true,
            'sub_agents' => [
                ['id' => 't1', 'agent_id' => 'failer', 'objective' => 'go', 'critical' => false],
                ['id' => 't2', 'agent_id' => 'winner', 'objective' => 'go'],
            ],
        ]);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('Target was not completed.', $response->message);
        // Summary lines key off the agent id, not the task id.
        $this->assertStringContainsString('- failer: failed', $response->message);
        $this->assertStringContainsString('- winner: done', $response->message);
    }

    // ------------------------------------------------------------------
    // Scenario: Dependency-failure skip (blocked_by) propagation
    // ------------------------------------------------------------------
    public function test_dependency_failure_skip_propagation(): void
    {
        $registry = $this->registry([
            'failer' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::failure($t->id, $t->agentId, 'boom')],
            'winner' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'won')],
        ]);
        $executor = new SubAgentExecutionService($registry);
        $ctx = $this->context();

        $plan = new AgentGoalPlan('go', [
            new SubAgentTask('t1', 'failer', 'F', 'go', critical: false),
            new SubAgentTask('t2', 'winner', 'W', 'go', dependsOn: ['t1'], critical: false),
            new SubAgentTask('t3', 'winner', 'W', 'go', dependsOn: ['t2'], critical: false),
        ]);

        $results = $executor->execute($plan, $ctx, ['stop_on_failure' => false]);

        $this->assertFalse($results['t1']->success);
        $this->assertFalse($results['t2']->success);
        $this->assertSame('Skipped because dependency t1 failed.', $results['t2']->error);
        $this->assertSame('t1', $results['t2']->metadata['blocked_by']);

        // Cascade: t2 unsuccessful -> t3 blocked by t2.
        $this->assertFalse($results['t3']->success);
        $this->assertSame('t2', $results['t3']->metadata['blocked_by']);

        // Variant: blocked t2 critical + stop_on_failure -> break before t3.
        $plan2 = new AgentGoalPlan('go', [
            new SubAgentTask('t1', 'failer', 'F', 'go', critical: false),
            new SubAgentTask('t2', 'winner', 'W', 'go', dependsOn: ['t1'], critical: true),
            new SubAgentTask('t3', 'winner', 'W', 'go', dependsOn: ['t2'], critical: false),
        ]);
        $results2 = $executor->execute($plan2, $ctx, ['stop_on_failure' => true]);
        $this->assertArrayHasKey('t2', $results2);
        $this->assertArrayNotHasKey('t3', $results2);
    }

    // ------------------------------------------------------------------
    // Scenario: RunSubAgentTool branch coverage
    // ------------------------------------------------------------------
    public function test_run_sub_agent_tool_branches(): void
    {
        $tool = new RunSubAgentTool();
        $ctx = $this->context();

        // Empty target -> needsUserInput.
        $r = $tool->execute([], $ctx);
        $this->assertTrue($r->requiresUserInput());
        $this->assertSame('A target is required to run a sub-agent.', $r->message);
        $this->assertSame(['target'], $r->metadata['required_inputs']);

        // 'input' fallback for target.
        $fake = Mockery::mock(GoalAgentService::class);
        $capturedTarget = null;
        $capturedOptions = null;
        $fake->shouldReceive('execute')
            ->andReturnUsing(function (string $target, $context, array $options) use (&$capturedTarget, &$capturedOptions) {
                $capturedTarget = $target;
                $capturedOptions = $options;

                return AgentResponse::success('done');
            });
        $this->app->instance(GoalAgentService::class, $fake);

        $r = $tool->execute(['input' => 'do X'], $ctx);
        $this->assertSame('do X', $capturedTarget);
        $this->assertTrue($r->success);

        // sub_agents + stop_on_failure pass through to options.
        $tool->execute(['target' => 'T', 'sub_agents' => [['agent_id' => 'x']], 'stop_on_failure' => false], $ctx);
        $this->assertSame([['agent_id' => 'x']], $capturedOptions['sub_agents']);
        $this->assertFalse($capturedOptions['stop_on_failure']);

        // needsUserInput response mapping.
        $needs = Mockery::mock(GoalAgentService::class);
        $needs->shouldReceive('execute')->andReturnUsing(function ($t, $c) {
            $resp = AgentResponse::needsUserInput('more?', null, null, $c, null, ['email']);

            return $resp;
        });
        $this->app->instance(GoalAgentService::class, $needs);
        $r = $tool->execute(['target' => 'T'], $ctx);
        $this->assertTrue($r->requiresUserInput());
        $this->assertSame('run_sub_agent', $r->metadata['agent_strategy']);
        $this->assertSame(['email'], $r->metadata['required_inputs']);

        // failure mapping.
        $failing = Mockery::mock(GoalAgentService::class);
        $failing->shouldReceive('execute')->andReturn(AgentResponse::failure('nope'));
        $this->app->instance(GoalAgentService::class, $failing);
        $r = $tool->execute(['target' => 'T'], $ctx);
        $this->assertFalse($r->success);
        $this->assertSame('run_sub_agent', $r->metadata['agent_strategy']);
    }

    // ------------------------------------------------------------------
    // Scenario: SubAgentConversationService validation + messageForNextTurn
    // ------------------------------------------------------------------
    public function test_conversation_service_validation_and_message_threading(): void
    {
        // Two agents whose handlers thread last_message into their reply.
        $lastSeen = [];
        $registry = $this->registry([
            'agent_a' => [
                'handler' => function (SubAgentTask $t) use (&$lastSeen) {
                    $lastSeen['agent_a'] = $t->input['last_message'] ?? null;

                    // empty message but scalar data -> next message = '42'.
                    return SubAgentResult::success($t->id, $t->agentId, null, 42);
                },
            ],
            'agent_b' => [
                'handler' => function (SubAgentTask $t) use (&$lastSeen) {
                    $lastSeen['agent_b'] = $t->input['last_message'] ?? null;

                    // empty message + non-scalar data -> json encoded.
                    return SubAgentResult::success($t->id, $t->agentId, null, ['k' => 'v']);
                },
            ],
        ]);

        $service = new SubAgentConversationService($registry, $this->app->make(ConversationContextCompactor::class));
        $ctx = $this->context();

        // Empty target.
        $r = $service->run('   ', $ctx, [['agent_id' => 'agent_a'], ['agent_id' => 'agent_b']]);
        $this->assertFalse($r->success);
        $this->assertSame('A target is required.', $r->error);

        // Single participant.
        $r = $service->run('T', $ctx, ['agent_a']);
        $this->assertFalse($r->success);
        $this->assertSame('At least two sub-agents are required.', $r->error);
        $this->assertNotEmpty($r->participants);

        // Two participants, single round, message threading.
        $r = $service->run('T', $ctx, [['agent_id' => 'agent_a'], ['agent_id' => 'agent_b']], ['rounds' => 1]);
        $this->assertTrue($r->success);
        // agent_a saw the seed (target) as last_message; agent_b saw '42'.
        $this->assertSame('T', $lastSeen['agent_a']);
        $this->assertSame('42', $lastSeen['agent_b']);

        // Conversation metadata stored on context.
        $this->assertArrayHasKey('last_sub_agent_conversation', $ctx->metadata);
        $this->assertSame(2, $ctx->metadata['last_sub_agent_conversation']['turn_count']);
    }

    // ------------------------------------------------------------------
    // Scenario: Conversation needsUserInput mid-round halts later participants
    // ------------------------------------------------------------------
    public function test_conversation_needs_user_input_halts_round(): void
    {
        $cRan = false;
        $registry = $this->registry([
            'a' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::success($t->id, $t->agentId, 'a-msg')],
            'b' => ['handler' => fn (SubAgentTask $t) => SubAgentResult::needsUserInput($t->id, $t->agentId, 'b-needs')],
            'c' => ['handler' => function (SubAgentTask $t) use (&$cRan) {
                $cRan = true;

                return SubAgentResult::success($t->id, $t->agentId, 'c-msg');
            }],
        ]);

        $service = new SubAgentConversationService($registry, $this->app->make(ConversationContextCompactor::class));

        // B halts in round 1 -> roundsCompleted = 0, C never runs.
        $r = $service->run('T', $this->context(), [['agent_id' => 'a'], ['agent_id' => 'b'], ['agent_id' => 'c']], ['rounds' => 2]);
        $this->assertTrue($r->success);
        $this->assertSame('needs_user_input', $r->stoppedReason);
        $this->assertSame(0, $r->roundsCompleted);
        $this->assertFalse($cRan);
        $this->assertCount(2, $r->transcript);
        $this->assertArrayHasKey('conversation_id', $r->metadata);
    }

    // ------------------------------------------------------------------
    // Cross-surface: RunSubAgentTool -> plan -> ToolCalling bubbles needsUserInput
    // ------------------------------------------------------------------
    public function test_cross_surface_run_sub_agent_bubbles_tool_needs_input(): void
    {
        $tools = new ToolRegistry();
        $this->app->instance(ToolRegistry::class, $tools);

        $emailRequired = true;
        $tools->register('ask_tool', $this->tool(
            validateErrors: ['need email'],
            // After "providing" the email we flip validation to pass.
        ));

        $registry = $this->registry([
            'collector' => ['tools' => ['ask_tool']],
        ]);

        // Real GoalAgentService resolved with the request-bound registry/executor.
        $this->app->instance(GoalAgentService::class, $this->goalAgent($registry));

        $tool = new RunSubAgentTool();
        $ctx = $this->context();

        $r = $tool->execute(['target' => 'Collect signup', 'sub_agents' => [['agent_id' => 'collector']]], $ctx);
        $this->assertTrue($r->requiresUserInput());
        $this->assertSame('run_sub_agent', $r->metadata['agent_strategy']);
        $this->assertSame(['need email'], $r->metadata['required_inputs']);

        // Follow-up turn: register a passing tool, re-run -> success.
        $tools->register('ask_tool', $this->tool(executeResult: ActionResult::success('captured email')));
        $r2 = $tool->execute(['target' => 'Collect signup', 'sub_agents' => [['agent_id' => 'collector']]], $ctx);
        $this->assertTrue($r2->success);
        $this->assertSame('run_sub_agent', $r2->metadata['agent_strategy']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build an anonymous AgentTool with configurable validate()/execute().
     */
    private function tool(array $validateErrors = [], ?ActionResult $executeResult = null, ?\Closure $onExecute = null): AgentTool
    {
        return new class($validateErrors, $executeResult ?? ActionResult::success('ok'), $onExecute) extends AgentTool {
            public function __construct(
                private array $validateErrors,
                private ActionResult $executeResult,
                private ?\Closure $onExecute
            ) {
            }

            public function getName(): string
            {
                return 'inline_tool';
            }

            public function getDescription(): string
            {
                return 'inline test tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function validate(array $parameters): array
            {
                return $this->validateErrors;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                if ($this->onExecute !== null) {
                    ($this->onExecute)($parameters, $context);
                }

                return $this->executeResult;
            }
        };
    }

    // ------------------------------------------------------------------
    // Security: a per-task tools override (HTTP/LLM-reachable) cannot escalate
    // beyond the agent's DECLARED tools, and open-mode tools stay policy-gated.
    // ------------------------------------------------------------------
    public function test_task_tools_override_cannot_escalate_beyond_declared_tools(): void
    {
        $executed = [];
        $tools = new ToolRegistry();
        $tools->register('safe_tool', $this->tool(executeResult: ActionResult::success('safe'), onExecute: function () use (&$executed): void {
            $executed[] = 'safe_tool';
        }));
        $tools->register('dangerous_tool', $this->tool(executeResult: ActionResult::success('danger'), onExecute: function () use (&$executed): void {
            $executed[] = 'dangerous_tool';
        }));

        // Agent declares ONLY safe_tool — that is its capability bound.
        $registry = $this->registry(['bounded' => ['tools' => ['safe_tool']]]);
        $handler = new ToolCallingSubAgentHandler($tools, $registry);

        // A per-task override tries to add a tool the agent never declared.
        $task = new SubAgentTask('t', 'bounded', 'Bounded', 'go', input: ['tools' => ['safe_tool', 'dangerous_tool']]);
        $result = $handler->handle($task, $this->context());

        $this->assertTrue($result->success);
        $this->assertSame(['safe_tool'], $executed, 'dangerous_tool must be filtered out by the declared-tools bound.');
        $this->assertArrayHasKey('safe_tool', $result->data['tool_results']);
        $this->assertArrayNotHasKey('dangerous_tool', $result->data['tool_results']);
    }

    public function test_open_mode_sub_agent_tools_are_gated_by_execution_policy(): void
    {
        config()->set('ai-agent.execution_policy.tool_deny', ['denied_tool']);

        $executed = [];
        $tools = new ToolRegistry();
        $tools->register('denied_tool', $this->tool(executeResult: ActionResult::success('x'), onExecute: function () use (&$executed): void {
            $executed[] = 'denied_tool';
        }));

        // Agent declares no tools (open mode); the policy deny-list must still gate it.
        $registry = $this->registry(['open' => ['tools' => []]]);
        $handler = new ToolCallingSubAgentHandler($tools, $registry);

        $task = new SubAgentTask('t', 'open', 'Open', 'go', input: ['tools' => ['denied_tool']]);
        $result = $handler->handle($task, $this->context());

        $this->assertFalse($result->success);
        $this->assertTrue($result->metadata['policy_blocked'] ?? false);
        $this->assertSame([], $executed, 'a policy-denied tool must never execute.');
    }
}

class InlineSubAgentHandlerStub implements SubAgentHandler
{
    public function handle(
        SubAgentTask $task,
        UnifiedActionContext $context,
        array $previousResults = [],
        array $options = []
    ): SubAgentResult {
        return SubAgentResult::success($task->id, $task->agentId, 'stub');
    }
}

class InlineNotAHandlerStub
{
}
