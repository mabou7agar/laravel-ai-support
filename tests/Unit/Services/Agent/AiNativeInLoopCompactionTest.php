<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
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

class AiNativeInLoopCompactionTest extends UnitTestCase
{
    public function test_in_loop_compaction_trims_tool_results_when_enabled_and_oversized(): void
    {
        config()->set('ai-agent.ai_native.compaction.enabled', true);
        config()->set('ai-agent.ai_native.compaction.threshold', 2);
        config()->set('ai-agent.ai_native.compaction.keep_recent_results', 1);
        config()->set('ai-agent.ai_native.max_steps', 12);

        $toolLog = [];
        $runtime = $this->runtime($this->lookupPlans(5), $toolLog);

        $context = new UnifiedActionContext('ai-native-compaction-on', 11);
        $response = $runtime->process('Look up several customers', $context);

        $this->assertTrue($response->success);
        $this->assertTrue($response->isComplete);

        $toolResults = $response->metadata['ai_native']['tool_results'] ?? null;
        $this->assertIsArray($toolResults);
        $this->assertLessThanOrEqual(1, count($toolResults));
        $this->assertGreaterThan(0, (int) ($response->metadata['ai_native']['compacted_tool_results'] ?? 0));
    }

    public function test_default_off_leaves_tool_results_untouched(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 12);

        $toolLog = [];
        $runtime = $this->runtime($this->lookupPlans(5), $toolLog);

        $context = new UnifiedActionContext('ai-native-compaction-off', 12);
        $response = $runtime->process('Look up several customers', $context);

        $this->assertTrue($response->success);
        $this->assertTrue($response->isComplete);

        $toolResults = $response->metadata['ai_native']['tool_results'] ?? null;
        $this->assertIsArray($toolResults);
        $this->assertCount(5, $toolResults);
        $this->assertArrayNotHasKey('compacted_tool_results', $response->metadata['ai_native']);
    }

    public function test_compaction_no_op_below_threshold(): void
    {
        config()->set('ai-agent.ai_native.compaction.enabled', true);
        config()->set('ai-agent.ai_native.compaction.threshold', 10);
        config()->set('ai-agent.ai_native.compaction.keep_recent_results', 2);
        config()->set('ai-agent.ai_native.max_steps', 12);

        $toolLog = [];
        $runtime = $this->runtime($this->lookupPlans(3), $toolLog);

        $context = new UnifiedActionContext('ai-native-compaction-below', 13);
        $response = $runtime->process('Look up a few customers', $context);

        $this->assertTrue($response->success);
        $toolResults = $response->metadata['ai_native']['tool_results'] ?? null;
        $this->assertIsArray($toolResults);
        $this->assertCount(3, $toolResults);
        $this->assertArrayNotHasKey('compacted_tool_results', $response->metadata['ai_native']);
    }

    public function test_compact_conversation_flag_invokes_compactor(): void
    {
        config()->set('ai-agent.ai_native.compaction.enabled', true);
        config()->set('ai-agent.ai_native.compaction.threshold', 100);
        config()->set('ai-agent.ai_native.compaction.compact_conversation', true);
        config()->set('ai-agent.ai_native.max_steps', 12);

        $compactor = Mockery::mock(ConversationContextCompactor::class);
        $compactor->shouldReceive('compact')->atLeast()->once();

        $toolLog = [];
        $runtime = $this->runtime($this->lookupPlans(1), $toolLog, $compactor);

        $context = new UnifiedActionContext('ai-native-compaction-conv', 14);
        $response = $runtime->process('Look up a customer', $context);

        $this->assertTrue($response->success);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lookupPlans(int $toolCalls): array
    {
        $plans = [];
        for ($i = 0; $i < $toolCalls; $i++) {
            $plans[] = [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Customer ' . $i],
                'message' => 'Looking up customer ' . $i . '.',
            ];
        }

        $plans[] = [
            'action' => 'final',
            'message' => 'Done looking up customers.',
        ];

        return $plans;
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     */
    private function runtime(array $plans, array &$toolLog, ?ConversationContextCompactor $compactor = null): AiNativeRuntime
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
