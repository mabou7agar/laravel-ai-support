<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentSkillMatcherTest extends UnitTestCase
{
    public function test_it_matches_enabled_skill_triggers(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')
            ->once()
            ->with([], false)
            ->andReturn([
                new AgentSkillDefinition(
                    id: 'create_invoice',
                    name: 'Create Invoice',
                    description: 'Create invoices.',
                    triggers: ['create invoice'],
                    actions: ['invoices.create'],
                    enabled: true
                ),
            ]);

        $match = (new AgentSkillMatcher($registry))->match('please create invoice for ACME');

        $this->assertNotNull($match);
        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame('create invoice', $match['trigger']);
        $this->assertGreaterThan(0, $match['score']);
    }

    public function test_planner_compiles_action_skill_to_update_action_draft(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            actions: ['invoices.create']
        );

        $plan = (new AgentSkillExecutionPlanner())->plan(
            $skill,
            'create invoice',
            new UnifiedActionContext('skill-plan-test'),
            ['score' => 100, 'trigger' => 'create invoice']
        );

        $this->assertSame('use_tool', $plan['action']);
        $this->assertSame('update_action_draft', $plan['resource_name']);
        $this->assertSame('invoices.create', $plan['params']['action_id']);
        $this->assertTrue($plan['params']['reset']);
        $this->assertSame('skill_match', $plan['decision_source']);
    }

    public function test_planner_prefers_skill_collector_over_supporting_tools(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['find_customer', 'create_customer'],
            metadata: ['collector' => 'test_invoice_creator']
        );

        $plan = (new AgentSkillExecutionPlanner())->plan(
            $skill,
            'create invoice',
            new UnifiedActionContext('skill-collector-plan-test'),
            ['score' => 100, 'trigger' => 'create invoice']
        );

        $this->assertSame('start_collector', $plan['action']);
        $this->assertSame('test_invoice_creator', $plan['resource_name']);
        $this->assertSame('skill_match', $plan['decision_source']);
    }
}
