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

class AiNativeAutoRetryTest extends UnitTestCase
{
    public function test_recoverable_failure_is_retried_before_escalating_when_enabled(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.auto_retry.max', 1);

        $log = ['calls' => 0, 'feedback_seen' => []];

        $runtime = $this->runtime([
            $this->toolCallPlan(),
            $this->toolCallPlan(),
            ['action' => 'final', 'message' => 'done', 'data' => ['ok' => true]],
        ], $log);

        $context = new UnifiedActionContext('ai-native-auto-retry-success', 77);
        $response = $runtime->process('Do the thing', $context);

        $this->assertTrue($response->success);
        $this->assertFalse($response->needsUserInput);
        $this->assertSame('done', $response->message);

        // The tool was executed twice (fail then success).
        $this->assertSame(2, $log['calls']);

        // Between the failing attempt and the retry, a recoverable-failure
        // feedback entry was visible to the planner.
        $this->assertContains('tool_execution_recoverable_failure', $log['feedback_seen']);
    }

    public function test_default_behavior_escalates_immediately_when_disabled(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        // auto_retry.max left at its default (0).

        $log = ['calls' => 0, 'feedback_seen' => []];

        $runtime = $this->runtime([
            $this->toolCallPlan(),
        ], $log);

        $context = new UnifiedActionContext('ai-native-auto-retry-default', 77);
        $response = $runtime->process('Do the thing', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame(1, $log['calls']);
    }

    public function test_retry_is_bounded_and_escalates_after_max_attempts(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.auto_retry.max', 1);

        // Tool that ALWAYS fails.
        $log = ['calls' => 0, 'feedback_seen' => [], 'always_fail' => true];

        $runtime = $this->runtime([
            $this->toolCallPlan(),
            $this->toolCallPlan(),
        ], $log);

        $context = new UnifiedActionContext('ai-native-auto-retry-bound', 77);
        $response = $runtime->process('Do the thing', $context);

        // 1 original + 1 retry, then escalate.
        $this->assertTrue($response->needsUserInput);
        $this->assertSame(2, $log['calls']);
    }

    public function test_needs_user_input_result_is_never_retried(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 6);
        config()->set('ai-agent.ai_native.auto_retry.max', 2);

        $log = ['calls' => 0, 'feedback_seen' => [], 'needs_input' => true];

        $runtime = $this->runtime([
            $this->toolCallPlan(),
        ], $log);

        $context = new UnifiedActionContext('ai-native-auto-retry-guard', 77);
        $response = $runtime->process('Do the thing', $context);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame(1, $log['calls']);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolCallPlan(): array
    {
        return [
            'action' => 'tool_call',
            'tool' => 'flaky_tool',
            'arguments' => ['query' => 'go'],
            'message' => 'Running the flaky tool.',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @param array<string, mixed> $log
     */
    private function runtime(array $plans, array &$log): AiNativeRuntime
    {
        $registry = new ToolRegistry();
        $registry->register('flaky_tool', new class($log) extends AgentTool {
            /** @param array<string, mixed> $log */
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'flaky_tool';
            }

            public function getDescription(): string
            {
                return 'A tool that fails once then succeeds.';
            }

            public function getParameters(): array
            {
                return ['query' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                // Record any runtime feedback that was fed back into state
                // before this invocation (proves the retry context is visible).
                foreach ((array) data_get($context->metadata, 'ai_native.runtime_feedback', []) as $entry) {
                    if (isset($entry['reason'])) {
                        $this->log['feedback_seen'][] = $entry['reason'];
                    }
                }

                $this->log['calls']++;

                if (($this->log['needs_input'] ?? false) === true) {
                    return ActionResult::needsUserInput('Need more info.', [
                        'required_inputs' => ['something'],
                    ]);
                }

                if (($this->log['always_fail'] ?? false) === true) {
                    return ActionResult::failure('Transient failure.');
                }

                // Fail on the first call, succeed afterwards.
                if ($this->log['calls'] === 1) {
                    return ActionResult::failure('Transient failure.');
                }

                return ActionResult::success('Tool succeeded.', ['ok' => true]);
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
