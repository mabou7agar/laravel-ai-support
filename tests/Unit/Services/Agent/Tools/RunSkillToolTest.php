<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RunSkillToolTest extends UnitTestCase
{
    public function test_it_handles_full_skill_flow_with_missing_customer_missing_product_edits_and_final_confirmation(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);
        config()->set('ai-agent.skill_tool_planner.max_steps', 4);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create an invoice from customer and item details.',
            tools: ['search_customer', 'create_customer', 'search_product', 'create_product', 'create_invoice'],
            requiresConfirmation: true,
            metadata: [
                'planner' => 'skill_tool_auto',
                'final_tool' => 'create_invoice',
                'target_json' => [
                    'customer_id' => null,
                    'customer_name' => null,
                    'customer_email' => null,
                    'items' => [],
                    'discount' => null,
                    'shipping_fee' => null,
                ],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'run_tool',
                'message' => 'Searching customer.',
                'payload_patch' => [
                    'customer_name' => 'NewCo',
                    'items' => [
                        ['product_name' => 'Alpha Laptop', 'quantity' => 2],
                        ['product_name' => 'Beta Phone', 'quantity' => 1],
                    ],
                ],
                'tool_name' => 'search_customer',
                'tool_params' => ['query' => 'NewCo'],
            ],
            [
                'action' => 'ask_user',
                'message' => 'Customer NewCo was not found. What email should I use?',
                'payload_patch' => [],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Create customer NewCo with sales@newco.test?',
                'payload_patch' => ['customer_email' => 'sales@newco.test'],
                'tool_name' => 'create_customer',
                'tool_params' => ['name' => 'NewCo', 'email' => 'sales@newco.test'],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Create customer NewCo Ltd with sales@newco.test?',
                'payload_patch' => ['customer_name' => 'NewCo Ltd'],
                'tool_name' => 'create_customer',
                'tool_params' => ['name' => 'NewCo Ltd', 'email' => 'sales@newco.test'],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Searching Alpha Laptop.',
                'payload_patch' => [
                    'customer_id' => 501,
                    'customer_name' => 'NewCo Ltd',
                    'customer_email' => 'sales@newco.test',
                ],
                'tool_name' => 'search_product',
                'tool_params' => ['query' => 'Alpha Laptop'],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Searching Beta Phone.',
                'payload_patch' => [
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ['product_name' => 'Beta Phone', 'quantity' => 1],
                    ],
                ],
                'tool_name' => 'search_product',
                'tool_params' => ['query' => 'Beta Phone'],
            ],
            [
                'action' => 'ask_user',
                'message' => 'Beta Phone was not found. Should I create it, and what price should I use?',
                'payload_patch' => [],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Create Beta Phone at 600?',
                'payload_patch' => ['shipping_fee' => 25],
                'tool_name' => 'create_product',
                'tool_params' => ['name' => 'Beta Phone', 'price' => 600],
            ],
            [
                'action' => 'ask_user',
                'message' => 'Updated draft: Alpha Laptop price is 900, shipping removed, Gamma Case added.',
                'payload_patch' => [
                    'shipping_fee' => null,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                        ['product_id' => 22, 'product_name' => 'Beta Phone', 'quantity' => 1, 'unit_price' => 600],
                        ['product_name' => 'Gamma Case', 'quantity' => 1, 'unit_price' => 40],
                    ],
                ],
            ],
            [
                'action' => 'ask_user',
                'message' => 'Updated draft: Gamma Case removed and one more Beta Phone added.',
                'payload_patch' => [
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                        ['product_id' => 22, 'product_name' => 'Beta Phone', 'quantity' => 2, 'unit_price' => 600],
                    ],
                ],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Confirm invoice for NewCo Ltd: 2 Alpha Laptop at 900 and 2 Beta Phone at 600.',
                'payload_patch' => [],
                'tool_name' => 'create_invoice',
                'tool_params' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                        ['product_id' => 22, 'product_name' => 'Beta Phone', 'quantity' => 2, 'unit_price' => 600],
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('invoice-full-flow', 77);

        $first = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Create invoice for NewCo with 2 Alpha Laptop and 1 Beta Phone',
            'reset' => true,
        ], $context);
        $this->assertTrue($first->requiresUserInput());
        $this->assertSame('Customer NewCo was not found. What email should I use?', $first->message);

        $customerConfirmation = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Use sales@newco.test',
        ], $context);
        $this->assertTrue($customerConfirmation->requiresUserInput());
        $this->assertSame('create_customer', $customerConfirmation->data['pending_tool']['name']);
        $this->assertSame('NewCo', $customerConfirmation->data['pending_tool']['params']['name']);

        $renamedCustomerConfirmation = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Before creating, rename the customer to NewCo Ltd',
        ], $context);
        $this->assertTrue($renamedCustomerConfirmation->requiresUserInput());
        $this->assertSame('create_customer', $renamedCustomerConfirmation->data['pending_tool']['name']);
        $this->assertSame('NewCo Ltd', $renamedCustomerConfirmation->data['pending_tool']['params']['name']);

        $createdCustomer = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'confirm',
        ], $context);
        $this->assertTrue($createdCustomer->success);
        $this->assertSame('NewCo Ltd', $toolLog['create_customer'][0]['name']);

        $productLookup = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Continue with products',
        ], $context);
        $this->assertTrue($productLookup->requiresUserInput());
        $this->assertSame(['Alpha Laptop', 'Beta Phone'], array_column($toolLog['search_product'], 'query'));

        $productConfirmation = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Create Beta Phone at 600 and add shipping 25',
        ], $context);
        $this->assertTrue($productConfirmation->requiresUserInput());
        $this->assertSame('create_product', $productConfirmation->data['pending_tool']['name']);

        $createdProduct = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'confirm product',
        ], $context);
        $this->assertTrue($createdProduct->success);
        $this->assertSame('Beta Phone', $toolLog['create_product'][0]['name']);

        $priceEdit = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Change Alpha Laptop price to 900, remove shipping, and add 1 Gamma Case at 40',
        ], $context);
        $this->assertTrue($priceEdit->requiresUserInput());
        $this->assertArrayNotHasKey('shipping_fee', $priceEdit->data['payload']);
        $this->assertSame(900, $priceEdit->data['payload']['items'][0]['unit_price']);
        $this->assertSame('Gamma Case', $priceEdit->data['payload']['items'][2]['product_name']);

        $removeAndAdd = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Remove Gamma Case and add one more Beta Phone',
        ], $context);
        $this->assertTrue($removeAndAdd->requiresUserInput());
        $this->assertCount(2, $removeAndAdd->data['payload']['items']);
        $this->assertSame(2, $removeAndAdd->data['payload']['items'][1]['quantity']);

        $invoiceConfirmation = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Confirm invoice',
        ], $context);
        $this->assertTrue($invoiceConfirmation->success);
        $this->assertSame('completed', $invoiceConfirmation->data['status']);
        $this->assertSame(501, $toolLog['create_invoice'][0]['customer_id']);
        $this->assertSame(3000, $toolLog['create_invoice'][0]['total']);
    }

    public function test_it_asks_user_when_planner_requests_missing_input(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['echo_tool'],
            metadata: ['planner' => 'skill_tool_auto', 'target_json' => ['customer_name' => null]]
        );

        $tool = $this->makeTool($skill, [
            'action' => 'ask_user',
            'message' => 'What customer name should I use?',
            'payload_patch' => ['items' => [['product_name' => 'Alpha Laptop', 'quantity' => 2]]],
        ]);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice for 2 Alpha Laptop',
            'reset' => true,
        ], new UnifiedActionContext('run-skill-ask-test'));

        $this->assertFalse($result->success);
        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('What customer name should I use?', $result->message);
        $this->assertSame('create_invoice', $result->data['skill_id']);
        $this->assertSame('Alpha Laptop', $result->data['payload']['items'][0]['product_name']);
    }

    public function test_it_runs_declared_tool_and_keeps_runtime_state(): void
    {
        config()->set('ai-agent.skill_tool_planner.max_steps', 1);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['echo_tool'],
            metadata: ['planner' => 'skill_tool_auto', 'target_json' => ['customer_name' => null]]
        );

        $tool = $this->makeTool($skill, [
            'action' => 'run_tool',
            'message' => 'Looking up the customer.',
            'payload_patch' => ['customer_name' => 'Acme'],
            'tool_name' => 'echo_tool',
            'tool_params' => ['value' => 'Acme'],
        ]);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice for Acme',
            'reset' => true,
        ], new UnifiedActionContext('run-skill-tool-test'));

        $this->assertTrue($result->success);
        $this->assertSame('Skill draft updated.', $result->message);
        $this->assertSame('collecting', $result->data['status']);
        $this->assertSame('Acme', $result->data['payload']['customer_name']);
    }

    public function test_it_converts_final_response_to_configured_final_tool_confirmation(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['create_invoice'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'final_tool' => 'create_invoice',
                'target_json' => [
                    'customer_id' => null,
                    'items' => [],
                ],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'final_response',
                'message' => 'The invoice is ready to create.',
                'payload_patch' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('invoice-final-tool-guard', 77);

        $confirmation = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Confirm invoice',
            'reset' => true,
        ], $context);

        $this->assertTrue($confirmation->requiresUserInput());
        $this->assertSame('collecting', $confirmation->data['status']);
        $this->assertSame('create_invoice', $confirmation->data['pending_tool']['name']);
        $this->assertSame(501, $confirmation->data['pending_tool']['params']['customer_id']);
        $this->assertSame(900, $confirmation->data['pending_tool']['params']['items'][0]['unit_price']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $final = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'confirm',
        ], $context);

        $this->assertTrue($final->success);
        $this->assertSame('completed', $final->data['status']);
        $this->assertSame(1800, $toolLog['create_invoice'][0]['total']);
    }

    public function test_it_executes_confirmed_write_tool_without_reasking_for_confirmation(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['create_product'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'target_json' => ['items' => []],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'ask_user',
                'message' => 'Please confirm product name Beta Phone and price 600.',
                'payload_patch' => ['items' => [['product_name' => 'Beta Phone', 'quantity' => 1, 'unit_price' => 600]]],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Creating Beta Phone at 600.',
                'payload_patch' => [],
                'tool_name' => 'create_product',
                'tool_params' => ['name' => 'Beta Phone', 'price' => 600],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('invoice-confirm-write-directly', 77);

        $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Create Beta Phone at 600',
            'reset' => true,
        ], $context);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'confirm product',
        ], $context);

        $this->assertTrue($result->success);
        $this->assertFalse($result->requiresUserInput());
        $this->assertSame('Beta Phone', $toolLog['create_product'][0]['name']);
    }

    public function test_it_keeps_final_response_as_collecting_when_user_is_editing(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['create_invoice'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'final_tool' => 'create_invoice',
                'target_json' => ['customer_id' => null, 'items' => []],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'final_response',
                'message' => 'Updated the invoice items.',
                'payload_patch' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
            ],
        ], $toolLog);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Change Alpha Laptop price to 900',
            'reset' => true,
        ], new UnifiedActionContext('invoice-final-response-edit', 77));

        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('collecting', $result->data['status']);
        $this->assertNull($result->data['pending_tool']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_it_does_not_queue_final_tool_when_user_is_editing(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['create_invoice'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'final_tool' => 'create_invoice',
                'target_json' => ['customer_id' => null, 'items' => []],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'run_tool',
                'message' => 'Updated the invoice items.',
                'payload_patch' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
                'tool_name' => 'create_invoice',
                'tool_params' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
            ],
        ], $toolLog);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Change Alpha Laptop price to 900',
            'reset' => true,
        ], new UnifiedActionContext('invoice-final-tool-edit', 77));

        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('collecting', $result->data['status']);
        $this->assertNull($result->data['pending_tool']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_final_approval_does_not_confirm_unrelated_pending_tool(): void
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['create_product', 'create_invoice'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'final_tool' => 'create_invoice',
                'target_json' => ['customer_id' => null, 'items' => []],
            ]
        );

        $toolLog = [];
        $tool = $this->makeToolWithPlans($skill, [
            [
                'action' => 'run_tool',
                'message' => 'Create Beta Phone at 600.',
                'payload_patch' => ['items' => [['product_name' => 'Beta Phone', 'quantity' => 1, 'unit_price' => 600]]],
                'tool_name' => 'create_product',
                'tool_params' => ['name' => 'Beta Phone', 'price' => 600],
            ],
            [
                'action' => 'run_tool',
                'message' => 'Creating the invoice.',
                'payload_patch' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
                'tool_name' => 'create_invoice',
                'tool_params' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Alpha Laptop', 'quantity' => 2, 'unit_price' => 900],
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('invoice-final-approval-reroute', 77);

        $pendingProduct = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Add Beta Phone',
            'reset' => true,
        ], $context);
        $this->assertTrue($pendingProduct->requiresUserInput());
        $this->assertSame('create_product', $pendingProduct->data['pending_tool']['name']);

        $final = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'Confirm invoice',
        ], $context);

        $this->assertTrue($final->success);
        $this->assertSame('completed', $final->data['status']);
        $this->assertArrayNotHasKey('create_product', $toolLog);
        $this->assertSame(1800, $toolLog['create_invoice'][0]['total']);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function makeTool(AgentSkillDefinition $skill, array $plan): RunSkillTool
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([$skill]);

        $tools = new ToolRegistry();
        $tools->register('echo_tool', new class extends AgentTool {
            public function getName(): string
            {
                return 'echo_tool';
            }

            public function getDescription(): string
            {
                return 'Echo a value.';
            }

            public function getParameters(): array
            {
                return ['value' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', ['value' => $parameters['value'] ?? null]);
            }
        });

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'));

        return new RunSkillTool(
            $registry,
            app(ConversationMemory::class),
            $ai,
            $tools
        );
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, array<int, array<string, mixed>>> $toolLog
     */
    private function makeToolWithPlans(AgentSkillDefinition $skill, array $plans, array &$toolLog): RunSkillTool
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([$skill]);

        $tools = new ToolRegistry();
        $tools->register('search_customer', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'search_customer';
            }

            public function getDescription(): string
            {
                return 'Search customers by name or email.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['search_customer'][] = $parameters;

                return ActionResult::success('No customer found.', ['found' => false]);
            }
        });

        $tools->register('create_customer', new class($toolLog) extends AgentTool {
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

        $tools->register('search_product', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'search_product';
            }

            public function getDescription(): string
            {
                return 'Search products by name.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['search_product'][] = $parameters;
                if (($parameters['query'] ?? null) === 'Alpha Laptop') {
                    return ActionResult::success('Product found.', [
                        'id' => 10,
                        'name' => 'Alpha Laptop',
                        'price' => 1000,
                    ]);
                }

                return ActionResult::success('No product found.', ['found' => false]);
            }
        });

        $tools->register('create_product', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_product';
            }

            public function getDescription(): string
            {
                return 'Create a product.';
            }

            public function getParameters(): array
            {
                return [
                    'name' => ['type' => 'string', 'required' => true],
                    'price' => ['type' => 'number', 'required' => true],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_product'][] = $parameters;

                return ActionResult::success('Product created.', [
                    'id' => 22,
                    'name' => $parameters['name'],
                    'price' => $parameters['price'],
                ]);
            }
        });

        $tools->register('create_invoice', new class($toolLog) extends AgentTool {
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
                    'customer_id' => ['type' => 'integer', 'required' => true],
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
                $total = array_reduce(
                    (array) ($parameters['items'] ?? []),
                    static fn (float|int $carry, array $item): float|int => $carry + (($item['quantity'] ?? 0) * ($item['unit_price'] ?? 0)),
                    0
                );
                $parameters['total'] = $total;
                $this->log['create_invoice'][] = $parameters;

                return ActionResult::success('Invoice created.', [
                    'id' => 9001,
                    'total' => $total,
                ]);
            }
        });

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->times(count($plans))
            ->andReturn(...array_map(
                static fn (array $plan): AIResponse => AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
                $plans
            ));

        return new RunSkillTool(
            $registry,
            app(ConversationMemory::class),
            $ai,
            $tools
        );
    }
}
