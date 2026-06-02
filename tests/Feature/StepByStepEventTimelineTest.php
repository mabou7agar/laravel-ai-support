<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * STEP-BY-STEP EVENT TIMELINE — the reference test for "what's happening" UIs.
 *
 * A frontend that wants to render an agent run as a live, step-by-step timeline
 * subscribes to the events the engine persists on the ASYNC run path. This test
 * drives a REAL agent run through {@see RunAgentJob} (real AgentRuntimeManager ->
 * real LaravelAgentRuntime -> real RoutingPipeline -> real AgentExecutionDispatcher
 * -> real AgentActionExecutionService -> real ToolRegistry), with only the leaf
 * IntentRouter mocked so the run deterministically routes to `use_tool` and the
 * registered tool actually executes. It then asserts the ORDERED event timeline
 * captured in $run->metadata['events'] (and ai_agent_run_steps) is exactly the
 * sequence a UI would render:
 *
 *     run.started
 *       -> routing.decided   (action: use_tool)
 *         -> tool.started     (tool_name identifies the tool)
 *         -> tool.completed   (tool_name identifies the tool)
 *     -> run.completed
 *
 * Driven via: RunAgentJob (production async path), NOT the sink fallback.
 */
class StepByStepEventTimelineTest extends TestCase
{
    private const SESSION = 'StepDemo-session';

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic heuristic routing: action-style messages reach the (mocked)
        // IntentRouter stage, conversational ones never do. No AI intent layer.
        config()->set('ai-agent.intent_understanding.mode', 'heuristic');

        // Keep memory/skill machinery quiet so no spurious AI/tool events appear in
        // the timeline we assert on.
        config()->set('ai-agent.conversation_memory.enabled', false);
        config()->set('ai-agent.skills.enabled', false);
        config()->set('ai-agent.skills.intent_matching.enabled', false);

