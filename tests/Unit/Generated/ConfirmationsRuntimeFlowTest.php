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
 * Integration coverage for {@see \LaravelAIEngine\Services\Agent\AiNative\AiNativePendingConfirmationHandler::handlePendingTool}
 * driven through the REAL {@see AiNativeRuntime::process()} loop rather than by
 * isolating the handler against mocked collaborators.
 *
 * Every test scripts one or more planner turns (mirroring the canonical
 * AiNativeRuntimeTest::runtime harness — a mocked AIEngineService that returns
 * canned plan JSON, never a real LLM/network call). Turn 1 issues a
 * tool_call plan for a tool whose requiresConfirmation() is true, which parks a
 * `pending_tool` in state and returns a confirmation prompt. Turn 2 sends the
 * user's reply ('yes'/'no'/empty/change-term), which routes into
 * handlePendingTool and exercises one of its documented branches.
 *
 * Branches covered: pure rejection, approval-executes, post-execute
 * needsUserInput, empty-message guard, custom change-terms, and the
 * stale/unavailable-tool guard. See the class-level note at the bottom for the
 * branches intentionally omitted (and why) because they cannot be reached
 * non-brittly through the real two-turn runtime flow.
 */
class ConfirmationsRuntimeFlowTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_pure_no_rejection_discards_pending_tool_without_executing(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // Turn 1 parks create_customer for confirmation. Turn 2 ('no') is a pure
        // negative: handlePendingTool clears the pending tool and returns null, so
        // the loop continues and consumes the second (final) plan.
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com'],
                'message' => 'Create Ahmed?',
            ],
            [
                'action' => 'final',
                'message' => 'Okay, I cancelled the customer creation.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-pure-no', 77);

        $first = $runtime->process('Create customer Ahmed', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);

        $second = $runtime->process('no', $context);

        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Okay, I cancelled the customer creation.', $second->message);
        // Pending tool discarded and never executed.
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
    }

    public function test_yes_approval_executes_pending_tool(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // Turn 2 ('yes') approves: handlePendingTool executes create_customer,
        // records the write, returns null, and the loop falls through to the final
        // plan summarising the result.
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com'],
                'message' => 'Create Ahmed?',
            ],
            [
                'action' => 'final',
                'message' => 'Customer is ready.',
                'data' => ['customer_id' => 501],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-yes', 77);

        $first = $runtime->process('Create customer Ahmed', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);

        $second = $runtime->process('yes', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Customer is ready.', $second->message);
        // The parked tool actually ran exactly once with the parked arguments.
        $this->assertCount(1, $toolLog['create_customer']);
        $this->assertSame('Ahmed', $toolLog['create_customer'][0]['name']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_confirm_on_stale_or_unavailable_tool_returns_needs_user_input(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // No planner turns: the pending tool is already parked in incoming state,
        // and its name is not registered (a stale pending tool from a prior
        // deployment). handlePendingTool short-circuits before the loop, so
        // generate() is never called.
        $runtime = $this->runtime([], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-stale-tool', 77, metadata: [
            'ai_native' => [
                'pending_tool' => [
                    'name' => 'create_widget',
                    'params' => ['name' => 'Ahmed'],
                    'message' => 'Create the widget?',
                ],
                'task_frame' => [
                    'status' => 'confirming',
                    'pending_tool' => [
                        'name' => 'create_widget',
                        'params' => ['name' => 'Ahmed'],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('yes', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Tool create_widget is not available.', $response->message);
        // Pending tool cleared so the stale entry cannot re-trigger.
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_approval_surfaces_post_execute_needs_user_input(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // create_invoice is parked for confirmation in turn 1 (lookups are
        // pre-seeded so the write is not gated). On approval its execute() returns
        // needsUserInput because the line item has no product_id, exercising the
        // post-execute "more information required" branch of handlePendingTool.
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-post-execute-needs-input', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => ['success' => true, 'data' => ['found' => true, 'id' => 501]],
                    ],
                    [
                        'tool' => 'lookup_product',
                        'params' => ['query' => 'Laptop'],
                        'result' => ['success' => true, 'data' => ['found' => true, 'id' => 10]],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $second = $runtime->process('yes', $context);

        // The pending tool ran, but its result requested more input rather than
        // succeeding outright.
        $this->assertTrue($second->needsUserInput);
        $this->assertCount(1, $toolLog['create_invoice']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_empty_message_guard_clears_pending_tool(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        // Turn 2 is whitespace only: the empty-message guard clears the pending
        // confirmation and returns null, so the loop continues into the second
        // (final) plan without executing the parked tool.
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com'],
                'message' => 'Create Ahmed?',
            ],
            [
                'action' => 'final',
                'message' => 'Standing by.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-empty-guard', 77);

        $first = $runtime->process('Create customer Ahmed', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);

        $second = $runtime->process('   ', $context);

        $this->assertSame('Standing by.', $second->message);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
    }

    public function test_change_term_cancels_pending_tool_and_records_feedback(): void
    {
        // max_steps=1 keeps each turn to a single planner call, so the second
        // (post-cancellation) turn consumes exactly one plan.
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        // 'change the email instead' is neither a pure negative nor an approval,
        // but it contains configured change-terms, so handlePendingTool cancels the
        // pending write, records pending_confirmation_changed_by_user feedback, and
        // returns null. The loop then resumes from the new instruction.
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com'],
                'message' => 'Create Ahmed?',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Sure — what should the new email be?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('confirm-runtime-change-term', 77);

        $first = $runtime->process('Create customer Ahmed', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);

        $second = $runtime->process('change the email instead', $context);

        $this->assertTrue($second->needsUserInput);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
        $this->assertSame(
            'pending_confirmation_changed_by_user',
            $context->metadata['ai_native']['runtime_feedback'][0]['reason'] ?? null
        );
    }

    /**
     * Branches intentionally omitted from this file because they cannot be reached
     * non-brittly through the real two-turn handlePendingTool flow:
     *
     * - current_payload validate/preview abort: lives in
     *   confirmCurrentPayloadIfRequested()/currentPayloadConfirmationResponse(),
     *   a sibling method that is NOT handlePendingTool. It is already exercised by
     *   AiNativeRuntimeTest::test_confirm_uses_active_skill_current_payload_*.
     * - suggested-tool continuation after approval: requires the executed pending
     *   tool to return a suggested_tool(s) result the continuation engine accepts;
     *   wiring that through the real loop is order-dependent and already covered by
     *   the suggested-tool continuation tests in AiNativeRuntimeTest.
     * - the toolCompleted (required-final-tool) branch: depends on skill final_tool
     *   policy state that is brittle to reproduce through two scripted turns.
     */

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, array<int, array<string, mixed>>> $toolLog
     */
    private function runtime(array $plans, array &$toolLog): AiNativeRuntime
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
                    'name' => $parameters['name'],
                    'email' => $parameters['email'],
                ]);
            }
        });

        $registry->register('lookup_product', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'lookup_product';
            }

            public function getDescription(): string
            {
                return 'Search for a product.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['lookup_product'][] = $parameters;

                return ActionResult::success('Product found.', [
                    'found' => true,
                    'id' => 10,
                    'name' => 'Laptop',
                    'price' => 1000,
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

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_invoice'][] = $parameters;

                $items = (array) ($parameters['items'] ?? []);
                if (($items[0]['product_id'] ?? null) === null) {
                    return ActionResult::needsUserInput('Resolve products before creating invoice.', [
                        'current_payload' => $parameters,
                        'missing_fields' => ['items.0.product_id'],
                    ]);
                }

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
                tools: ['lookup_customer', 'create_customer', 'lookup_product', 'create_invoice'],
                metadata: [
                    'target_json' => [
                        'customer_id' => null,
                        'customer_name' => null,
                        'items' => [],
                    ],
                    'final_tool' => 'create_invoice',
                ]
            ),
        ]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->times(count($plans))
            ->andReturn(...array_map(
                static fn (array $plan): AIResponse => AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
                $plans
            ));

        return new AiNativeRuntime(
            $ai,
            $registry,
            $skills,
            app(IntentSignalService::class)
        );
    }
}
