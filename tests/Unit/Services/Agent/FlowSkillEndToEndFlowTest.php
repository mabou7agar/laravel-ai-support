<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * End-to-end "skill" flow:
 *   1. A skill is registered through a fake AgentSkillProvider (trigger + tools + ai_native planner).
 *   2. A chat turn whose message matches the skill is driven through AgentSkillMatchStage.
 *   3. The stage matches + plans the skill into a run_skill routing decision.
 *   4. RunSkillTool executes that decision via a mocked AiNativeRuntime and surfaces the result.
 *
 * No real network/AI calls happen — the runtime is mocked.
 */
class FlowSkillEndToEndFlowTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-agent.skills.enabled', true);
        config()->set('ai-agent.skills.prefer_deterministic_matches', true);
        // Register our skill end-to-end through the provider config the registry reads.
        config()->set('ai-agent.skill_providers', [
            'flowskill' => FlowSkillFakeProvider::class,
        ]);
    }

    public function test_provider_registered_skill_is_discoverable_through_registry(): void
    {
        $skills = app(AgentSkillRegistry::class)->skills();

        $ids = array_map(static fn (AgentSkillDefinition $skill): string => $skill->id, $skills);

        $this->assertContains('flowskill_translate', $ids);
    }

    public function test_matching_message_is_routed_to_run_skill_and_executes_with_result(): void
    {
        $context = new UnifiedActionContext('flowskill-e2e');
        $message = 'translate this document to french';

        // --- Stage 1: the chat turn is matched + planned by the skill match stage. ---
        $stage = new AgentSkillMatchStage(
            new AgentSkillMatcher(app(AgentSkillRegistry::class)),
            new AgentSkillExecutionPlanner()
        );

        $decision = $stage->decide($message, $context);

        $this->assertNotNull($decision, 'Expected the skill match stage to produce a routing decision.');
        $this->assertSame(RoutingDecisionAction::USE_TOOL, $decision->action);
        $this->assertSame(RoutingDecisionSource::DETERMINISTIC, $decision->source);
        $this->assertSame('run_skill', $decision->payload['resource_name']);
        $this->assertSame('flowskill_translate', $decision->payload['params']['skill_id']);
        $this->assertSame($message, $decision->payload['params']['message']);
        $this->assertSame('flowskill_translate', $decision->metadata['matched_skill']['skill_id'] ?? null);

        // --- Stage 2: the planned run_skill tool executes the skill (runtime mocked). ---
        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->with(
                $message,
                $context,
                Mockery::on(static function (array $options): bool {
                    return ($options['skill_id'] ?? null) === 'flowskill_translate'
                        && ($options['runtime_scope'] ?? null) === 'skill';
                })
            )
            ->andReturn(new AgentResponse(
                success: true,
                message: 'Translated: bonjour le monde.',
                context: $context,
                metadata: ['executed_tool' => 'flowskill_translate_tool'],
                isComplete: true
            ));

        $tool = new RunSkillTool(
            Mockery::mock(AgentSkillRegistry::class),
            app(ConversationMemory::class),
            Mockery::mock(AIEngineService::class),
            new ToolRegistry(),
            null,
            null,
            $native
        );

        $result = $tool->execute($decision->payload['params'], $context);

        $this->assertTrue($result->success);
        $this->assertSame('Translated: bonjour le monde.', $result->message);
        $this->assertSame('flowskill_translate_tool', $result->metadata['executed_tool'] ?? null);
        // The selected skill is surfaced into the shared context state.
        $this->assertSame('flowskill_translate', $context->metadata['ai_native']['selected_skill_id']);
        $this->assertSame('skill', $context->metadata['ai_native']['runtime_scope']);
    }

    public function test_unrelated_message_does_not_match_the_skill(): void
    {
        $stage = new AgentSkillMatchStage(
            new AgentSkillMatcher(app(AgentSkillRegistry::class)),
            new AgentSkillExecutionPlanner()
        );

        $decision = $stage->decide('what is the weather like today', new UnifiedActionContext('flowskill-miss'));

        $this->assertNull($decision);
    }
}

class FlowSkillFakeProvider implements AgentSkillProvider
{
    public function skills(): iterable
    {
        yield new AgentSkillDefinition(
            id: 'flowskill_translate',
            name: 'Translate Document',
            description: 'Translate a document into another language using the translation tool.',
            triggers: ['translate'],
            tools: ['flowskill_translate_tool'],
            metadata: [
                'planner' => 'ai_native',
                'target_json' => ['language' => null],
                'final_tool' => 'flowskill_translate_tool',
            ],
            enabled: true
        );
    }
}
