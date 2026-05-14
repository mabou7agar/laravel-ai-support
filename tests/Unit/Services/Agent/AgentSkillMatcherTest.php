<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\AIEngineService;
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

    public function test_planner_compiles_skill_tool_auto_to_run_skill(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['find_customer', 'create_invoice'],
            metadata: [
                'planner' => 'skill_tool_auto',
                'target_json' => ['customer_name' => null, 'items' => []],
                'final_tool' => 'create_invoice',
            ]
        );

        $plan = (new AgentSkillExecutionPlanner())->plan(
            $skill,
            'create invoice',
            new UnifiedActionContext('skill-tool-plan-test'),
            ['score' => 100, 'trigger' => 'create invoice']
        );

        $this->assertSame('use_tool', $plan['action']);
        $this->assertSame('run_skill', $plan['resource_name']);
        $this->assertSame('create_invoice', $plan['params']['skill_id']);
        $this->assertSame('create invoice', $plan['params']['message']);
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

    public function test_it_matches_skill_by_ai_intent_from_conversation_without_trigger_words(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create a sales invoice by collecting customer details and line items.',
            triggers: ['create invoice'],
            requiredData: ['customer', 'items'],
            actions: ['invoices.create'],
            capabilities: ['invoice.create'],
            metadata: [
                'target_json' => [
                    'customer_name' => null,
                    'items' => [],
                ],
            ],
            enabled: true
        );

        $registry->shouldReceive('skills')
            ->twice()
            ->with([], false)
            ->andReturn([$skill]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->withArgs(fn ($request): bool => str_contains($request->getPrompt(), 'AGENT_SKILL_INTENT_MATCHER')
                && str_contains($request->getPrompt(), 'prepare the paperwork from those details')
                && str_contains($request->getPrompt(), 'ACME asked for 2 laptops'))
            ->andReturn(AIResponse::success(json_encode([
                'skill_id' => 'create_invoice',
                'confidence' => 91,
                'reason' => 'The user wants to turn the prior order conversation into an invoice.',
            ]), 'openai', 'gpt-4o-mini'));

        $context = new UnifiedActionContext('skill-intent-test', null, [
            ['role' => 'user', 'content' => 'ACME asked for 2 laptops and 1 phone.'],
            ['role' => 'assistant', 'content' => 'I can help prepare that order.'],
        ]);

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent('prepare the paperwork from those details', $context);

        $this->assertNotNull($match);
        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame('ai_intent', $match['trigger']);
        $this->assertSame(91, $match['score']);
    }

    public function test_it_matches_continuation_request_from_recent_conversation_context(): void
    {
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            actions: ['invoices.create']
        );

        $registry->shouldReceive('skills')
            ->twice()
            ->with([], false)
            ->andReturn([$skill]);

        $context = new UnifiedActionContext('skill-context-test', null, [
            ['role' => 'user', 'content' => 'I need to create invoice for Sample Customer with 2 laptops.'],
            ['role' => 'assistant', 'content' => 'I can help with that.'],
        ]);

        $match = (new AgentSkillMatcher($registry))->matchIntent('do it', $context);

        $this->assertNotNull($match);
        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame('conversation_context:create invoice', $match['trigger']);
    }

    public function test_it_matches_skill_by_semantic_business_alias(): void
    {
        config()->set('ai-agent.skills.intent_aliases', [
            'invoice' => ['invoice', 'bill'],
        ]);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            actions: ['invoices.create'],
            capabilities: ['invoice.create']
        );

        $registry->shouldReceive('skills')
            ->twice()
            ->with([], false)
            ->andReturn([$skill]);

        $context = new UnifiedActionContext('skill-alias-test', null, [
            ['role' => 'user', 'content' => 'Sample Customer wants 2 Alpha Laptop and 3 Beta Phone.'],
        ]);

        $match = (new AgentSkillMatcher($registry))->matchIntent('bill him for those items', $context);

        $this->assertNotNull($match);
        $this->assertSame('create_invoice', $match['skill']->id);
        $this->assertSame('semantic_alias:invoice', $match['trigger']);
    }

    public function test_ai_intent_match_respects_confidence_threshold(): void
    {
        config()->set('ai-agent.skills.intent_matching.min_confidence', 80);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            triggers: ['create invoice'],
            actions: ['invoices.create']
        );

        $registry->shouldReceive('skills')
            ->twice()
            ->with([], false)
            ->andReturn([$skill]);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode([
                'skill_id' => 'create_invoice',
                'confidence' => 60,
                'reason' => 'Weak match.',
            ]), 'openai', 'gpt-4o-mini'));

        $match = (new AgentSkillMatcher($registry, $ai))->matchIntent(
            'maybe later',
            new UnifiedActionContext('skill-intent-low-confidence')
        );

        $this->assertNull($match);
    }
}
