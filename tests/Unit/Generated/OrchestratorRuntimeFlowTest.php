<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Runtime-integration coverage for the three deferred AiNative orchestrator
 * scenarios, driven through the REAL AiNativeRuntime graph (no hand-mocked
 * collaborators). The harness mirrors AiNativeRuntimeTest::runtime() exactly:
 * the planner (AIEngineService) is the only mock and replays an ordered list of
 * canned plan JSON; every other node — task_frame state, suggested-tool
 * continuation, ToolResultAuthority, the ask/tool/parallel/pending handlers — is
 * the genuine wiring. Never a real LLM/network call.
 *
 * Scenarios:
 *  1. Loop exhaustion -> currentPayloadConfirmationResponse pending-write fallback.
 *  2. Parallel batch + suggested-tool continuation + reasoning/plan-timeline in one run.
 *  3. AiNativeAskUserActionHandler continueLoop-vs-response branches.
 */
class OrchestratorRuntimeFlowTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Scenario 1: loop-exhaustion currentPayloadConfirmationResponse fallback
    // ---------------------------------------------------------------------

    public function test_loop_exhaustion_falls_back_to_current_payload_confirmation_for_pending_write(): void
    {
        // A "create invoice" message seeds the active skill (final tool =
        // create_invoice, which requiresConfirmation). Every planning round emits
        // the SAME ask_user plan that (a) carries a complete invoice draft in
        // data.current_payload — rememberPlanPayload writes it into
        // task_frame.current_payload — and (b) has NO required_inputs. Because a
        // ready current_payload exists and the final tool has not run yet, the ask
        // handler's needsFinalToolBeforeAsk gate sends every turn back into the
        // loop (continueLoop). The loop never returns, exhausts max_steps, and the
        // terminal currentPayloadConfirmationResponse turns the pending draft into a
        // confirmation prompt for create_invoice.
        config()->set('ai-agent.ai_native.max_steps', 3);

        $askPlan = [
            'action' => 'ask_user',
            'message' => 'Shall I proceed with the invoice?',
            'data' => [
                'current_payload' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 1, 'unit_price' => 1000],
                    ],
                ],
            ],
        ];

        $toolLog = [];
        $runtime = $this->runtime(array_fill(0, 3, $askPlan), $toolLog);

        // A prior successful lookup_customer authorizes customer_id=501 through the
        // ToolResultAuthority guard, so the terminal confirmation's payload
        // validation passes. This is real authority state (a recorded tool result),
        // not a hand-mocked collaborator.
        $context = new UnifiedActionContext('orchestrator-runtime-payload-confirmation', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501, 'name' => 'Ahmed'],
                        ],
                    ],
                ],
            ],
        ]);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        // Terminal fallback produced a confirmation (not the generic
        // "I need more information" nor a validation error) for the final tool.
        $this->assertTrue($response->needsUserInput);
        $this->assertSame('create_invoice', $response->data['pending_tool']['name'] ?? null);
        $this->assertSame(501, $response->data['pending_tool']['params']['customer_id'] ?? null);
        $this->assertNotSame('I need more information to continue.', $response->message);

        // The pending write is held — create_invoice never actually executed.
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        // The draft survives on the task frame for the confirming turn.
        $this->assertSame(
            501,
            $context->metadata['ai_native']['task_frame']['current_payload']['customer_id'] ?? null
        );
        $this->assertSame(
            'create_invoice',
            $context->metadata['ai_native']['pending_tool']['name'] ?? null
        );
    }

    public function test_pending_current_payload_confirmation_resumes_and_writes_on_approval(): void
    {
        // Companion to the above: confirming the surfaced pending write executes
        // create_invoice exactly once. Proves the exhaustion fallback parks a real,
        // resumable pending_tool (not a dead-end message).
        config()->set('ai-agent.ai_native.max_steps', 3);

        $askPlan = [
            'action' => 'ask_user',
            'message' => 'Shall I proceed with the invoice?',
            'data' => [
                'current_payload' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 1, 'unit_price' => 1000],
                    ],
                ],
            ],
        ];

        $toolLog = [];
        // Turn 1 consumes 3 plans (exhaustion); turn 2 ("confirm") executes the
        // pending write without planning. zeroOrMoreTimes keeps the mock relaxed.
        $runtime = $this->runtime(array_fill(0, 3, $askPlan), $toolLog, generateTimes: null);

        $context = new UnifiedActionContext('orchestrator-runtime-payload-resume', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501, 'name' => 'Ahmed'],
                        ],
                    ],
                ],
            ],
        ]);

        $turn1 = $runtime->process('Create invoice for Ahmed', $context);
        $this->assertTrue($turn1->needsUserInput);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name'] ?? null);

        $turn2 = $runtime->process('confirm', $context);
        $this->assertTrue($turn2->success);
        $this->assertFalse($turn2->needsUserInput);
        $this->assertSame('Invoice created.', $turn2->message);
        $this->assertCount(1, $toolLog['create_invoice']);
        $this->assertSame(501, $toolLog['create_invoice'][0]['customer_id']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Scenario 2: parallel batch + suggested-tool continuation +
    // reasoning_trace + plan_timeline composed in ONE run
    // ---------------------------------------------------------------------

    public function test_parallel_batch_with_suggested_tool_continuation_and_reasoning_and_plan_timeline(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.expose_reasoning', true);
        config()->set('ai-agent.ai_native.plan_timeline', true);

        $toolLog = [];
        $runtime = $this->runtime([
            // Round 1: a parallel read batch. lookup_vendor('CHAIN-1') returns a
            // needsUserInput result carrying a suggested_tool plus a current_payload.
            // That marks a real suggested_tool_continuation and the batch continues
            // the loop. The sibling lookup_customer read runs first, fixing dispatch
            // order.
            [
                'action' => 'tool_call',
                'message' => 'Looking up the customer and vendor together.',
                'reasoning' => 'Fan out the two independent reads',
                'steps' => ['lookup customer', 'lookup vendor', 'summarize'],
                'tool_calls' => [
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                    ['tool' => 'lookup_vendor', 'arguments' => ['query' => 'CHAIN-1']],
                ],
            ],
            // The next loop pass is NOT a planning call: the runtime's suggested-tool
            // continuation runs first and abandons (the suggested tool is
            // unavailable), clearing the continuation. Only then is this final plan
            // requested — it terminates the run and contributes the closing
            // reasoning/step.
            [
                'action' => 'final',
                'message' => 'Customer and vendor resolved.',
                'reasoning' => 'Both lookups resolved, summarizing',
                'steps' => ['summarize'],
            ],
        ], $toolLog, generateTimes: null);

        $context = new UnifiedActionContext('orchestrator-runtime-parallel-suggested', 77);
        $response = $runtime->process(
            'Look up Ahmed and the CHAIN-1 vendor, then summarize',
            $context,
            ['parallel_tools' => true]
        );

        $this->assertTrue($response->success);
        $this->assertSame('Customer and vendor resolved.', $response->message);

        // Dispatch ordering: the parallel batch ran the customer read, then the
        // vendor read (whose result marked the continuation).
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('CHAIN-1', $toolLog['lookup_vendor'][0]['query']);
        $this->assertArrayNotHasKey('lookup_location', $toolLog);

        // The suggested-tool continuation genuinely fired and then resolved: it was
        // marked from the vendor read and abandoned once the suggested tool proved
        // unavailable. Both real feedback markers are present.
        $feedbackReasons = array_column((array) ($context->metadata['ai_native']['runtime_feedback'] ?? []), 'reason');
        $this->assertContains('suggested_tool_continuation', $feedbackReasons);
        $this->assertContains('suggested_tool_continuation_abandoned', $feedbackReasons);
        // The continuation cleared, so it does not leak into the next turn.
        $this->assertArrayNotHasKey('suggested_tool_continuation', $context->metadata['ai_native']);

        // Reasoning + plan timeline composed across the whole run land on metadata.
        $this->assertSame(
            ['Fan out the two independent reads', 'Both lookups resolved, summarizing'],
            $response->metadata['reasoning_trace'] ?? null
        );
        $this->assertSame(['summarize'], $response->metadata['plan']['steps'] ?? null);
        $this->assertSame(1, $response->metadata['plan']['current'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Scenario 3: AiNativeAskUserActionHandler continueLoop-vs-response branches
    // ---------------------------------------------------------------------

    public function test_ask_user_with_recent_context_is_gated_back_into_loop(): void
    {
        // continueLoop branch: an active skill plus pre-existing recent context
        // (a completed write on the task frame). An ask_user plan with no structured
        // required_inputs trips needsRecentContextBeforeAsk, which records the
        // 'recent_context_available' feedback and continues the loop instead of
        // surfacing the question. With identical plans the loop exhausts into the
        // generic terminal fallback — the ask question itself is never returned.
        config()->set('ai-agent.ai_native.max_steps', 2);

        $askPlan = [
            'action' => 'ask_user',
            'message' => 'Which customer should I use?',
        ];

        $toolLog = [];
        $runtime = $this->runtime(array_fill(0, 2, $askPlan), $toolLog);

        $context = new UnifiedActionContext('orchestrator-runtime-ask-recent-context', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                    'completed_writes' => [
                        ['tool' => 'create_customer', 'label' => 'Ahmed', 'outcome' => 'created'],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('Create invoice for Ahmed', $context);

        // The ask question was gated; the loop fell through to the generic fallback.
        $this->assertTrue($response->needsUserInput);
        $this->assertNotSame('Which customer should I use?', $response->message);
        $this->assertSame('I need more information to continue.', $response->message);

        // The genuine gate fired and recorded its feedback marker.
        $this->assertContains(
            'recent_context_available',
            array_column((array) ($context->metadata['ai_native']['runtime_feedback'] ?? []), 'reason')
        );
        $this->assertSame([], $toolLog);
    }

    public function test_ask_user_without_skill_or_context_returns_the_question_directly(): void
    {
        // response branch: no active skill, no recent context, and the plan carries
        // structured required_inputs. The action-intent guard treats this as a real
        // action turn (the message asks to "create"), none of the lookup / recent /
        // final-tool gates apply, so the handler returns the ask_user question
        // verbatim as a needsUserInput response on the first round.
        config()->set('ai-agent.ai_native.max_steps', 6);

        $askPlan = [
            'action' => 'ask_user',
            'message' => 'What is the new gadget name?',
            'required_inputs' => [
                ['name' => 'gadget_name', 'type' => 'text', 'required' => true],
            ],
        ];

        $toolLog = [];
        // Only one planning round should be needed; pin it to prove no looping.
        $runtime = $this->runtime([$askPlan], $toolLog, generateTimes: 1);

        // A message that has action intent ("create") but matches NO registered
        // skill, so seedActiveTask leaves the frame empty and no gate applies.
        $context = new UnifiedActionContext('orchestrator-runtime-ask-direct', 77);
        $response = $runtime->process('Create a brand new gadget for me', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What is the new gadget name?', $response->message);
        $this->assertSame([
            ['name' => 'gadget_name', 'type' => 'text', 'required' => true],
        ], $response->requiredInputs);
        $this->assertSame([], $toolLog);
    }

    // ---------------------------------------------------------------------
    // Harness — mirrors AiNativeRuntimeTest::runtime() (rich tool/skill graph),
    // parameterized like OrchestratorFlowTest::runtime() for generate count.
    // ---------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, mixed> $toolLog
     * @param int|null $generateTimes Expected generate() call count; null = zeroOrMoreTimes.
     */
    private function runtime(array $plans, array &$toolLog, ?int $generateTimes = null): AiNativeRuntime
    {
        $registry = new ToolRegistry();

        $registry->register('lookup_customer', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'lookup_customer';
            }

            public function getDescription(): string
            {
                return 'Search for a customer.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['lookup_customer'][] = $parameters;

                return ActionResult::success('Customer found.', [
                    'found' => true,
                    'id' => 501,
                    'name' => 'Ahmed',
                    'email' => 'ahmed@example.com',
                ]);
            }
        });

        $registry->register('create_invoice', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_invoice';
            }

            public function getDescription(): string
            {
                return 'Create an invoice.';
            }

            public function getParameters(): array
            {
                return [
                    'customer_id' => ['type' => 'integer', 'required' => false],
                    'customer_name' => ['type' => 'string', 'required' => false],
                    'items' => ['type' => 'array', 'required' => true],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function validate(array $parameters): array
            {
                $errors = parent::validate($parameters);

                if (($parameters['customer_id'] ?? null) === null
                    && trim((string) ($parameters['customer_name'] ?? '')) === '') {
                    $errors[] = 'Missing required parameter: customer_id';
                }

                return array_values(array_unique($errors));
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_invoice'][] = $parameters;

                return ActionResult::success('Invoice created.', ['id' => 9001]);
            }
        });

        $registry->register('lookup_vendor', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'lookup_vendor';
            }

            public function getDescription(): string
            {
                return 'Search for a vendor.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['lookup_vendor'][] = $parameters;

                if (($parameters['query'] ?? null) === 'CHAIN-1') {
                    // Suggest a tool that is NOT registered: the continuation is
                    // marked, then deterministically abandoned on the next loop pass
                    // (no executable suggested tool), which clears it so the closing
                    // final plan can terminate the run. This is the real
                    // "unavailable suggested tool" runtime path.
                    return ActionResult::needsUserInput('Resolve location before creating contract.', [
                        'current_payload' => [
                            'vendor_code' => 'CHAIN-1',
                            'location_name' => 'Warehouse A',
                        ],
                        'missing_fields' => ['location_id'],
                        'suggested_tool' => 'unregistered_locator',
                    ]);
                }

                return ActionResult::success('Vendor found.', [
                    'found' => true,
                    'id' => 701,
                    'vendor_name' => $parameters['query'],
                ]);
            }
        });

        $registry->register('lookup_location', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'lookup_location';
            }

            public function getDescription(): string
            {
                return 'Search for a location.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['lookup_location'][] = $parameters;

                if (str_contains((string) ($parameters['query'] ?? ''), 'Missing')) {
                    return ActionResult::success('Location not found.', ['found' => false]);
                }

                return ActionResult::success('Location found.', [
                    'found' => true,
                    'id' => 801,
                    'location_name' => $parameters['query'],
                ]);
            }
        });

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices.',
                triggers: ['create invoice'],
                tools: ['lookup_customer', 'create_invoice'],
                metadata: [
                    'target_json' => [
                        'customer_id' => null,
                        'customer_name' => null,
                        'items' => [],
                    ],
                    'relations' => [
                        [
                            'name' => 'customer',
                            'field' => 'customer_id',
                            'lookup_tool' => 'lookup_customer',
                            'lookup_fields' => ['customer_name'],
                        ],
                    ],
                    'final_tool' => 'create_invoice',
                ]
            ),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $expectation = $ai->shouldReceive('generate')
            ->andReturn(...array_map(
                static fn (array $plan): AIResponse => AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
                $plans
            ));

        if ($generateTimes !== null) {
            $expectation->times($generateTimes);
        } else {
            $expectation->zeroOrMoreTimes();
        }

        return new AiNativeRuntime(
            $ai,
            $registry,
            $skills,
            app(IntentSignalService::class)
        );
    }
}
