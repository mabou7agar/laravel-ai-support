<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestRealAgentFlowCommandTest extends UnitTestCase
{
    public function test_command_outputs_json_summary_for_multiple_messages(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $ctx1 = new UnifiedActionContext('s1', 1);
        $ctx1->metadata['tool_used'] = 'db_query';
        $ctx2 = new UnifiedActionContext('s1', 1);
        $ctx2->metadata['tool_used'] = 'vector_search';

        $runtime->shouldReceive('process')
            ->twice()
            ->andReturn(
                AgentResponse::conversational('Listed results', $ctx1),
                AgentResponse::conversational('Follow-up answer', $ctx2)
            );

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['list invoices', 'what is status of first one'],
            '--session' => 'real-test-session',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertIsArray($payload);
        $this->assertSame(2, $payload['summary']['total_turns']);
        $this->assertSame(2, $payload['summary']['successful_turns']);
        $this->assertSame(0, $payload['summary']['failed_turns']);
        $this->assertSame(1, $payload['summary']['tool_counts']['db_query']);
        $this->assertSame(1, $payload['summary']['tool_counts']['vector_search']);
    }

    public function test_command_handles_exceptions_and_returns_failure(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->andThrow(new \RuntimeException('Live provider error'));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['list invoices'],
            '--stop-on-error' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['summary']['failed_turns']);
        $this->assertSame(false, $payload['turns'][0]['success']);
    }

    public function test_command_treats_unsuccessful_agent_response_as_failed_turn(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::failure('No tool specified', context: new UnifiedActionContext('s-failed', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['show me recent updates'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, $payload['summary']['total_turns']);
        $this->assertSame(0, $payload['summary']['successful_turns']);
        $this->assertSame(1, $payload['summary']['failed_turns']);
        $this->assertSame(0, $payload['summary']['error_turns']);
    }

    public function test_command_passes_local_only_option_to_orchestrator(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->withArgs(function ($message, $sessionId, $userId, $options) {
                return $message === 'hello'
                    && is_array($options)
                    && ($options['local_only'] ?? false) === true;
            })
            ->andReturn(AgentResponse::conversational('Hello!', new UnifiedActionContext('s2', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['hello'],
            '--local-only' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertTrue($payload['summary']['local_only']);
    }

    public function test_command_passes_rag_models_to_runtime(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->once()
            ->withArgs(function ($message, $sessionId, $userId, $options) {
                return $message === 'Tell me about Apollo'
                    && ($options['rag_collections'] ?? []) === ['App\\Models\\Document'];
            })
            ->andReturn(AgentResponse::conversational('Apollo context', new UnifiedActionContext('s3', 1)));

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        Artisan::call('ai-engine:test-real-agent', [
            '--message' => ['Tell me about Apollo'],
            '--rag-model' => ['App\\Models\\Document'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(['App\\Models\\Document'], $payload['summary']['rag_collections']);
    }

    public function test_command_runs_invoice_create_script_with_assertions(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $messages = [
            'create invoice',
            'Mohamed Abou Hagar',
            'mohamed@example.test',
            'actually change customer name to Mohamed Hagar before confirmation',
            'yes create the customer',
            '2 Macbook Pro and 3 iPhone',
            'remove 1 iPhone and add 1 iPad',
            'confirm',
            'yes',
        ];

        $responses = [
            AgentResponse::needsUserInput('What is the customer name or email?', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Customer not found. Should I create a new customer?', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('I have Mohamed Abou Hagar with mohamed@example.test.', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Updated customer name to Mohamed Hagar with mohamed@example.test. Please confirm.', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Customer created. What products should be on the invoice?', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Products: 2 Macbook Pro and 3 iPhone. Confirm or edit products.', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Updated products: 2 Macbook Pro, 2 iPhone, 1 iPad. Confirm?', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::needsUserInput('Please review. Confirm to proceed. Type: yes or no.', context: new UnifiedActionContext('invoice-script', 1)),
            AgentResponse::success('Invoice created successfully.', context: new UnifiedActionContext('invoice-script', 1)),
        ];

        $runtime->shouldReceive('process')
            ->times(9)
            ->withArgs(function ($message) use (&$messages): bool {
                return $message === array_shift($messages);
            })
            ->andReturn(...$responses);

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai-engine:test-real-agent', [
            '--script' => 'invoice-create',
            '--assert' => 'invoice-create',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(9, $payload['summary']['total_turns']);
        $this->assertTrue($payload['summary']['assertions']['passed']);
    }

    public function test_invoice_create_assertions_fail_on_placeholder_customer(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);

        $runtime->shouldReceive('process')
            ->times(9)
            ->andReturn(
                AgentResponse::needsUserInput('What is the customer name or email?', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Customer not found. Should I create a new customer?', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('I have Mohamed Abou Hagar with mohamed@example.test.', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Updated customer name to Mohamed Hagar.', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput("Tool 'create_customer' not found. Using customer_id: 0 placeholder.", context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Products: 2 Macbook Pro and 3 iPhone.', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Updated products: 2 Macbook Pro, 2 iPhone, 1 iPad.', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Please review. Confirm to proceed. Type: yes or no.', context: new UnifiedActionContext('invoice-script-fail', 1)),
                AgentResponse::needsUserInput('Final JSON has customer_id: 0 placeholder.', context: new UnifiedActionContext('invoice-script-fail', 1)),
            );

        $this->app->instance(AgentRuntimeContract::class, $runtime);

        $exitCode = Artisan::call('ai-engine:test-real-agent', [
            '--script' => 'invoice-create',
            '--assert' => 'invoice-create',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['summary']['assertions']['passed']);
        $this->assertContains('does_not_report_missing_create_customer_tool', $payload['summary']['assertions']['failures']);
    }
}