        // Tool audit on so the dispatcher emits tool.started / tool.completed.
        config()->set('ai-engine.provider_tools.audit.enabled', true);
    }

    public function test_run_agent_job_emits_ordered_step_by_step_event_timeline(): void
    {
        // The tool a UI watches execute: it records its run so we can prove it fired.
        $tool = new StepDemoInvoiceTool();
        /** @var ToolRegistry $registry */
        $registry = $this->app->make(ToolRegistry::class);
        $registry->register($tool->getName(), $tool);

        // Only the leaf intent router is faked: it deterministically routes the
        // action message to our registered tool. Everything else is the real stack.
        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->once()
            ->with('Create an invoice for StepDemo Inc for 99.', Mockery::type(UnifiedActionContext::class), Mockery::type('array'))
            ->andReturn([
                'action' => 'use_tool',
                'resource_name' => 'stepdemo_create_invoice',
                'params' => ['customer' => 'StepDemo Inc', 'amount' => 99],
                'reasoning' => 'invoice creation tool',
                'decision_source' => 'router_ai',
            ]);
        $this->app->instance(IntentRouter::class, $intentRouter);

        // Forget cached singletons that captured collaborators at construction so the
        // bound mock IntentRouter is threaded through the real routing pipeline.
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class);
        $this->app->forgetInstance(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class);
        $this->app->forgetInstance(AgentRuntimeContract::class);

        // A persisted run, exactly like the API would enqueue before dispatching.
        $run = app(AgentRunRepository::class)->create([
            'session_id' => self::SESSION,
            'user_id' => '7',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);

        // Drive the REAL runtime through the production async job path.
        $job = new RunAgentJob($run->id, 'Create an invoice for StepDemo Inc for 99.', self::SESSION, '7');
        $job->handle(
            $this->app->make(AgentRuntimeContract::class),
            app(AgentRunRepository::class),
            app(AgentRunStepRepository::class),
            app(AgentRunSafetyService::class)
        );

        $run->refresh();

        // The run completed and the registered tool actually executed once.
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $tool->executions, 'The registered tool must have executed exactly once.');
        $this->assertSame(['customer' => 'StepDemo Inc', 'amount' => 99], $tool->receivedParameters);

        // ---- The timeline a frontend renders, in emission order. ----
        $events = collect($run->metadata['events'] ?? []);
        $names = $events->pluck('name')->all();

        // Every milestone a UI cares about is present, with the exact constant names.
        $this->assertContains(AgentRunEventStreamService::RUN_STARTED, $names);
        $this->assertContains(AgentRunEventStreamService::ROUTING_DECIDED, $names);
        $this->assertContains(AgentRunEventStreamService::TOOL_STARTED, $names);
        $this->assertContains(AgentRunEventStreamService::TOOL_COMPLETED, $names);
        $this->assertContains(AgentRunEventStreamService::RUN_COMPLETED, $names);

        // The headline ordered backbone of the timeline. Other diagnostic events
        // (e.g. routing.stage_started/abstained for earlier stages) may interleave,
        // but these five must appear in this relative order.
        $this->assertSame(
            [
                AgentRunEventStreamService::RUN_STARTED,
                AgentRunEventStreamService::ROUTING_DECIDED,
                AgentRunEventStreamService::TOOL_STARTED,
                AgentRunEventStreamService::TOOL_COMPLETED,
                AgentRunEventStreamService::RUN_COMPLETED,
            ],
            $this->relativeOrder($names, [
                AgentRunEventStreamService::RUN_STARTED,
                AgentRunEventStreamService::ROUTING_DECIDED,
                AgentRunEventStreamService::TOOL_STARTED,
                AgentRunEventStreamService::TOOL_COMPLETED,
                AgentRunEventStreamService::RUN_COMPLETED,
            ]),
            'The step-by-step timeline must render run.started -> routing.decided -> tool.started -> tool.completed -> run.completed.'
        );

        // run.started fires before everything; run.completed closes the timeline.
        $this->assertSame(AgentRunEventStreamService::RUN_STARTED, $names[array_key_first($names)]);
        $this->assertSame(AgentRunEventStreamService::RUN_COMPLETED, end($names));

        // routing.decided identifies the chosen action so the UI can label the step.
        $routingDecided = $events->firstWhere('name', AgentRunEventStreamService::ROUTING_DECIDED);
        $this->assertSame('use_tool', $routingDecided['payload']['decision']['action'] ?? null);

        // tool.started / tool.completed carry the tool identity the UI shows the user.
        $toolStarted = $events->firstWhere('name', AgentRunEventStreamService::TOOL_STARTED);
        $toolCompleted = $events->firstWhere('name', AgentRunEventStreamService::TOOL_COMPLETED);
        $this->assertSame('stepdemo_create_invoice', $toolStarted['payload']['tool_name'] ?? null);
        $this->assertSame('stepdemo_create_invoice', $toolCompleted['payload']['tool_name'] ?? null);
        $this->assertTrue($toolCompleted['payload']['success'] ?? false);

        // run.completed carries the final assistant message for the UI to render.
        $runCompleted = $events->firstWhere('name', AgentRunEventStreamService::RUN_COMPLETED);
        $this->assertSame('Invoice INV-STEPDEMO-1 created for StepDemo Inc.', $runCompleted['payload']['message'] ?? null);

        // The durable step record (ai_agent_run_steps) also carries the run lifecycle
        // bookends so a reconnecting UI can re-anchor the timeline.
        $step = $run->steps()->first();
        $stepNames = collect($step->metadata['events'] ?? [])->pluck('name')->all();
        $this->assertContains(AgentRunEventStreamService::RUN_STARTED, $stepNames);
        $this->assertContains(AgentRunEventStreamService::RUN_COMPLETED, $stepNames);
        // The per-step event log must also retain the routing/tool events appended
        // during processing (the terminal write-back no longer clobbers them).
        $this->assertContains(AgentRunEventStreamService::TOOL_STARTED, $stepNames);
        $this->assertContains(AgentRunEventStreamService::TOOL_COMPLETED, $stepNames);

        // fallbackEvents() is the service's own canonical, de-duplicated, time-ordered
        // merge of the run + step logs — the exact replay a frontend consumes after a
        // reconnect. It must reproduce the same backbone, in the same order.
        $fallbackNames = collect(app(AgentRunEventStreamService::class)->fallbackEvents($run))
            ->pluck('name')
            ->all();
        $this->assertSame(
            [
                AgentRunEventStreamService::RUN_STARTED,
                AgentRunEventStreamService::ROUTING_DECIDED,
                AgentRunEventStreamService::TOOL_STARTED,
                AgentRunEventStreamService::TOOL_COMPLETED,
                AgentRunEventStreamService::RUN_COMPLETED,
            ],
            $this->relativeOrder($fallbackNames, [
                AgentRunEventStreamService::RUN_STARTED,
                AgentRunEventStreamService::ROUTING_DECIDED,
                AgentRunEventStreamService::TOOL_STARTED,
                AgentRunEventStreamService::TOOL_COMPLETED,
                AgentRunEventStreamService::RUN_COMPLETED,
            ]),
            'The canonical reconnect replay must reproduce the timeline backbone in order.'
        );
    }

    /**
     * Assertion-light DOCUMENTATION test: this is the full, ordered event
     * vocabulary a frontend can subscribe to when rendering an agent run.
     *
     * Lifecycle ordering (events relevant to a given run interleave per phase):
     *
     *   run.started                          // the run begins
     *     routing.stage_started              // a routing stage is evaluated
     *     routing.stage_abstained            //   ...stage had no opinion (try next)
     *     routing.decided                    //   ...a stage picked the action
     *     rag.started / rag.sources_found / rag.completed      // search_rag path
     *     tool.started / tool.progress / tool.completed | tool.failed   // use_tool path
     *     sub_agent.started / sub_agent.completed              // run_sub_agent path
     *     approval.required / approval.resolved                // human-in-the-loop
     *     artifact.created                                     // produced output
     *     final_response.token_streamed / final_response.stream_completed  // token stream
     *   run.waiting_input | run.waiting_approval               // non-terminal pause
     *   run.completed | run.failed | run.cancelled | run.expired   // terminal close
     */
    public function test_event_vocabulary_is_the_full_subscribable_timeline(): void
    {
        // The constants on AgentRunEventStreamService ARE the public contract a UI
        // subscribes to. This documents and pins the complete, ordered vocabulary.
        $this->assertSame([
            'run.started',
            'routing.stage_started',
            'routing.stage_abstained',
            'routing.decided',
            'agent.reasoning',
            'plan.updated',
            'rag.started',
            'rag.sources_found',
            'rag.completed',
            'tool.started',
            'tool.progress',
            'tool.completed',
            'tool.failed',
            'sub_agent.started',
            'sub_agent.completed',
            'approval.required',
            'approval.resolved',
            'artifact.created',
            'final_response.token_streamed',
            'final_response.stream_completed',
            'run.waiting_input',
            'run.waiting_approval',
            'run.completed',
            'run.failed',
            'run.cancelled',
            'run.expired',
        ], app(AgentRunEventStreamService::class)->names());
    }

    /**
     * Filter $names down to only the entries in $wanted, preserving emission order.
     * Lets us assert the relative order of the timeline backbone without being
     * brittle about other diagnostic events that interleave.
     *
     * @param array<int, string> $names
     * @param array<int, string> $wanted
     * @return array<int, string>
     */
    private function relativeOrder(array $names, array $wanted): array
    {
        return array_values(array_filter($names, static fn (string $n): bool => in_array($n, $wanted, true)));
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}

/**
 * Registered tool fixture. Records executions so the timeline test can prove the
 * tool actually ran behind the tool.started / tool.completed events.
 */
class StepDemoInvoiceTool extends AgentTool
{
    public int $executions = 0;

    /** @var array<string, mixed> */
    public array $receivedParameters = [];

    public function getName(): string
    {
        return 'stepdemo_create_invoice';
    }

    public function getDescription(): string
    {
        return 'Create an invoice for a customer for a given amount.';
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
            message: "Invoice INV-STEPDEMO-1 created for {$customer}.",
            data: [
                'invoice_id' => 'INV-STEPDEMO-1',
                'customer' => $customer,
                'amount' => $parameters['amount'] ?? null,
            ],
            metadata: ['agent_strategy' => 'tool']
        );
    }
}
