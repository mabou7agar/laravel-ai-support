<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationPresenter;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseFactory;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativePlanTimelineTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     */
    private function runtime(array $plans): AiNativeRuntime
    {
        $registry = new ToolRegistry();
        $registry->register('lookup_customer', new class extends AgentTool {
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
                return ActionResult::success('Customer found.', ['found' => true, 'id' => 1, 'name' => 'Ahmed']);
            }
        });

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
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

    public function test_runtime_populates_plan_metadata_when_flag_on(): void
    {
        config()->set('ai-agent.ai_native.plan_timeline', true);

        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'All set.',
                'steps' => ['Find customer', 'Create invoice'],
            ],
        ]);

        $response = $runtime->process('do it', new UnifiedActionContext(sessionId: 'plan-e2e-on'));

        $this->assertArrayHasKey('plan', $response->metadata);
        $this->assertSame(['Find customer', 'Create invoice'], $response->metadata['plan']['steps']);
        $this->assertSame(1, $response->metadata['plan']['current']);
        $this->assertArrayHasKey('ai_native', $response->metadata);
    }

    public function test_runtime_advances_current_index_across_planning_round_trips(): void
    {
        config()->set('ai-agent.ai_native.plan_timeline', true);
        config()->set('ai-agent.ai_native.max_steps', 6);

        // First plan calls a tool (loop continues), second plan finalizes. Both carry
        // the steps list, so capturePlanTimeline runs twice and the current index
        // advances 1 -> 2 (clamped to the 2-step count).
        $runtime = $this->runtime([
            [
                'action' => 'tool_call',
                'tool' => 'lookup_customer',
                'arguments' => ['query' => 'Ahmed'],
                'steps' => ['Find customer', 'Create invoice'],
            ],
            [
                'action' => 'final',
                'message' => 'Done.',
                'steps' => ['Find customer', 'Create invoice'],
            ],
        ]);

        $response = $runtime->process('do it', new UnifiedActionContext(sessionId: 'plan-e2e-advance'));

        $this->assertSame(['Find customer', 'Create invoice'], $response->metadata['plan']['steps']);
        // current advanced to 2 across the two planning round-trips, clamped to count.
        $this->assertSame(2, $response->metadata['plan']['current']);
    }

    public function test_runtime_omits_plan_metadata_when_flag_off(): void
    {
        config()->set('ai-agent.ai_native.plan_timeline', false);

        $runtime = $this->runtime([
            [
                'action' => 'final',
                'message' => 'All set.',
                'steps' => ['Find customer', 'Create invoice'],
            ],
        ]);

        $response = $runtime->process('do it', new UnifiedActionContext(sessionId: 'plan-e2e-off'));

        $this->assertArrayNotHasKey('plan', $response->metadata);
        // Default-off path leaves the ai_native metadata intact.
        $this->assertArrayHasKey('ai_native', $response->metadata);
    }

    private function factory(): AiNativeResponseFactory
    {
        return new AiNativeResponseFactory(
            new AiNativeStateStore(),
            new ToolRegistry(),
            new AiNativeConfirmationPresenter()
        );
    }

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext(sessionId: 'sess-plan');
    }

    public function test_parser_preserves_steps_key(): void
    {
        $plan = (new AiNativeResponseParser())->parse('{"action":"final","message":"x","steps":["Find customer","Create invoice"]}');

        $this->assertSame('final', $plan['action']);
        $this->assertSame(['Find customer', 'Create invoice'], $plan['steps']);
    }

    public function test_factory_copies_plan_snapshot_to_top_level_metadata_in_success(): void
    {
        $response = $this->factory()->final(
            $this->context(),
            ['plan_timeline' => ['steps' => ['Find customer', 'Create invoice'], 'current' => 2]],
            'Done.'
        );

        $this->assertArrayHasKey('plan', $response->metadata);
        $this->assertSame(['Find customer', 'Create invoice'], $response->metadata['plan']['steps']);
        $this->assertSame(2, $response->metadata['plan']['current']);
        // Still nested under ai_native too, not replacing it.
        $this->assertArrayHasKey('ai_native', $response->metadata);
    }

    public function test_factory_copies_plan_snapshot_in_needs_user_input(): void
    {
        $response = $this->factory()->needsUserInput(
            $this->context(),
            ['plan_timeline' => ['steps' => ['Ask for email'], 'current' => 1]],
            'What is the email?'
        );

        $this->assertSame(['Ask for email'], $response->metadata['plan']['steps']);
        $this->assertSame(1, $response->metadata['plan']['current']);
    }

    public function test_factory_omits_plan_when_snapshot_absent(): void
    {
        $response = $this->factory()->final($this->context(), [], 'Done.');

        $this->assertArrayNotHasKey('plan', $response->metadata);
        // Default-off path leaves ai_native intact and byte-for-byte unchanged.
        $this->assertArrayHasKey('ai_native', $response->metadata);
    }

    public function test_factory_omits_plan_when_steps_empty(): void
    {
        $response = $this->factory()->final(
            $this->context(),
            ['plan_timeline' => ['steps' => [], 'current' => 0]],
            'Done.'
        );

        $this->assertArrayNotHasKey('plan', $response->metadata);
    }

    public function test_factory_filters_and_clamps_plan_snapshot(): void
    {
        $response = $this->factory()->final(
            $this->context(),
            ['plan_timeline' => ['steps' => ['  ', 'Real step', 123, ''], 'current' => 99]],
            'Done.'
        );

        $this->assertSame(['Real step'], $response->metadata['plan']['steps']);
        // current is clamped down to the (filtered) step count.
        $this->assertSame(1, $response->metadata['plan']['current']);
    }

    public function test_prompt_builder_includes_steps_instruction_when_flag_on(): void
    {
        config()->set('ai-agent.ai_native.plan_timeline', true);

        $prompt = $this->promptBuilder()->build('hi', $this->context(), []);

        $this->assertStringContainsString('"steps":', $prompt);
    }

    public function test_prompt_builder_omits_steps_instruction_when_flag_off(): void
    {
        config()->set('ai-agent.ai_native.plan_timeline', false);

        $prompt = $this->promptBuilder()->build('hi', $this->context(), []);

        $this->assertStringNotContainsString('listing the remaining steps you intend to take', $prompt);
    }

    private function promptBuilder(): AiNativePromptBuilder
    {
        return new AiNativePromptBuilder(
            new ToolRegistry(),
            app(AgentSkillRegistry::class)
        );
    }
}
