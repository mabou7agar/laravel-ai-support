<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

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

class AiNativeRuntimeTest extends UnitTestCase
{
    public function test_ai_can_call_registered_tool_directly_and_finish(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking the customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed exists.',
                'data' => ['customer_id' => 501],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-direct-tool', 77);
        $response = $runtime->process('Check Ahmed', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Ahmed exists.', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('ai_native', $response->strategy);
        $this->assertSame('lookup_customer', $response->metadata['ai_native']['tool_results'][0]['tool']);
    }

    public function test_ai_native_tool_calls_respect_execution_policy(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);
        config()->set('ai-agent.execution_policy.tool_deny', ['lookup_customer']);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking the customer.',
            ],
        ], $toolLog);

        $response = $runtime->process('Check Ahmed', new UnifiedActionContext('ai-native-policy-deny', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
        $this->assertArrayNotHasKey('lookup_customer', $toolLog);
    }

    public function test_failed_lookup_not_found_continues_planning_instead_of_returning_raw_tool_error(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Failing Missing Corp'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Failing Missing Corp was not found. What email should I use to create it?',
                'required_inputs' => ['customer_email'],
                'data' => [
                    'current_payload' => [
                        'customer_name' => 'Failing Missing Corp',
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-failed-lookup-not-found', 77);
        $response = $runtime->process('Create invoice for Failing Missing Corp', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Failing Missing Corp was not found. What email should I use to create it?', $response->message);
        $this->assertSame('Failing Missing Corp', $toolLog['lookup_customer'][0]['query']);
        $this->assertFalse($context->metadata['ai_native']['tool_results'][0]['result']['success']);
        $this->assertSame('not_found', $context->metadata['ai_native']['recent_outcomes'][0]['outcome']);
        $this->assertSame('Failing Missing Corp', $context->metadata['ai_native']['task_frame']['current_payload']['customer_name']);
    }

    public function test_background_domain_context_does_not_start_required_input_flow(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'What email should I use for Ahmed Mooh?',
                'required_inputs' => ['customer_email'],
                'data' => ['customer_name' => 'Ahmed Mooh'],
            ],
            [
                'action' => 'final',
                'message' => 'I noted those details and can help when you want to take an action.',
            ],
        ], $toolLog);

        $response = $runtime->process(
            'Hi. I am speaking with Ahmed Mooh. He wants 2 Macbook Pro at 400 each and 3 iPhone at 200 each.',
            new UnifiedActionContext('ai-native-background-context', 77)
        );

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('I noted those details and can help when you want to take an action.', $response->message);
        $this->assertSame('latest_message_not_action_request', $response->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertSame([], $toolLog);
    }

    public function test_background_domain_context_does_not_start_confirming_write_tool(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_name' => 'Ahmed Mooh',
                    'items' => [
                        ['product_name' => 'Macbook Pro', 'quantity' => 2, 'unit_price' => 400],
                    ],
                ],
                'message' => 'Creating invoice.',
            ],
            [
                'action' => 'final',
                'message' => 'I saved the details as context and will wait for your instruction.',
            ],
        ], $toolLog);

        $response = $runtime->process(
            'Ahmed Mooh wants 2 Macbook Pro at 400 each.',
            new UnifiedActionContext('ai-native-background-write-tool', 77)
        );

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('I saved the details as context and will wait for your instruction.', $response->message);
        $this->assertSame('latest_message_not_action_request', $response->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_explicit_action_request_can_still_ask_for_missing_fields(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'What email should I use for Ahmed Mooh?',
                'required_inputs' => ['customer_email'],
                'data' => ['customer_name' => 'Ahmed Mooh'],
            ],
        ], $toolLog);

        $response = $runtime->process(
            'Generate a report for Ahmed Mooh.',
            new UnifiedActionContext('ai-native-explicit-action-ask', 77)
        );

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What email should I use for Ahmed Mooh?', $response->message);
        $feedback = $response->metadata['ai_native']['runtime_feedback'] ?? [];
        $this->assertNotContains('latest_message_not_action_request', array_column($feedback, 'reason'));
    }

    public function test_ai_native_runtime_redacts_sensitive_tool_state_from_metadata(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => [
                    'query' => 'Ahmed',
                    'api_key' => 'sk-test-secret',
                    'accessToken' => 'access-token-secret',
                    'clientSecret' => 'client-secret-value',
                ],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed exists.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-redaction', 77);
        $response = $runtime->process('Check Ahmed', $context);

        $this->assertSame('sk-test-secret', $toolLog['lookup_customer'][0]['api_key']);
        $this->assertSame('[redacted]', $context->metadata['ai_native']['tool_results'][0]['params']['api_key']);
        $this->assertSame('[redacted]', $context->metadata['ai_native']['tool_results'][0]['params']['accessToken']);
        $this->assertSame('[redacted]', $context->metadata['ai_native']['tool_results'][0]['params']['clientSecret']);
        $this->assertSame('[redacted]', $response->metadata['ai_native']['tool_results'][0]['params']['api_key']);
        $this->assertStringNotContainsString('sk-test-secret', json_encode($response->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_ai_native_long_conversation_handles_unrelated_chat_and_invoice_flow(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'Hi, how can I help?',
            ],
            [
                'action' => 'final',
                'message' => 'I can answer questions or help run actions.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Missing Corp'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'I could not find Missing Corp. Should I create it, or use an existing customer?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking Ahmed.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Laptop'],
                'message' => 'Checking Laptop.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Missing Widget'],
                'message' => 'Checking Missing Widget.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'I could not find Missing Widget. What should I use instead?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Mouse'],
                'message' => 'Checking Mouse.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Laptop'],
                'message' => 'Checking product.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Laptop'],
                'message' => 'Checking product.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 3, 'unit_price' => 1000],
                        ['product_id' => 11, 'product_name' => 'Mouse', 'quantity' => 1, 'unit_price' => 50],
                    ],
                ],
                'message' => 'Create this invoice?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 5, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create the updated invoice?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 5, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create it again?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-long-conversation', 77);

        $hello = $runtime->process('Hi', $context);
        $this->assertTrue($hello->success);
        $this->assertArrayNotHasKey('task_frame', $context->metadata['ai_native'] ?? []);
        $this->assertSame([], $toolLog);

        $question = $runtime->process('What can you do?', $context);
        $this->assertTrue($question->success);
        $this->assertSame([], $toolLog);

        $missingCustomer = $runtime->process('Create invoice for Missing Corp with 2 Laptop and 1 Missing Widget', $context);
        $this->assertTrue($missingCustomer->needsUserInput);
        $this->assertSame('Missing Corp', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $missingProduct = $runtime->process('Use existing Ahmed instead.', $context);
        $this->assertTrue($missingProduct->needsUserInput);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][1]['query']);
        $this->assertSame('Laptop', $toolLog['lookup_product'][0]['query']);
        $this->assertSame('Missing Widget', $toolLog['lookup_product'][1]['query']);

        $draft = $runtime->process('Replace Missing Widget with Mouse and make Laptop quantity 3.', $context);
        $this->assertTrue($draft->needsUserInput);
        $this->assertSame('Mouse', $toolLog['lookup_product'][2]['query']);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['task_frame']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $updatedDraft = $runtime->process('Remove Mouse and use 5 Laptop.', $context);
        $this->assertTrue($updatedDraft->needsUserInput);
        $this->assertSame('pending_confirmation_changed_by_user', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertSame(5, $context->metadata['ai_native']['pending_tool']['params']['items'][0]['quantity']);

        $created = $runtime->process('confirm', $context);
        $this->assertTrue($created->success);
        $this->assertFalse($created->needsUserInput);
        $this->assertSame('Invoice created.', $created->message);
        $this->assertCount(1, $toolLog['create_invoice']);

        $duplicate = $runtime->process('create it again', $context);
        $this->assertTrue($duplicate->success);
        $this->assertSame('That action has already been completed.', $duplicate->message);
        $this->assertCount(1, $toolLog['create_invoice']);
    }

    public function test_missing_customer_can_be_created_then_invoice_flow_continues_to_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 5);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Missing Corp'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Missing Corp was not found. What email should I use to create it?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Missing Corp', 'email' => 'billing@missing.test'],
                'message' => 'Create Missing Corp?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Laptop'],
                'message' => 'Checking product.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create this invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-missing-customer-create-chain', 77);

        $missingCustomer = $runtime->process('Create invoice for Missing Corp with 2 Laptop', $context);
        $this->assertTrue($missingCustomer->needsUserInput);
        $this->assertSame('Missing Corp', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $customerConfirmation = $runtime->process('Use billing@missing.test and create the customer.', $context);
        $this->assertTrue($customerConfirmation->needsUserInput);
        $this->assertSame('create_customer', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);

        $invoiceConfirmation = $runtime->process('confirm', $context);
        $this->assertTrue($invoiceConfirmation->needsUserInput);
        $this->assertSame('Missing Corp', $toolLog['create_customer'][0]['name']);
        $this->assertSame('Laptop', $toolLog['lookup_product'][0]['query']);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertSame(501, $context->metadata['ai_native']['pending_tool']['params']['customer_id'] ?? null);
        $this->assertSame(10, $context->metadata['ai_native']['pending_tool']['params']['items'][0]['product_id'] ?? null);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $created = $runtime->process('confirm', $context);
        $this->assertTrue($created->success);
        $this->assertFalse($created->needsUserInput);
        $this->assertSame('Invoice created.', $created->message);
        $this->assertSame(501, $toolLog['create_invoice'][0]['customer_id']);
        $this->assertCount(1, $toolLog['create_customer']);
        $this->assertCount(1, $toolLog['create_invoice']);
    }

    public function test_missing_product_can_be_created_then_invoice_flow_continues_to_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 5);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Missing Product'],
                'message' => 'Checking product.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Missing Product was not found. Should I create it, and what unit price should I use?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_product',
                'arguments' => ['name' => 'Missing Product', 'price' => 125],
                'message' => 'Create Missing Product?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 12, 'product_name' => 'Missing Product', 'quantity' => 2, 'unit_price' => 125],
                    ],
                ],
                'message' => 'Create this invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-missing-product-create-chain', 77);

        $missingProduct = $runtime->process('Create invoice for Ahmed with 2 Missing Product', $context);
        $this->assertTrue($missingProduct->needsUserInput);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('Missing Product', $toolLog['lookup_product'][0]['query']);
        $this->assertArrayNotHasKey('create_product', $toolLog);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $productConfirmation = $runtime->process('Create it at 125 and use it.', $context);
        $this->assertTrue($productConfirmation->needsUserInput);
        $this->assertSame('create_product', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_product', $toolLog);

        $invoiceConfirmation = $runtime->process('confirm', $context);
        $this->assertTrue($invoiceConfirmation->needsUserInput);
        $this->assertSame('Missing Product', $toolLog['create_product'][0]['name']);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $created = $runtime->process('confirm', $context);
        $this->assertTrue($created->success);
        $this->assertFalse($created->needsUserInput);
        $this->assertSame('Invoice created.', $created->message);
        $this->assertSame(12, $toolLog['create_invoice'][0]['items'][0]['product_id']);
        $this->assertSame(125, $toolLog['create_invoice'][0]['items'][0]['unit_price']);
        $this->assertCount(1, $toolLog['create_product']);
        $this->assertCount(1, $toolLog['create_invoice']);
    }

    public function test_runtime_seeds_active_task_from_matching_skill_before_lookup_tools(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-active-objective', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['task_frame']['active_objective']);
        $this->assertSame('working', $context->metadata['ai_native']['task_frame']['status']);
    }

    public function test_runtime_retries_unused_lookup_tools_before_asking_for_resolvable_values(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'What is the Laptop price?',
                'required_inputs' => ['unit_price'],
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Laptop'],
                'message' => 'Checking product.',
            ],
        ], $toolLog);

        $runtime->process('Create invoice for Ahmed with 1 Laptop', new UnifiedActionContext('ai-native-unused-lookup-before-ask', 77));

        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('Laptop', $toolLog['lookup_product'][0]['query']);
    }

    public function test_followup_final_answer_cannot_bypass_active_task_final_tool(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'The invoice has been created.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 1, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-active-task-final-tool', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                ],
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('confirm', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Create invoice?', $response->message);
        $this->assertStringContainsString('Summary:', $response->message);
        $this->assertSame('final_without_required_final_tool', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_write_tools_pause_for_confirmation_and_resume_after_confirm(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
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

        $context = new UnifiedActionContext('ai-native-write-confirm', 77);
        $first = $runtime->process('Create Ahmed', $context);

        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);
        $this->assertSame('confirmation', $first->requiredInputs[0]['name']);
        $this->assertSame('select', $first->requiredInputs[0]['type']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);

        $second = $runtime->process('confirm', $context);
        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Customer is ready.', $second->message);
        $this->assertSame('Ahmed', $toolLog['create_customer'][0]['name']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_pending_write_can_resume_from_configured_continuation_term(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);
        config()->set('ai-agent.skills.continuation_terms', ['create it']);

        $toolLog = [];
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

        $context = new UnifiedActionContext('ai-native-write-create-it', 77);
        $first = $runtime->process('Create Ahmed', $context);

        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $first->data['pending_tool']['name']);

        $second = $runtime->process('create it', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Customer is ready.', $second->message);
        $this->assertSame('Ahmed', $toolLog['create_customer'][0]['name']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_pending_write_is_not_approved_by_navigation_phrase(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Zeta Labs', 'email' => 'billing@zetalabs.test'],
                'message' => 'Create Zeta Labs?',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Which product should I add next?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-continue-does-not-confirm', 77);
        $first = $runtime->process('Create customer Zeta Labs', $context);

        $this->assertTrue($first->needsUserInput);

        $second = $runtime->process('continue with products', $context);

        $this->assertTrue($second->needsUserInput);
        $this->assertSame('pending_confirmation_changed_by_user', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
    }

    public function test_read_only_followup_preserves_pending_write_confirmation_for_later_approval(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
            [
                'action' => 'final',
                'message' => 'The invoice is still waiting for confirmation.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-read-only-keeps-pending-confirmation', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                    [
                        'tool' => 'lookup_product',
                        'params' => ['query' => 'Laptop'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 10],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);

        $status = $runtime->process('Show me the current invoice status.', $context);
        $this->assertFalse($status->needsUserInput);
        $this->assertSame('The invoice is still waiting for confirmation.', $status->message);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);

        $created = $runtime->process('Confirm', $context);
        $this->assertTrue($created->success);
        $this->assertFalse($created->needsUserInput);
        $this->assertSame('Invoice created.', $created->message);
        $this->assertCount(1, $toolLog['create_invoice']);
    }

    public function test_pending_write_confirmation_is_cancelled_when_user_changes_instruction(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Zeta Labs', 'email' => 'billing@zetalabs.test'],
                'message' => 'Create Zeta Labs?',
            ],
            [
                'action' => 'ask_user',
                'message' => 'I cancelled the pending write and will use Ahmed instead.',
                'data' => [
                    'current_payload' => ['customer_name' => 'Ahmed'],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-pending-cancelled-on-change', 77);
        $first = $runtime->process('Create customer Zeta Labs', $context);

        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_customer', $context->metadata['ai_native']['task_frame']['pending_tool']['name']);

        $second = $runtime->process('Actually use existing Ahmed instead.', $context);

        $this->assertTrue($second->needsUserInput);
        $this->assertArrayNotHasKey('pending_tool', $context->metadata['ai_native']);
        $this->assertArrayNotHasKey('pending_tool', $context->metadata['ai_native']['task_frame']);
        $this->assertSame('working', $context->metadata['ai_native']['task_frame']['status']);
        $this->assertSame('pending_confirmation_changed_by_user', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
    }

    public function test_skill_relation_write_must_lookup_same_record_before_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['name' => 'Apollo Labs', 'email' => 'apollo@example.com'],
                'message' => 'Create Apollo Labs?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Apollo Labs'],
                'message' => 'Checking customer first.',
            ],
        ], $toolLog);

        $response = $runtime->process('Create invoice for Apollo Labs', new UnifiedActionContext('ai-native-write-lookup-first', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Apollo Labs', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
    }

    public function test_lookup_before_write_uses_generic_entity_name_fields(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_vendor',
                'arguments' => ['vendor_name' => 'Northwind', 'vendor_email' => 'billing@northwind.test'],
                'message' => 'Create Northwind?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_vendor',
                'arguments' => ['query' => 'Northwind'],
                'message' => 'Checking vendor first.',
            ],
        ], $toolLog);

        $response = $runtime->process('Create contract for Northwind', new UnifiedActionContext('ai-native-generic-write-lookup-first', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Northwind', $toolLog['lookup_vendor'][0]['query']);
        $this->assertArrayNotHasKey('create_vendor', $toolLog);
    }

    public function test_lookup_before_write_can_use_tool_metadata_instead_of_name_conventions(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'vendor_writer',
                'arguments' => ['vendor_name' => 'Northwind'],
                'message' => 'Create Northwind?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'vendor_finder',
                'arguments' => ['query' => 'Northwind'],
                'message' => 'Checking vendor first.',
            ],
        ], $toolLog);

        $response = $runtime->process('Onboard vendor Northwind', new UnifiedActionContext('ai-native-metadata-lookup-write', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Northwind', $toolLog['vendor_finder'][0]['query']);
        $this->assertArrayNotHasKey('vendor_writer', $toolLog);
    }

    public function test_lookup_not_found_detection_can_use_tool_metadata(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'vendor_finder',
                'arguments' => ['query' => 'Missing Vendor'],
                'message' => 'Checking vendor.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'What email should I use?',
                'required_inputs' => [
                    ['name' => 'vendor_email', 'type' => 'text', 'required' => true],
                ],
            ],
        ], $toolLog);

        $response = $runtime->process('Onboard vendor Missing Vendor', new UnifiedActionContext('ai-native-metadata-lookup-not-found', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Missing Vendor', $toolLog['vendor_finder'][0]['query']);
        $this->assertSame('What email should I use?', $response->message);
    }

    public function test_lookup_before_ask_uses_generic_entity_required_inputs(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_vendor',
                'arguments' => ['query' => 'Northwind'],
                'message' => 'Checking vendor.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Which location should I use?',
                'required_inputs' => [
                    ['name' => 'location_name', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_location',
                'arguments' => ['query' => 'Warehouse A'],
                'message' => 'Checking location first.',
            ],
        ], $toolLog);

        $response = $runtime->process('Create contract for Northwind at Warehouse A', new UnifiedActionContext('ai-native-generic-ask-lookup-first', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Northwind', $toolLog['lookup_vendor'][0]['query']);
        $this->assertSame('Warehouse A', $toolLog['lookup_location'][0]['query']);
    }

    public function test_confirm_uses_active_skill_current_payload_to_open_final_tool_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([], $toolLog);

        $context = new UnifiedActionContext('ai-native-confirm-current-payload', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('confirm', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('create_invoice', $response->data['pending_tool']['name']);
        $this->assertSame(2, $response->data['pending_tool']['params']['items'][0]['quantity']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_confirmed_required_final_tool_returns_completion_without_reopening_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([], $toolLog);

        $context = new UnifiedActionContext('ai-native-final-tool-completes', 77, metadata: [
            'ai_native' => [
                'pending_tool' => [
                    'name' => 'create_invoice',
                    'params' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'confirming',
                    'pending_tool' => [
                        'name' => 'create_invoice',
                    ],
                ],
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                    [
                        'tool' => 'lookup_product',
                        'params' => ['query' => 'Laptop'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 10],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('confirm', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Invoice created.', $response->message);
        $this->assertCount(1, $toolLog['create_invoice']);
        $this->assertNull($context->metadata['ai_native']['pending_tool'] ?? null);
    }

    public function test_completed_confirmed_write_is_not_executed_twice_for_same_payload(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
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
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => ['email' => 'ahmed@example.com', 'name' => 'Ahmed'],
                'message' => 'Create Ahmed again?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-duplicate-write', 77);

        $first = $runtime->process('Create Ahmed', $context);
        $this->assertTrue($first->needsUserInput);

        $second = $runtime->process('confirm', $context);
        $this->assertTrue($second->success);
        $this->assertCount(1, $toolLog['create_customer']);

        $third = $runtime->process('create it again', $context);

        $this->assertTrue($third->success);
        $this->assertFalse($third->needsUserInput);
        $this->assertSame('That action has already been completed.', $third->message);
        $this->assertCount(1, $toolLog['create_customer']);
    }

    public function test_recent_created_context_prevents_asking_for_identifier_again(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'Please provide the invoice ID or any specific details to retrieve the last created invoice.',
                'required_inputs' => [
                    ['name' => 'invoice_id', 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'action' => 'final',
                'message' => 'The last created invoice is INV-9001 for Ahmed Mooh.',
                'data' => [
                    'invoice_number' => 'INV-9001',
                    'customer_name' => 'Ahmed Mooh',
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-recent-created-followup', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'completed',
                    'completed_writes' => [
                        [
                            'tool' => 'create_invoice',
                            'label' => 'INV-9001',
                            'outcome' => 'created',
                        ],
                    ],
                ],
                'recent_outcomes' => [
                    [
                        'tool' => 'create_invoice',
                        'outcome' => 'created',
                        'success' => true,
                        'entity_type' => 'invoice',
                        'entity_id' => 9001,
                        'label' => 'INV-9001',
                        'display' => [
                            'number' => 'INV-9001',
                            'customer_name' => 'Ahmed Mooh',
                            'total' => 5000,
                        ],
                    ],
                ],
                'tool_results' => [
                    [
                        'tool' => 'create_invoice',
                        'params' => [
                            'customer_id' => 501,
                            'items' => [
                                ['product_id' => 10, 'quantity' => 2],
                            ],
                        ],
                        'result' => [
                            'success' => true,
                            'data' => [
                                'id' => 9001,
                                'number' => 'INV-9001',
                                'customer_name' => 'Ahmed Mooh',
                                'total' => 5000,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('show me last created invoice', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('The last created invoice is INV-9001 for Ahmed Mooh.', $response->message);
        $this->assertSame('recent_context_available', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_read_only_followup_can_answer_from_active_draft_without_forcing_final_tool(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'I do not see a completed invoice yet. There is an active draft for Ahmed Mooh with 2 Macbook Pro and 3 iPhone.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-read-active-draft', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                    'current_payload' => [
                        'customer_id' => 21,
                        'customer_name' => 'Ahmed Mooh',
                        'items' => [
                            ['product_name' => 'Macbook Pro', 'quantity' => 2],
                            ['product_name' => 'iPhone', 'quantity' => 3],
                        ],
                    ],
                    'current_payload_source' => 'ai_plan',
                ],
            ],
        ]);

        $response = $runtime->process('last created invoices', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('I do not see a completed invoice yet. There is an active draft for Ahmed Mooh with 2 Macbook Pro and 3 iPhone.', $response->message);
        $this->assertArrayNotHasKey('runtime_feedback', $context->metadata['ai_native']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_write_confirmation_replaces_progress_statement_with_confirmation_prompt(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'customer_name' => 'Ahmed',
                    'customer_email' => null,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 1, 'unit_price' => 1000, 'discount' => null],
                    ],
                    'notes' => '',
                ],
                'message' => 'Creating the invoice.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-write-confirm-message', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Please review before I run create invoice.', $response->message);
        $this->assertStringContainsString('Summary:', $response->message);
        $this->assertStringContainsString('Customer: Ahmed', $response->message);
        $this->assertStringContainsString('Items:', $response->message);
        $this->assertStringContainsString('Product: Laptop', $response->message);
        $this->assertStringContainsString('Quantity: 1', $response->message);
        $this->assertStringContainsString('Unit Price: 1000', $response->message);
        $this->assertStringContainsString('Choose Confirm to continue, or Change to edit before execution.', $response->message);
        $this->assertStringNotContainsString('customer_id', $response->message);
        $this->assertStringNotContainsString('product_id', $response->message);
        $this->assertStringNotContainsString('product_name', $response->message);
        $this->assertStringNotContainsString('unit_price', $response->message);
        $this->assertStringNotContainsString('Customer Email: null', $response->message);
        $this->assertStringNotContainsString('Discount: null', $response->message);
        $this->assertStringNotContainsString('Notes:', $response->message);
        $this->assertStringNotContainsString('501', $response->message);
        $this->assertSame('select', $response->requiredInputs[0]['type']);
    }

    public function test_write_confirmation_uses_tool_preview_for_calculated_summary_before_confirm(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice_preview',
                'arguments' => [
                    'customer_name' => 'Ahmed',
                    'items' => [
                        ['product_name' => 'Macbook Pro', 'quantity' => 2, 'unit_price' => 400],
                        ['product_name' => 'iPhone', 'quantity' => 3, 'unit_price' => 200],
                    ],
                ],
                'message' => 'Creating invoice.',
            ],
        ], $toolLog);

        $response = $runtime->process('Create invoice for Ahmed with 2 Macbook Pro and 3 iPhone', new UnifiedActionContext('ai-native-preview-totals', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Subtotal: 1400', $response->message);
        $this->assertStringContainsString('Tax: 140', $response->message);
        $this->assertStringContainsString('Total: 1540', $response->message);
        $this->assertEquals(1540, $response->data['pending_tool']['params']['total'] ?? null);
        $this->assertArrayNotHasKey('create_invoice_preview', $toolLog);
    }

    public function test_write_confirmation_summary_is_configurable_and_redacts_sensitive_fields(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);
        config()->set('ai-agent.ai_native.confirmation_summary.prompt', 'Review {tool} first.');
        config()->set('ai-agent.ai_native.confirmation_summary.heading', 'Pending data:');
        config()->set('ai-agent.ai_native.confirmation_summary.instruction', 'Approve or revise.');

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_customer',
                'arguments' => [
                    'name' => 'Ahmed',
                    'email' => 'ahmed@example.com',
                    'api_key' => 'sk-test-secret',
                    'confirmed' => false,
                ],
                'message' => 'Creating the customer.',
            ],
        ], $toolLog);

        $response = $runtime->process('Create customer Ahmed', new UnifiedActionContext('ai-native-write-summary-redaction', 77));

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Review create customer first.', $response->message);
        $this->assertStringContainsString('Pending data:', $response->message);
        $this->assertStringContainsString('Approve or revise.', $response->message);
        $this->assertStringContainsString('Api Key: [redacted]', $response->message);
        $this->assertStringNotContainsString('api_key', $response->message);
        $this->assertStringNotContainsString('sk-test-secret', $response->message);
        $this->assertStringNotContainsString('confirmed:', $response->message);
    }

    public function test_ai_generated_relation_ids_are_not_trusted_without_tool_result_authority(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 999999,
                    'items' => [
                        ['product_id' => 10, 'quantity' => 1, 'unit_price' => 100],
                    ],
                ],
                'message' => 'Creating invoice.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-untrusted-id', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => false,
                            'data' => ['found' => false],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('create', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Missing required parameter: customer_id', $response->message);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_action_requests_do_not_accept_final_answers_without_tool_evidence(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'Done.',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer first.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'What items should I add?',
                'required_inputs' => [
                    ['name' => 'items', 'type' => 'array', 'required' => true],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-no-fake-final', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What items should I add?', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('final_without_tool_evidence', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_skill_final_payload_cannot_bypass_required_final_tool_when_ai_returns_final(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

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
                'message' => 'Invoice created.',
                'data' => [
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
            ],
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

        $context = new UnifiedActionContext('ai-native-final-tool-required', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Create invoice?', $response->message);
        $this->assertSame(501, $context->metadata['ai_native']['task_frame']['current_payload']['customer_id']);
        $this->assertSame('final_without_required_final_tool', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_ready_skill_payload_uses_final_tool_confirmation_instead_of_conversational_confirm(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Do you want to proceed with creating this invoice?',
                'required_inputs' => [],
                'data' => [
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
            ],
            [
                'action' => 'final',
                'message' => 'The invoice is ready. Do you want to proceed?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-ready-payload-confirmation', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Create invoice?', $response->message);
        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertSame('final_tool_required_before_confirmation_question', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
        $this->assertSame('final_without_required_final_tool', $context->metadata['ai_native']['runtime_feedback'][1]['reason']);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_invoice', $toolLog);
    }

    public function test_final_plan_payload_is_preserved_for_next_turn_context(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'The draft is ready.',
                'data' => [
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-rejected-final-payload', 77);
        $response = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertStringContainsString('Missing required parameter: customer_id', $response->message);
        $this->assertSame(2, $context->metadata['ai_native']['task_frame']['current_payload']['items'][0]['quantity']);
        $this->assertSame('ai_plan', $context->metadata['ai_native']['task_frame']['current_payload_source']);
        $this->assertSame('final_without_required_final_tool', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_ai_plan_payload_is_preserved_between_turns_for_active_skill_drafts(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 1);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'I found the customer. What should I change?',
                'data' => [
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                        ],
                    ],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-current-payload', 77);
        $response = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame(501, $context->metadata['ai_native']['task_frame']['current_payload']['customer_id']);
        $this->assertSame(2, $context->metadata['ai_native']['task_frame']['current_payload']['items'][0]['quantity']);
        $this->assertSame('ai_plan', $context->metadata['ai_native']['task_frame']['current_payload_source']);
    }

    public function test_tool_name_action_shape_is_treated_as_tool_call(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed exists.',
                'data' => ['customer_id' => 501],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-tool-action-shape', 77);
        $response = $runtime->process('Check Ahmed', $context);

        $this->assertTrue($response->success);
        $this->assertSame('Ahmed exists.', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
    }

    public function test_tool_name_action_shape_can_use_top_level_arguments(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'lookup_customer',
                'query' => 'Ahmed',
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Ahmed exists.',
                'data' => ['customer_id' => 501],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-tool-action-top-level-args', 77);
        $response = $runtime->process('Check Ahmed', $context);

        $this->assertTrue($response->success);
        $this->assertSame('Ahmed exists.', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
    }

    public function test_action_requests_do_not_accept_final_after_missing_lookup_without_next_step(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Missing Customer'],
                'message' => 'Checking customer.',
            ],
            [
                'action' => 'final',
                'message' => 'Done.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'Customer was not found. What email should I use?',
                'required_inputs' => [
                    ['name' => 'email', 'type' => 'email', 'required' => true],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-missing-lookup-next-step', 77);
        $response = $runtime->process('Create invoice for Missing Customer', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Customer was not found. What email should I use?', $response->message);
        $this->assertSame('Missing Customer', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('missing_lookup_without_next_step', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_action_requests_try_lookup_before_asking_for_resolvable_data(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'What email should I use?',
                'required_inputs' => [
                    ['name' => 'email', 'type' => 'email', 'required' => true],
                ],
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Searching customer first.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'What items should I add?',
                'required_inputs' => [
                    ['name' => 'items', 'type' => 'array', 'required' => true],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-lookup-before-ask', 77);
        $response = $runtime->process('Create invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What items should I add?', $response->message);
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('ask_without_lookup', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_skill_trigger_matching_tolerates_common_stopwords(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'ask_user',
                'message' => 'What email should I use?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Searching customer first.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'What items should I add?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-stopword-trigger', 77);
        $response = $runtime->process('Create an invoice for Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('What items should I add?', $response->message);
        $this->assertSame('ask_without_lookup', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_confirmed_write_tool_continues_through_suggested_tools_and_retries_without_extra_confirmation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_name' => 'Laptop', 'quantity' => 2],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-suggested-tool-continuation', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $first->data['pending_tool']['name']);

        $second = $runtime->process('confirm', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Invoice created.', $second->message);
        $this->assertSame('Laptop', $toolLog['lookup_product'][0]['query']);
        $this->assertCount(2, $toolLog['create_invoice']);
        $this->assertArrayNotHasKey('pending_tool', array_filter($context->metadata['ai_native'] ?? []));
        $this->assertSame('suggested_tool_continuation', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_confirmed_write_can_pause_for_suggested_write_confirmation_and_retry_original_write(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        config()->set('ai-agent.ai_native.auto_confirm_suggested_writes_after_final_confirmation', false);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_name' => 'Missing Corp',
                    'customer_email' => 'missing@example.com',
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'customer_name' => 'Missing Corp',
                    'customer_email' => 'missing@example.com',
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Creating invoice after creating customer.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-suggested-write-confirmation', 77);

        $first = $runtime->process('Create invoice for Missing Corp with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $first->data['pending_tool']['name']);

        $second = $runtime->process('confirm', $context);
        $this->assertTrue($second->needsUserInput);
        $this->assertSame('create_customer', $second->data['pending_tool']['name']);
        $this->assertSame('Missing Corp', $second->data['pending_tool']['params']['name']);
        $this->assertSame('missing@example.com', $second->data['pending_tool']['params']['email']);

        $third = $runtime->process('confirm', $context);
        $this->assertTrue($third->success);
        $this->assertFalse($third->needsUserInput);
        $this->assertSame('Invoice created.', $third->message);
        $this->assertSame('Missing Corp', $toolLog['create_customer'][0]['name']);
        $this->assertCount(3, $toolLog['create_invoice']);
        $this->assertArrayNotHasKey('pending_tool', array_filter($context->metadata['ai_native'] ?? []));
    }

    public function test_confirmed_final_write_auto_confirms_suggested_helper_write_from_confirmed_payload(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 5);
        config()->set('ai-agent.ai_native.auto_confirm_suggested_writes_after_final_confirmation', true);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_name' => 'Missing Corp',
                    'customer_email' => 'missing@example.com',
                    'items' => [
                        ['product_id' => 10, 'product_name' => 'Laptop', 'quantity' => 2, 'unit_price' => 1000],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-auto-confirm-suggested-write', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_product',
                        'params' => ['query' => 'Laptop'],
                        'result' => [
                            'success' => true,
                            'data' => [
                                'found' => true,
                                'id' => 10,
                                'name' => 'Laptop',
                                'price' => 1000,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Missing Corp with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $first->data['pending_tool']['name']);

        $second = $runtime->process('confirm', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Invoice created.', $second->message);
        $this->assertSame('Missing Corp', $toolLog['create_customer'][0]['name']);
        $this->assertTrue($toolLog['create_customer'][0]['confirmed']);
        $this->assertCount(2, $toolLog['create_invoice']);
        $this->assertSame(501, $toolLog['create_invoice'][1]['customer_id']);
        $this->assertArrayNotHasKey('pending_tool', array_filter($context->metadata['ai_native'] ?? []));
        $this->assertContains('create_customer', array_column($context->metadata['ai_native']['tool_results'], 'tool'));
    }

    public function test_confirmed_final_write_auto_retries_after_suggested_helper_write_resolves_list_relation(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 5);
        config()->set('ai-agent.ai_native.auto_confirm_suggested_writes_after_final_confirmation', true);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'customer_name' => 'Ahmed',
                    'items' => [
                        ['product_name' => 'Auto Missing Product', 'quantity' => 2, 'unit_price' => 125],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-auto-retry-suggested-list-relation', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Auto Missing Product at 125', $context);
        $this->assertTrue($first->needsUserInput);
        $this->assertSame('create_invoice', $first->data['pending_tool']['name']);

        $second = $runtime->process('confirm', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Invoice created.', $second->message);
        $this->assertSame('Auto Missing Product', $toolLog['lookup_product'][0]['query']);
        $this->assertSame('Auto Missing Product', $toolLog['create_product'][0]['name']);
        $this->assertTrue($toolLog['create_product'][0]['confirmed']);
        $this->assertCount(2, $toolLog['create_invoice']);
        $this->assertSame(12, $toolLog['create_invoice'][1]['items'][0]['product_id']);
        $this->assertArrayNotHasKey('suggested_tool_continuation', $context->metadata['ai_native']);
        $this->assertArrayNotHasKey('pending_tool', array_filter($context->metadata['ai_native'] ?? []));
    }

    public function test_confirmed_suggested_write_retries_original_confirmed_payload_before_ai_can_drift(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_name' => 'Laptop', 'quantity' => 2],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-suggested-write-payload-mismatch', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);

        $second = $runtime->process('confirm', $context);

        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Invoice created.', $second->message);
        $this->assertCount(2, $toolLog['create_invoice']);
        $this->assertSame('Laptop', $toolLog['create_invoice'][1]['items'][0]['product_name']);
        $this->assertSame(2, $toolLog['create_invoice'][1]['items'][0]['quantity']);
        $this->assertArrayNotHasKey('pending_tool', array_filter($context->metadata['ai_native'] ?? []));
    }

    public function test_suggested_tool_continuation_auto_runs_lookup_when_ai_asks_user_instead(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_name' => 'Laptop', 'quantity' => 2],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-auto-suggested-tool-continuation', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with 2 Laptop', $context);
        $this->assertTrue($first->needsUserInput);

        $second = $runtime->process('confirm', $context);

        $this->assertTrue($second->success);
        $this->assertFalse($second->needsUserInput);
        $this->assertSame('Invoice created.', $second->message);
        $this->assertSame('Laptop', $toolLog['lookup_product'][0]['query']);
        $this->assertCount(2, $toolLog['create_invoice']);
        $this->assertSame('suggested_tool_continuation', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_suggested_tool_continuation_uses_generic_code_fields(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_contract',
                'arguments' => [
                    'vendor_code' => 'VEN-701',
                ],
                'message' => 'Create contract?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-generic-suggested-continuation', 77);
        $first = $runtime->process('Create contract for vendor code VEN-701', $context);
        $this->assertTrue($first->needsUserInput);

        $response = $runtime->process('confirm', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Contract created.', $response->message);
        $this->assertSame('VEN-701', $toolLog['lookup_vendor'][0]['query']);
        $this->assertCount(2, $toolLog['create_contract']);
        $this->assertSame('suggested_tool_continuation', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_unavailable_suggested_tool_does_not_trap_runtime_in_continuation_state(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 3);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_invoice',
                'arguments' => [
                    'customer_id' => 501,
                    'items' => [
                        ['product_name' => 'Unknown Tool Product', 'quantity' => 1],
                    ],
                ],
                'message' => 'Create invoice?',
            ],
            [
                'action' => 'ask_user',
                'message' => 'I could not resolve the product automatically. Which product should I use?',
                'required_inputs' => [
                    ['name' => 'product_name', 'type' => 'text', 'required' => true],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-unavailable-suggested-tool', 77, metadata: [
            'ai_native' => [
                'tool_results' => [
                    [
                        'tool' => 'lookup_customer',
                        'params' => ['query' => 'Ahmed'],
                        'result' => [
                            'success' => true,
                            'data' => ['found' => true, 'id' => 501],
                        ],
                    ],
                ],
            ],
        ]);

        $first = $runtime->process('Create invoice for Ahmed with Unknown Tool Product', $context);
        $this->assertTrue($first->needsUserInput);

        $second = $runtime->process('confirm', $context);

        $this->assertTrue($second->needsUserInput);
        $this->assertSame('I could not resolve the product automatically. Which product should I use?', $second->message);
        $this->assertCount(1, $toolLog['create_invoice']);
        $this->assertArrayNotHasKey('suggested_tool_continuation', $context->metadata['ai_native']);
        $this->assertContains('suggested_tool_continuation_abandoned', array_column($context->metadata['ai_native']['runtime_feedback'], 'reason'));
    }

    public function test_user_change_cancels_stale_suggested_tool_continuation_before_auto_running_it(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 2);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_product',
                'arguments' => ['query' => 'Mouse'],
                'message' => 'Resolving the updated product.',
            ],
            [
                'action' => 'ask_user',
                'message' => 'I switched the draft product to Mouse. What quantity should I use?',
                'required_inputs' => [
                    ['name' => 'quantity', 'type' => 'number', 'required' => true],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-cancel-stale-suggested-continuation', 77, metadata: [
            'ai_native' => [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                    'current_payload' => [
                        'customer_id' => 501,
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2],
                        ],
                    ],
                ],
                'confirmed_write_tools' => [
                    'create_invoice' => true,
                ],
                'suggested_tool_continuation' => [
                    'confirmed_write_tool' => 'create_invoice',
                    'suggested_tools' => ['lookup_product'],
                    'tool_result' => [
                        'success' => false,
                        'data' => [
                            'current_payload' => [
                                'customer_id' => 501,
                                'items' => [
                                    ['product_name' => 'Laptop', 'quantity' => 2],
                                ],
                            ],
                            'missing_fields' => ['items.0.product_id'],
                            'suggested_tools' => ['lookup_product'],
                        ],
                    ],
                ],
            ],
        ]);

        $response = $runtime->process('Change the product to Mouse instead.', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Mouse', $toolLog['lookup_product'][0]['query']);
        $this->assertArrayNotHasKey('suggested_tool_continuation', $context->metadata['ai_native']);
        $this->assertArrayNotHasKey('confirmed_write_tools', $context->metadata['ai_native']);
        $this->assertSame('suggested_tool_continuation_cancelled_by_user', $context->metadata['ai_native']['runtime_feedback'][0]['reason']);
    }

    public function test_chained_suggested_tools_keep_confirmed_write_until_final_retry_completes(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'create_contract',
                'arguments' => [
                    'vendor_code' => 'CHAIN-1',
                ],
                'message' => 'Create contract?',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-chained-suggested-tools', 77);
        $first = $runtime->process('Create contract for vendor code CHAIN-1', $context);
        $this->assertTrue($first->needsUserInput);

        $response = $runtime->process('confirm', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Contract created.', $response->message);
        $this->assertSame('CHAIN-1', $toolLog['lookup_vendor'][0]['query']);
        $this->assertSame('Warehouse A', $toolLog['lookup_location'][0]['query']);
        $this->assertCount(2, $toolLog['create_contract']);
        $this->assertArrayNotHasKey('suggested_tool_continuation', $context->metadata['ai_native']);
    }

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
                if (str_contains((string) ($parameters['query'] ?? ''), 'Failing Missing')) {
                    return ActionResult::failure('Customer not found.', ['found' => false]);
                }

                if (str_contains((string) ($parameters['query'] ?? ''), 'Missing')) {
                    return ActionResult::success('Customer not found.', ['found' => false]);
                }

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
                    'customer_email' => ['type' => 'string', 'required' => false],
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

                if (($parameters['customer_id'] ?? null) === null && trim((string) ($parameters['customer_name'] ?? '')) === '') {
                    $errors[] = 'Missing required parameter: customer_id';
                }

                return array_values(array_unique($errors));
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_invoice'][] = $parameters;

                if (($parameters['customer_id'] ?? null) === null) {
                    return ActionResult::needsUserInput('Resolve customer before creating invoice.', [
                        'current_payload' => $parameters,
                        'missing_fields' => ['customer_id'],
                        'suggested_tool' => 'create_customer',
                    ]);
                }

                $items = (array) ($parameters['items'] ?? []);
                if (($items[0]['product_id'] ?? null) === null) {
                    if (($items[0]['product_name'] ?? null) === 'Auto Missing Product') {
                        return ActionResult::needsUserInput('Resolve or create invoice products before creating invoice.', [
                            'current_payload' => $parameters,
                            'missing_fields' => ['items.0.product_id'],
                            'suggested_tools' => ['lookup_product', 'create_product'],
                        ]);
                    }

                    if (($items[0]['product_name'] ?? null) === 'Unknown Tool Product') {
                        return ActionResult::needsUserInput('Resolve products before creating invoice.', [
                            'current_payload' => $parameters,
                            'missing_fields' => ['items.0.product_id'],
                            'suggested_tools' => ['missing_product_lookup'],
                        ]);
                    }

                    return ActionResult::needsUserInput('Resolve products before creating invoice.', [
                        'current_payload' => $parameters,
                        'missing_fields' => ['items.0.product_id'],
                        'suggested_tools' => ['lookup_product'],
                    ]);
                }

                return ActionResult::success('Invoice created.', ['id' => 9001]);
            }
        });

        $registry->register('create_invoice_preview', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_invoice_preview';
            }

            public function getDescription(): string
            {
                return 'Create an invoice with previewed totals.';
            }

            public function getParameters(): array
            {
                return [
                    'customer_name' => ['type' => 'string', 'required' => true],
                    'items' => ['type' => 'array', 'required' => true],
                    'total' => ['type' => 'number', 'required' => false],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function previewConfirmation(array $parameters, UnifiedActionContext $context): ?ActionResult
            {
                $items = array_map(static function (array $item): array {
                    $quantity = (float) ($item['quantity'] ?? 0);
                    $unitPrice = (float) ($item['unit_price'] ?? 0);

                    return array_merge($item, [
                        'line_total' => $quantity * $unitPrice,
                    ]);
                }, (array) ($parameters['items'] ?? []));
                $subtotal = array_sum(array_column($items, 'line_total'));
                $tax = $subtotal * 0.10;
                $total = $subtotal + $tax;

                return ActionResult::success('Invoice preview is ready.', [
                    'draft' => [
                        'payload' => array_merge($parameters, [
                            'items' => $items,
                            'subtotal' => $subtotal,
                            'tax' => $tax,
                            'total' => $total,
                        ]),
                        'summary' => [
                            'customer_name' => $parameters['customer_name'],
                            'items' => $items,
                            'subtotal' => $subtotal,
                            'tax' => $tax,
                            'total' => $total,
                        ],
                    ],
                ]);
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_invoice_preview'][] = $parameters;

                return ActionResult::success('Invoice created.', ['id' => 9002]);
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
                if (str_contains((string) ($parameters['query'] ?? ''), 'Missing')) {
                    return ActionResult::success('Product not found.', ['found' => false]);
                }

                if (str_contains((string) ($parameters['query'] ?? ''), 'Mouse')) {
                    return ActionResult::success('Product found.', [
                        'found' => true,
                        'id' => 11,
                        'name' => 'Mouse',
                        'price' => 50,
                    ]);
                }

                return ActionResult::success('Product found.', [
                    'found' => true,
                    'id' => 10,
                    'name' => 'Laptop',
                    'price' => 1000,
                ]);
            }
        });

        $registry->register('create_product', new class($toolLog) extends AgentTool {
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
                    'id' => 12,
                    'name' => $parameters['name'],
                    'price' => $parameters['price'],
                ]);
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
                    return ActionResult::needsUserInput('Resolve location before creating contract.', [
                        'current_payload' => [
                            'vendor_code' => 'CHAIN-1',
                            'location_name' => 'Warehouse A',
                        ],
                        'missing_fields' => ['location_id'],
                        'suggested_tool' => 'lookup_location',
                    ]);
                }

                return ActionResult::success('Vendor found.', [
                    'found' => true,
                    'id' => 701,
                    'vendor_name' => $parameters['query'],
                    'vendor_email' => 'billing@northwind.test',
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

                return ActionResult::success('Location found.', [
                    'found' => true,
                    'id' => 801,
                    'location_name' => $parameters['query'],
                ]);
            }
        });

        $registry->register('create_vendor', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_vendor';
            }

            public function getDescription(): string
            {
                return 'Create a vendor.';
            }

            public function getParameters(): array
            {
                return [
                    'vendor_name' => ['type' => 'string', 'required' => true],
                    'vendor_email' => ['type' => 'string', 'required' => true],
                    'vendor_code' => ['type' => 'string', 'required' => false],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_vendor'][] = $parameters;

                if (($parameters['vendor_name'] ?? null) === null && ($parameters['vendor_code'] ?? null) !== null) {
                    return ActionResult::needsUserInput('Resolve vendor before creating contract.', [
                        'current_payload' => $parameters,
                        'missing_fields' => ['vendor_id'],
                        'suggested_tools' => ['lookup_vendor'],
                    ]);
                }

                return ActionResult::success('Vendor created.', [
                    'id' => 701,
                    'vendor_name' => $parameters['vendor_name'] ?? 'Northwind',
                    'vendor_email' => $parameters['vendor_email'] ?? 'billing@northwind.test',
                    'vendor_code' => $parameters['vendor_code'] ?? 'VEN-701',
                ]);
            }
        });

        $registry->register('vendor_finder', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'vendor_finder';
            }

            public function getDescription(): string
            {
                return 'Find a vendor.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function getToolKind(): ?string
            {
                return 'lookup';
            }

            public function getEntityType(): ?string
            {
                return 'vendor';
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['vendor_finder'][] = $parameters;
                if (str_contains((string) ($parameters['query'] ?? ''), 'Missing')) {
                    return ActionResult::success('Vendor not found.', ['found' => false]);
                }

                return ActionResult::success('Vendor found.', [
                    'found' => true,
                    'id' => 701,
                    'vendor_name' => $parameters['query'],
                ]);
            }
        });

        $registry->register('vendor_writer', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'vendor_writer';
            }

            public function getDescription(): string
            {
                return 'Create or update a vendor.';
            }

            public function getParameters(): array
            {
                return [
                    'vendor_name' => ['type' => 'string', 'required' => true],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function getToolKind(): ?string
            {
                return 'write';
            }

            public function getEntityType(): ?string
            {
                return 'vendor';
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['vendor_writer'][] = $parameters;

                return ActionResult::success('Vendor saved.', [
                    'id' => 701,
                    'vendor_name' => $parameters['vendor_name'],
                ]);
            }
        });

        $registry->register('create_contract', new class($toolLog) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_contract';
            }

            public function getDescription(): string
            {
                return 'Create a contract.';
            }

            public function getParameters(): array
            {
                return [
                    'vendor_id' => ['type' => 'integer', 'required' => false],
                    'vendor_code' => ['type' => 'string', 'required' => false],
                    'title' => ['type' => 'string', 'required' => false],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log['create_contract'][] = $parameters;

                if (($parameters['vendor_code'] ?? null) === 'CHAIN-1' && ($parameters['location_id'] ?? null) !== null) {
                    return ActionResult::success('Contract created.', ['id' => 9901]);
                }

                if (($parameters['vendor_id'] ?? null) === null) {
                    return ActionResult::needsUserInput('Resolve vendor before creating contract.', [
                        'current_payload' => $parameters,
                        'missing_fields' => ['vendor_id'],
                        'suggested_tool' => 'lookup_vendor',
                    ]);
                }

                return ActionResult::success('Contract created.', ['id' => 9901]);
            }
        });

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices.',
                triggers: ['create invoice'],
                tools: ['lookup_customer', 'create_customer', 'lookup_product', 'create_product', 'create_invoice'],
                metadata: [
                    'target_json' => [
                        'customer_id' => null,
                        'items' => [],
                    ],
                    'final_tool' => 'create_invoice',
                ]
            ),
            new AgentSkillDefinition(
                id: 'create_contract',
                name: 'Create Contract',
                description: 'Create contracts.',
                triggers: ['create contract'],
                tools: ['lookup_vendor', 'create_vendor', 'lookup_location', 'create_contract'],
                metadata: [
                    'target_json' => [
                        'vendor_id' => null,
                        'vendor_name' => null,
                        'vendor_code' => null,
                        'location_id' => null,
                        'location_name' => null,
                    ],
                    'final_tool' => 'create_contract',
                ]
            ),
            new AgentSkillDefinition(
                id: 'onboard_vendor',
                name: 'Onboard Vendor',
                description: 'Onboard vendors.',
                triggers: ['onboard vendor'],
                tools: ['vendor_finder', 'vendor_writer'],
                metadata: [
                    'target_json' => [
                        'vendor_id' => null,
                        'vendor_name' => null,
                    ],
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
