<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativeActivityCallbackTest extends UnitTestCase
{
    public function test_on_activity_reports_thinking_and_tool_lifecycle(): void
    {
        $activity = [];
        $runtime = $this->runtime([
            ['action' => 'tool_call', 'tool' => 'lookup_customer', 'arguments' => ['query' => 'Acme'], 'reasoning' => 'I need the customer record first.'],
            ['action' => 'final', 'message' => 'Done.'],
        ]);

        $response = $runtime->process('Find Acme', new UnifiedActionContext('activity-hook'), [
            'on_activity' => function (string $type, array $payload) use (&$activity): void {
                $activity[] = [$type, $payload];
            },
        ]);

        $this->assertTrue($response->success);

        $types = array_map(static fn (array $a): string => $a[0], $activity);
        // Step 1: thinking (pre-plan) → thinking (reasoning) → tool_call → tool_result;
        // step 2: thinking (pre-plan) → final (no tool events).
        $this->assertContains('thinking', $types);
        $this->assertContains('tool_call', $types);
        $this->assertContains('tool_result', $types);
        $this->assertGreaterThan(
            array_search('tool_call', $types, true),
            array_search('tool_result', $types, true),
            'tool_result must follow tool_call',
        );

        $reasoning = array_values(array_filter($activity, static fn (array $a): bool => $a[0] === 'thinking' && ($a[1]['content'] ?? '') !== ''));
        $this->assertSame('I need the customer record first.', $reasoning[0][1]['content'] ?? null);

        $toolCalls = array_values(array_filter($activity, static fn (array $a): bool => $a[0] === 'tool_call'));
        $this->assertSame('lookup_customer', $toolCalls[0][1]['tool_name'] ?? null);
    }

    public function test_missing_or_throwing_callback_never_breaks_the_turn(): void
    {
        $runtime = $this->runtime([
            ['action' => 'final', 'message' => 'Plain answer.'],
        ]);

        $response = $runtime->process('hello', new UnifiedActionContext('activity-none'), [
            'on_activity' => static function (): void {
                throw new \RuntimeException('observer exploded');
            },
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Plain answer.', $response->message);
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     */
    private function runtime(array $plans): AiNativeRuntime
    {
        $registry = new ToolRegistry();
        $registry->register('lookup_customer', new class () extends AgentTool {
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
                return ActionResult::success('Customer found.', ['id' => 501]);
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

        return new AiNativeRuntime($ai, $registry, $skills, app(IntentSignalService::class));
    }
}
