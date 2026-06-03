<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeParallelToolCallsTest extends UnitTestCase
{
    public function test_parallel_tool_calls_records_both_results_in_one_step_when_flag_on(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        config()->set('ai-agent.ai_native.parallel_tools', true);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'message' => 'Looking up both records.',
                'tool_calls' => [
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                    ['tool' => 'lookup_product', 'arguments' => ['query' => 'Widget']],
                ],
            ],
            [
                'action' => 'final',
                'message' => 'Both found.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-parallel-on', 77);
        $response = $runtime->process('Check Ahmed and Widget', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('Both found.', $response->message);

        // Both lookups ran from the SINGLE batch plan (one generate() consumed by step 0).
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertSame('Widget', $toolLog['lookup_product'][0]['query']);

        $tools = array_column($context->metadata['ai_native']['tool_results'], 'tool');
        $this->assertContains('lookup_customer', $tools);
        $this->assertContains('lookup_product', $tools);
    }

    public function test_parallel_tools_disabled_ignores_tool_calls_array(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        // Flag defaults to false; behavior falls through to the scalar 'tool' single-call path.

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'message' => 'Scalar tool path.',
                'tool_calls' => [
                    ['tool' => 'lookup_product', 'arguments' => ['query' => 'Widget']],
                ],
            ],
            [
                'action' => 'final',
                'message' => 'Done.',
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-parallel-off', 77);
        $response = $runtime->process('Check Ahmed', $context);

        $this->assertTrue($response->success);
        // Only the scalar tool ran; the tool_calls[] array was NOT batch-executed.
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('lookup_product', $toolLog);
    }

    public function test_parallel_batch_stops_and_surfaces_confirmation_for_write_entry(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        config()->set('ai-agent.ai_native.parallel_tools', true);

        $toolLog = [];
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'message' => 'Looking up and creating.',
                'tool_calls' => [
                    ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                    ['tool' => 'create_customer', 'arguments' => ['name' => 'Ahmed', 'email' => 'ahmed@example.com']],
                ],
            ],
        ], $toolLog);

        $context = new UnifiedActionContext('ai-native-parallel-confirm', 77);
        $response = $runtime->process('Create Ahmed', $context);

        $this->assertTrue($response->needsUserInput);
        // The read lookup ran, but the write entry stopped the batch for confirmation
        // instead of executing silently.
        $this->assertSame('Ahmed', $toolLog['lookup_customer'][0]['query']);
        $this->assertArrayNotHasKey('create_customer', $toolLog);
        $this->assertSame('create_customer', $context->metadata['ai_native']['pending_tool']['name']);
    }

    public function test_parser_preserves_tool_calls_array_through_parse(): void
    {
        $parser = new \LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser();

        $plan = $parser->parse(json_encode([
            'action' => 'tool_call',
            'message' => 'Batch.',
            'tool_calls' => [
                ['tool' => 'lookup_customer', 'arguments' => ['query' => 'Ahmed']],
                ['tool' => 'lookup_product', 'arguments' => ['query' => 'Widget']],
            ],
        ]));

        $this->assertArrayHasKey('tool_calls', $plan);
        $this->assertCount(2, $plan['tool_calls']);
        $this->assertSame('lookup_customer', $plan['tool_calls'][0]['tool']);
        $this->assertSame('lookup_product', $plan['tool_calls'][1]['tool']);
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, mixed> $toolLog
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
                    'name' => 'Widget',
                    'price' => 25,
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
                ]);
            }
        });

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

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
