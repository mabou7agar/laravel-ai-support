<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeOmittedDetailDetector;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Optional details the user typed ("phone +20 …") must not silently vanish from the write.
 */
class AiNativeOmittedDetailTest extends UnitTestCase
{
    private function leadTool(array &$log = []): AgentTool
    {
        return new class($log) extends AgentTool {
            public function __construct(private array &$log) {}

            public function getName(): string
            {
                return 'create_lead';
            }

            public function getDescription(): string
            {
                return 'Create a lead.';
            }

            public function getParameters(): array
            {
                return [
                    'name' => ['type' => 'string', 'required' => true],
                    'email' => ['type' => 'string', 'required' => false],
                    'phone' => ['type' => 'string', 'required' => false],
                    'follow_up_date' => ['type' => 'string', 'required' => false],
                    'expected_value' => ['type' => 'number', 'required' => false],
                    'notes' => ['type' => 'string', 'required' => false],
                    'pipeline_id' => ['type' => 'integer', 'required' => false],
                    'confirmed' => ['type' => 'boolean', 'required' => false],
                ];
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->log[] = $parameters;

                return ActionResult::success('Lead created.', ['id' => 1]);
            }
        };
    }

    public function test_detects_labelled_optional_values_the_call_left_out(): void
    {
        $found = (new AiNativeOmittedDetailDetector())->detect(
            $this->leadTool(),
            ['name' => 'Omar Said'],
            'Create a lead for Omar Said, phone +20 100 123 4567, email omar@example.com, follow up date 2026-10-01, expected value 5000, notes: met at the expo'
        );

        $this->assertSame([
            'email' => 'omar@example.com',
            'phone' => '+20 100 123 4567',
            'follow_up_date' => '2026-10-01',
            'expected_value' => '5000',
            'notes' => 'met at the expo',
        ], $found);
    }

    public function test_never_guesses(): void
    {
        $detector = new AiNativeOmittedDetailDetector();
        $tool = $this->leadTool();

        // Values already in the call, unlabelled values, ids and free text without a separator.
        $this->assertSame([], $detector->detect($tool, ['name' => 'Omar', 'phone' => '+20 100 123 4567'], 'Create a lead for Omar, phone +20 100 123 4567'));
        $this->assertSame([], $detector->detect($tool, ['name' => 'Omar'], 'Create a lead for Omar +20 100 123 4567 omar@example.com'));
        $this->assertSame([], $detector->detect($tool, ['name' => 'Omar'], 'Create a lead for Omar in pipeline 3 with notes about the expo'));
        // A value the planner placed under another key is not "dropped".
        $this->assertSame([], $detector->detect($tool, ['name' => 'Omar', 'notes' => 'Call +20 100 123 4567'], 'lead Omar phone +20 100 123 4567'));
        // Host page context before the fence does not count as the user's words.
        $this->assertSame([], $detector->detect($tool, ['name' => 'Omar'], "TURN CONTEXT\nphone: +20 999 999 9999\nUser request: create a lead for Omar"));
    }

    public function test_runtime_asks_the_planner_once_to_include_the_dropped_phone(): void
    {
        $log = [];
        $registry = new ToolRegistry();
        $registry->register('create_lead', $this->leadTool($log));

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $plans = [
            ['action' => 'tool_call', 'tool' => 'create_lead', 'arguments' => ['name' => 'Omar Said'], 'message' => 'Create lead?'],
            ['action' => 'tool_call', 'tool' => 'create_lead', 'arguments' => ['name' => 'Omar Said', 'phone' => '+20 100 123 4567'], 'message' => 'Create lead?'],
        ];
        $prompts = [];
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->times(2)->andReturnUsing(function ($request) use (&$plans, &$prompts): AIResponse {
            $prompts[] = $request->getPrompt();

            return AIResponse::success(json_encode(array_shift($plans)), 'openai', 'gpt-4o-mini');
        });

        $runtime = new AiNativeRuntime($ai, $registry, $skills, app(IntentSignalService::class));
        $context = new UnifiedActionContext('omitted-detail', 5);

        $runtime->process('Create a lead for Omar Said, phone +20 100 123 4567', $context);

        $this->assertSame('+20 100 123 4567', $context->metadata['ai_native']['pending_tool']['params']['phone']);
        $this->assertStringContainsString('user_details_missing_from_write', $prompts[1]);
        $this->assertSame([], $log, 'Nothing executes before the user confirms.');
    }

    public function test_feedback_is_given_once_then_the_confirmation_proceeds(): void
    {
        config()->set('ai-agent.ai_native.max_steps', 4);
        $registry = new ToolRegistry();
        $registry->register('create_lead', $this->leadTool());
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $plan = ['action' => 'tool_call', 'tool' => 'create_lead', 'arguments' => ['name' => 'Omar Said'], 'message' => 'Create lead?'];
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->times(2)->andReturn(
            AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
            AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'),
        );

        $runtime = new AiNativeRuntime($ai, $registry, $skills, app(IntentSignalService::class));
        $context = new UnifiedActionContext('omitted-detail-once', 5);
        $runtime->process('Create a lead for Omar Said, phone +20 100 123 4567', $context);

        $this->assertSame('create_lead', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('phone', $context->metadata['ai_native']['pending_tool']['params']);
    }
}
