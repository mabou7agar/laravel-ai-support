<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;
use RuntimeException;

/**
 * Self-contained runtime-loop tests for the AiNative orchestrator
 * (AiNativeRuntime::process). Exercises the planner error/throw fallbacks,
 * loop-exhaustion terminal responses, reasoning trace, plan timeline, parallel
 * batches, the approval-of-completed guard boundary, and best-effort conversation
 * compaction. Mirrors the existing AiNativeRuntimeTest harness exactly (mocked
 * AIEngineService returning canned plan JSON; never a real LLM call).
 */
class OrchestratorFlowTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // nextPlan() error / throw fallbacks (P5)
    // ---------------------------------------------------------------------

    public function test_planner_throwable_surfaces_exception_message_as_ask_user(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([], $toolLog, generate: function () {
            throw new RuntimeException('Upstream LLM 503');
        }, generateTimes: 1);

        // A non-skill, action-intent message: the synthetic ask_user plan surfaces
        // directly rather than being gated by an active-skill lookup requirement.
        $context = new UnifiedActionContext('orchestrator-planner-throws', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Upstream LLM 503', $response->message);
        $this->assertSame([], $toolLog);
        // Did not fall through to the generic terminal fallback.
        $this->assertNotSame('I need more information to continue.', $response->message);
    }

    public function test_planner_throwable_with_empty_message_surfaces_runtime_failed(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([], $toolLog, generate: function () {
            throw new RuntimeException('');
        }, generateTimes: 1);

        $context = new UnifiedActionContext('orchestrator-planner-throws-empty', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('AI runtime failed.', $response->message);
        $this->assertSame([], $toolLog);
    }

    public function test_planner_unsuccessful_response_surfaces_get_error_as_ask_user(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([], $toolLog, generate: static function (): AIResponse {
            return AIResponse::error('rate limited', 'openai', 'gpt-4o-mini');
        }, generateTimes: 1);

        $context = new UnifiedActionContext('orchestrator-planner-error', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('rate limited', $response->message);
        $this->assertSame([], $toolLog);
    }

    public function test_planner_unsuccessful_response_null_error_falls_back_to_runtime_failed(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        // AIResponse::error requires a non-null error string, so build a failed
        // response whose getError() is null to drive the null-coalesce fallback.
        $runtime = $this->runtime([], $toolLog, generate: static function (): AIResponse {
            return new AIResponse(
                content: '',
                engine: null,
                model: null,
                success: false,
                error: null
            );
        }, generateTimes: 1);

        $context = new UnifiedActionContext('orchestrator-planner-null-error', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('AI runtime failed.', $response->message);
        $this->assertSame([], $toolLog);
    }

    // ---------------------------------------------------------------------
    // Loop-exhaustion terminal fallbacks
    // ---------------------------------------------------------------------

    public function test_loop_exhaustion_surfaces_last_tool_validation_failure(): void
    {
        // Repeatedly emit the same create_invoice tool_call with NO customer id /
        // name so validate() fails identically each step. The handler records the
        // failure + retry feedback once, returns false on the next identical
        // attempt, and the loop exhausts into the validation-error fallback.
        config()->set('ai-agent.ai_native.max_steps', 4);

        $invoicePlan = [
            'action' => 'tool_call',
            'tool' => 'create_invoice',
            'arguments' => [
                'items' => [
                    ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 1, 'unit_price' => 1000],
                ],
            ],
            'message' => 'Creating the invoice.',
        ];

        $toolLog = [];
        $runtime = $this->runtime(array_fill(0, 4, $invoicePlan), $toolLog);

        $context = new UnifiedActionContext('orchestrator-validation-exhaustion', 77);
        $response = $runtime->process('Create an invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Missing required parameter: customer_id', $response->message);
        // The terminal validation fallback threads the offending tool name into data.
        $this->assertSame('create_invoice', $response->data['tool_name'] ?? null);
        // Distinct from the bare generic fallback.
        $this->assertNotSame('I need more information to continue.', $response->message);
        // The invoice write never executed.
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_loop_exhaustion_with_gated_ask_user_returns_generic_fallback(): void
    {
        // An active create_invoice skill with available lookup tools: every ask_user
        // plan (carrying required_inputs but no lookup yet) is gated back into the
        // loop by needsLookupBeforeAsk. No current_payload is ever seeded and no
        // tool validation failure is recorded, so the loop exhausts straight into
        // the lowest-priority generic terminal fallback.
        config()->set('ai-agent.ai_native.max_steps', 3);

        $askPlan = [
            'action' => 'ask_user',
            'message' => 'Which customer email should I use?',
            'required_inputs' => [
                ['name' => 'customer_email', 'type' => 'text', 'required' => true],
            ],
        ];

        $toolLog = [];
        $runtime = $this->runtime(array_fill(0, 3, $askPlan), $toolLog);

        $context = new UnifiedActionContext('orchestrator-generic-fallback', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('I need more information to continue.', $response->message);
        $this->assertSame([], $toolLog);
    }

    // ---------------------------------------------------------------------
    // expose_reasoning end-to-end (P5)
    // ---------------------------------------------------------------------

    public function test_expose_reasoning_accumulates_trace_across_loop_steps_via_config(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        config()->set('ai-agent.ai_native.expose_reasoning', true);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
                'reasoning' => 'Looking up the customer first',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed found.',
                'reasoning' => 'Customer found, summarizing',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-reasoning-config', 77);
        $response = $runtime->process('Check Ahmed then summarize', $context);

        $this->assertTrue($response->success);
        $this->assertSame(
            ['Looking up the customer first', 'Customer found, summarizing'],
            $response->metadata['reasoning_trace'] ?? null
        );
    }

    public function test_expose_reasoning_per_run_override_beats_false_config_and_filters_empty(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        config()->set('ai-agent.ai_native.expose_reasoning', false);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
                'reasoning' => 'Looking up the customer first',
            ],
            [
                // Empty reasoning contributes nothing to the trace.
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Re-checking customer.',
                'reasoning' => '   ',
            ],
            [
                'action' => 'final',
                'message' => 'Done.',
                'reasoning' => 'Customer found, summarizing',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-reasoning-override', 77);
        $response = $runtime->process('Check Ahmed then summarize', $context, ['expose_reasoning' => true]);

        $this->assertTrue($response->success);
        $this->assertSame(
            ['Looking up the customer first', 'Customer found, summarizing'],
            $response->metadata['reasoning_trace'] ?? null
        );
    }

    // ---------------------------------------------------------------------
    // plan_timeline clamp-down across rounds (P4)
    // ---------------------------------------------------------------------

    public function test_plan_timeline_current_index_clamps_down_when_later_round_has_fewer_steps(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.plan_timeline', true);

        // Two read lookups continue the loop (capturePlanTimeline runs each planning
        // round regardless of action type); the final terminates. Steps lists shrink
        // 4 -> 2 -> 1 so current would climb 1 -> 2 -> 3 but clamps DOWN to count=1.
        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
                'steps' => ['a', 'b', 'c', 'd'],
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'ACME'],
                'message' => 'Checking ACME.',
                'steps' => ['x', 'y'],
            ],
            [
                'action' => 'final',
                'message' => 'All done.',
                'steps' => ['z'],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-plan-clamp', 77);
        $response = $runtime->process('Do a multi-step task', $context);

        $this->assertTrue($response->success);
        $this->assertSame(['z'], $response->metadata['plan']['steps'] ?? null);
        // current would be 3 across three rounds but clamps down to count(steps)=1.
        $this->assertSame(1, $response->metadata['plan']['current'] ?? null);
    }

    public function test_plan_timeline_per_run_override_beats_false_config(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.plan_timeline', false);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'Done.',
                'steps' => ['only step'],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-plan-override', 77);
        $response = $runtime->process('Do a task', $context, ['plan_timeline' => true]);

        $this->assertSame(['only step'], $response->metadata['plan']['steps'] ?? null);
        $this->assertSame(1, $response->metadata['plan']['current'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Parallel batches (P4 / P5)
    // ---------------------------------------------------------------------

    public function test_parallel_batch_continues_then_falls_through_to_final_in_later_step(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        // config default false; per-run override turns it on.

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'message' => 'Looking up both customers.',
                'tool_calls' => [
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'ACME']],
                ],
            ],
            [
                'action' => 'final',
                'message' => 'Both found.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-parallel-final', 77);
        $response = $runtime->process('Look up Ahmed and ACME, then summarize both', $context, ['parallel_tools' => true]);

        $this->assertTrue($response->success);
        $this->assertSame('Both found.', $response->message);

        // Both lookups ran from the single batch plan.
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('ACME', $toolLog['lookup_customer'][1]['query']);

        $tools = array_column($context->metadata['ai_native']['tool_results'], 'tool');
        $this->assertSame(['lookup_customer', 'lookup_customer'], array_values(array_filter(
            $tools,
            static fn (string $t): bool => $t === 'lookup_customer'
        )));
    }

    public function test_parallel_batch_with_write_stops_for_confirmation_then_resumes_on_approval(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);

        $toolLog = [];
        $runtime = $this->runtime([
            // Turn 1: batch with a read lookup then a write that must confirm.
            [
                'action' => 'tool_call',
                'message' => 'Looking up then creating.',
                'tool_calls' => [
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                    ['tool' => 'create_customer', 'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com']],
                ],
            ],
            // Turn 2: after the write executes, a final wraps up. (Only consumed if a
            // planning round happens post-resume.)
            [
                'action' => 'final',
                'message' => 'Customer created.',
            ],
        ], $toolLog, generateTimes: null);

        $context = new UnifiedActionContext('orchestrator-parallel-pending', 77);

        // No active skill is seeded (message lacks a skill trigger), so the write
        // entry surfaces its confirmation directly mid-batch.
        $turn1 = $runtime->process('Register Ahmed as a new customer', $context, ['parallel_tools' => true]);
        $this->assertTrue($turn1->needsUserInput);
        $this->assertSame('create_customer', $context->metadata['ai_native']['pending_tool']['name']);
        // The read ran, the write was held for confirmation.
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);

        $turn2 = $runtime->process('yes, confirm', $context, ['parallel_tools' => true]);
        $this->assertTrue($turn2->success);
        $this->assertSame('Ahmed', $toolLog['create_customer'][0]['name']);
        $this->assertCount(1, $toolLog['create_customer']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    // ---------------------------------------------------------------------
    // Best-effort conversation compaction (P4 / P3)
    // ---------------------------------------------------------------------

    public function test_compactor_throw_is_swallowed_and_loop_completes(): void
    {
        config()->set('ai-agent.ai_native.compaction.enabled', true);
        config()->set('ai-agent.ai_native.compaction.threshold', 100);
        config()->set('ai-agent.ai_native.compaction.compact_conversation', true);
        config()->set('ai-agent.ai_native.max_steps', 4);

        $compactor = Mockery::mock(ConversationContextCompactor::class);
        $compactor->shouldReceive('compact')->atLeast()->once()->andThrow(new RuntimeException('compaction boom'));

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed found.',
            ],
        ], $toolLog, compactor: $compactor);

        $context = new UnifiedActionContext('orchestrator-compactor-throws', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->success);
        $this->assertSame('Ahmed found.', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
    }

    public function test_compact_conversation_with_no_injected_compactor_resolves_via_container(): void
    {
        config()->set('ai-agent.ai_native.compaction.enabled', true);
        config()->set('ai-agent.ai_native.compaction.threshold', 100);
        config()->set('ai-agent.ai_native.compaction.compact_conversation', true);
        config()->set('ai-agent.ai_native.max_steps', 4);

        // Bind a harmless container compactor so the app() fallback resolves.
        $resolved = ['count' => 0];
        $this->app->bind(ConversationContextCompactor::class, function () use (&$resolved) {
            $mock = Mockery::mock(ConversationContextCompactor::class);
            $mock->shouldReceive('compact')->andReturnUsing(function () use (&$resolved): void {
                $resolved['count']++;
            });

            return $mock;
        });

        $toolLog = [];
        // No compactor injected -> resolveCompactor() hits app(...).
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed found.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('orchestrator-compactor-container', 77);
        $response = $runtime->process('Look up Ahmed', $context);

        $this->assertTrue($response->success);
        $this->assertSame('Ahmed found.', $response->message);
        $this->assertGreaterThan(0, $resolved['count']);
    }

    // ---------------------------------------------------------------------
    // Approval-of-completed guard boundary (P4)
    // ---------------------------------------------------------------------

    public function test_approval_of_completed_with_completed_writes_short_circuits_without_generate(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        // generateTimes=0 asserts the planner is NEVER called.
        $runtime = $this->runtime([], $toolLog, generateTimes: 0);

        $context = new UnifiedActionContext('orchestrator-approval-completed', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'completed',
                    'completed_writes' => [
                        ['tool' => 'create_invoice', 'label' => 'INV-1', 'outcome' => 'created'],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('yes', $context);

        $this->assertTrue($response->success);
        $this->assertSame('That action has already been completed.', $response->message);
        $this->assertSame([], $toolLog);
    }

    public function test_approval_of_completed_with_empty_completed_writes_runs_the_loop(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // Empty completed_writes => guard skipped => loop runs => generate IS called.
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'Nothing pending.',
            ],
        ], $toolLog, generateTimes: 1);

        $context = new UnifiedActionContext('orchestrator-approval-empty', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'completed',
                    'completed_writes' => [],
                ],
            ],
        ]);

        $response = $runtime->process('yes', $context);

        $this->assertTrue($response->success);
        $this->assertSame('Nothing pending.', $response->message);
    }

    // ---------------------------------------------------------------------
    // Harness
    // ---------------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, mixed> $toolLog
     * @param (callable():mixed)|null $generate Optional override for generate() (throw / custom response).
     * @param int|null $generateTimes Expected generate() call count; null = anyNumberOfTimes.
     */
    private function runtime(
        array $plans,
        array &$toolLog,
        ?callable $generate = null,
        ?int $generateTimes = null,
        ?ConversationContextCompactor $compactor = null
    ): AiNativeRuntime {
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

        $registry->register('create_customer', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_customer';
            }

            public function getDescription(): string
            {
                return 'Create a customer.';
            }

            public function getParameters(): array
            {
                return [
                    'name' => ['type' => 'string', 'required' => true],
                    'email' => ['type' => 'string', 'required' => true],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_customer'][] = $parameters;

                return ActionResult::success('Customer created.', [
                    'id' => 501,
                    'name' => $parameters['name'] ?? null,
                    'email' => $parameters['email'] ?? null,
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

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices.',
                triggers: ['create invoice'],
                tools: ['lookup_customer', 'create_customer', 'create_invoice'],
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
                            'create_tool' => 'create_customer',
                            'lookup_fields' => ['customer_name'],
                            'create_required_fields' => ['name', 'email'],
                            'safe_create' => true,
                        ],
                    ],
                    'final_tool' => 'create_invoice',
                ]
            ),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $expectation = $ai->shouldReceive('generate');

        if ($generate !== null) {
            $expectation->andReturnUsing($generate);
        } else {
            $expectation->andReturn(...array_map(
                static fn (array $plan): AIResponse => AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
                $plans
            ));
        }

        if ($generateTimes !== null) {
            $expectation->times($generateTimes);
        } else {
            $expectation->zeroOrMoreTimes();
        }

        return new AiNativeRuntime(
            $ai,
            $registry,
            $skills,
            app(IntentSignalService::class),
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            $compactor
        );
    }
}
