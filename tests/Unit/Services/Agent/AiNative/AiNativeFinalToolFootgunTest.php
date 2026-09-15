<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationIntent;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeFinalToolPolicy;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Guards against the runtime_scope=skill footgun: a bare skill-scoped runtime
 * must not treat EVERY message as skill-matched, and an active objective that
 * was seeded by loose trigger noise (e.g. a host preamble containing a trigger
 * word) must not force that skill's final tool onto unrelated messages.
 * Live-proven failure: "move the pricing section up" looped 8x on
 * final_without_required_final_tool demanding a brand-tokens tool.
 */
class AiNativeFinalToolFootgunTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function policy(): AiNativeFinalToolPolicy
    {
        $skill = new AgentSkillDefinition(
            id: 'adapt_theme_style',
            name: 'Adapt Theme Style',
            description: 'Restyle the site',
            triggers: ['dark theme', 'colors', 'change style'],
            tools: ['set_brand_tokens'],
            metadata: ['final_tool' => 'set_brand_tokens'],
        );

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([$skill]);

        return new AiNativeFinalToolPolicy(
            $registry,
            new AiNativeSkillMatcher($registry),
            new AiNativeConfirmationIntent(app(IntentSignalService::class)),
        );
    }

    public function test_bare_skill_scope_does_not_force_requirement_on_unrelated_messages(): void
    {
        self::assertFalse($this->policy()->requirementApplies(
            'move the pricing section up one position',
            [],
            ['runtime_scope' => 'skill'],
        ));
    }

    public function test_explicit_skill_id_still_forces_the_requirement(): void
    {
        self::assertTrue($this->policy()->requirementApplies(
            'here is the data you asked for',
            [],
            ['skill_id' => 'adapt_theme_style', 'runtime_scope' => 'skill'],
        ));
    }

    public function test_seeded_objective_does_not_force_final_tool_on_unmatched_message(): void
    {
        // Objective seeded (e.g. from preamble trigger noise) but the actual
        // message has nothing to do with the skill and no payload was collected.
        $state = ['task_frame' => ['active_objective' => 'adapt_theme_style', 'status' => 'working']];

        self::assertSame([], $this->policy()->requiredTools(
            'move the pricing section up one position',
            ['runtime_scope' => 'skill'],
            $state,
        ));
    }

    public function test_seeded_objective_forces_final_tool_when_message_matches(): void
    {
        $state = ['task_frame' => ['active_objective' => 'adapt_theme_style', 'status' => 'working']];

        self::assertSame(['set_brand_tokens'], $this->policy()->requiredTools(
            'use a dark theme for the whole site',
            ['runtime_scope' => 'skill'],
            $state,
        ));
    }

    public function test_successful_result_from_the_skills_own_tool_counts_as_engagement(): void
    {
        // The skill's own lookup succeeded on a prior step — this is a genuine
        // task, so the data-only follow-up keeps the final tool required.
        $state = ['task_frame' => ['active_objective' => 'adapt_theme_style', 'status' => 'working'],
            'tool_results' => [[
                'tool' => 'set_brand_tokens',
                'params' => [],
                'result' => ['success' => false],
            ], [
                'tool' => 'set_brand_tokens',
                'params' => [],
                'result' => ['success' => true],
            ]],
        ];

        self::assertSame(['set_brand_tokens'], $this->policy()->requiredTools(
            'looks good',
            ['runtime_scope' => 'skill'],
            $state,
        ));
    }

    public function test_unrelated_tool_activity_does_not_count_as_engagement(): void
    {
        // A DIFFERENT tool succeeded (e.g. a reorder) while the objective was
        // noise-seeded — that must not force this skill's final tool.
        $state = ['task_frame' => ['active_objective' => 'adapt_theme_style', 'status' => 'working'],
            'tool_results' => [[
                'tool' => 'reorder_remove_sections',
                'params' => [],
                'result' => ['success' => true],
            ]],
        ];

        self::assertSame([], $this->policy()->requiredTools(
            'move the pricing section up one position',
            ['runtime_scope' => 'skill'],
            $state,
        ));
    }

    public function test_engaged_multi_turn_task_still_forces_final_tool(): void
    {
        // Turn 2 of a genuine skill task: the payload collected on turn 1 is
        // in the frame, so the data-only reply keeps the final tool required.
        $state = ['task_frame' => [
            'active_objective' => 'adapt_theme_style',
            'status' => 'working',
            'current_payload' => ['primary_color' => '#111111'],
        ]];

        self::assertSame(['set_brand_tokens'], $this->policy()->requiredTools(
            'yes use that one',
            ['runtime_scope' => 'skill'],
            $state,
        ));
    }
    public function test_a_message_for_a_different_skill_does_not_force_the_active_tasks_final_tool(): void
    {
        $invoice = new AgentSkillDefinition(id: 'invoice_create', name: 'Create Invoice', description: 'Invoices', triggers: ['create invoice'], tools: ['create_invoice'], metadata: ['final_tool' => 'create_invoice']);
        $leads = new AgentSkillDefinition(id: 'lead_lookup', name: 'Leads', description: 'Leads', triggers: ['leads'], tools: ['data_query']);
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([$invoice, $leads]);
        $policy = new AiNativeFinalToolPolicy($registry, new AiNativeSkillMatcher($registry), new AiNativeConfirmationIntent(app(IntentSignalService::class)));

        // An invoice draft is open (its confirmation was just abandoned).
        $state = ['task_frame' => ['active_objective' => 'invoice_create', 'status' => 'working', 'current_payload' => ['customer_name' => 'Acme']]];

        self::assertFalse($policy->requirementApplies('How many leads do I have?', $state, []), 'A question for another skill must be answered, not forced into create_invoice.');
        self::assertTrue($policy->requirementApplies('create invoice for Acme with 2 chairs', $state, []));
    }
}
