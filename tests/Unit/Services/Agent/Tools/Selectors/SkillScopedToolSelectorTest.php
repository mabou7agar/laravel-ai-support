<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Services\Agent\Tools\Selectors\AllToolSelector;
use LaravelAIEngine\Services\Agent\Tools\Selectors\SkillScopedToolSelector;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class SkillScopedToolSelectorTest extends UnitTestCase
{
    /**
     * @param array<int, string> $names
     * @return array<string, object>
     */
    private function tools(array $names): array
    {
        $tools = [];
        foreach ($names as $name) {
            $tools[$name] = new class($name) {
                public function __construct(private string $name)
                {
                }

                public function getName(): string
                {
                    return $this->name;
                }
            };
        }

        return $tools;
    }

    private function skill(string $id, array $tools, array $relations = [], string $final = ''): object
    {
        return (object) [
            'id' => $id,
            'tools' => $tools,
            'metadata' => array_filter([
                'final_tool' => $final,
                'relations' => $relations,
            ]),
        ];
    }

    public function test_all_selector_returns_every_tool(): void
    {
        $tools = $this->tools(['a', 'b', 'c']);
        $this->assertSame($tools, (new AllToolSelector())->select($tools, 'hi', [], []));
    }

    public function test_scopes_to_the_active_skill_tools_plus_core(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.always', ['search_knowledge', 'data_query']);

        $matcher = Mockery::mock(AiNativeSkillMatcher::class);
        $matcher->shouldReceive('selectedSkillIdForActiveTask')->andReturn('');
        $matcher->shouldReceive('matchedSkillId')->andReturn('invoice');

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([
            $this->skill('other', ['unrelated_tool']),
            $this->skill('invoice', ['find_customer'], [
                ['lookup_tool' => 'find_product', 'create_tool' => 'create_product'],
            ], 'create_invoice'),
        ]);

        $selector = new SkillScopedToolSelector($registry, $matcher);
        $tools = $this->tools([
            'find_customer', 'create_invoice', 'find_product', 'create_product',
            'send_email', 'unrelated_tool', 'search_knowledge', 'data_query',
        ]);

        $selected = array_keys($selector->select($tools, 'create an invoice', [], []));
        sort($selected);

        $this->assertSame([
            'create_invoice', 'create_product', 'data_query', 'find_customer',
            'find_product', 'search_knowledge',
        ], $selected);
        $this->assertNotContains('send_email', $selected);
        $this->assertNotContains('unrelated_tool', $selected);
    }

    public function test_an_open_task_does_not_hide_the_tools_an_unrelated_message_needs(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.always', ['data_query']);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([
            $this->skill('invoice', ['create_invoice']),
            $this->skill('leads', ['create_lead']),
        ]);
        $tools = $this->tools(['create_invoice', 'create_lead', 'show_customer', 'send_email', 'data_query']);

        $matcher = Mockery::mock(AiNativeSkillMatcher::class);
        $matcher->shouldReceive('selectedSkillIdForActiveTask')->andReturn('invoice');
        $matcher->shouldReceive('matchedSkillId')->with('find customer CUST-0001', [])->andReturn(null);
        $matcher->shouldReceive('matchedSkillId')->with('Acme', [])->andReturn(null);
        $matcher->shouldReceive('matchedSkillId')->with('add a lead', [])->andReturn('leads');
        $selector = new SkillScopedToolSelector($registry, $matcher);

        // Off-topic question with an invoice draft open: the task's tools stay, the customer tool is added.
        $selected = array_keys($selector->select($tools, 'find customer CUST-0001', [], []));
        sort($selected);
        $this->assertSame(['create_invoice', 'data_query', 'find_tools', 'show_customer'], $selected);

        // A plain reply to the task stays scoped to it.
        $selected = array_keys($selector->select($tools, 'Acme', [], []));
        sort($selected);
        $this->assertSame(['create_invoice', 'data_query'], $selected);

        // A message for another skill scopes to that skill.
        $selected = array_keys($selector->select($tools, 'add a lead', [], []));
        sort($selected);
        $this->assertSame(['create_lead', 'data_query'], $selected);
    }

    public function test_returns_all_tools_when_no_skill_is_active(): void
    {
        $matcher = Mockery::mock(AiNativeSkillMatcher::class);
        $matcher->shouldReceive('selectedSkillIdForActiveTask')->andReturn('');
        $matcher->shouldReceive('matchedSkillId')->andReturn(null);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([]);

        $selector = new SkillScopedToolSelector($registry, $matcher);
        $tools = $this->tools(['a', 'b', 'c']);

        $this->assertSame($tools, $selector->select($tools, 'just chatting', [], []));
    }

    public function test_large_registry_without_a_skill_exposes_a_ranked_subset_plus_find_tools(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.always', ['search_knowledge']);
        config()->set('ai-agent.ai_native.tool_selection.unscoped_limit', 5);

        $matcher = Mockery::mock(AiNativeSkillMatcher::class);
        $matcher->shouldReceive('selectedSkillIdForActiveTask')->andReturn('');
        $matcher->shouldReceive('matchedSkillId')->andReturn(null);
        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([]);

        $names = ['search_knowledge'];
        for ($i = 0; $i < 300; $i++) {
            $names[] = "find_widget_{$i}";
        }
        $names[] = 'find_vendor_bill';
        $names[] = 'show_vendor';
        $tools = $this->tools($names);

        $toolRegistry = new \LaravelAIEngine\Services\Agent\Tools\ToolRegistry();
        $findTools = new \LaravelAIEngine\Services\Agent\Tools\FindToolsTool($toolRegistry);
        $toolRegistry->register('find_tools', $findTools);

        $selector = new SkillScopedToolSelector($registry, $matcher, $toolRegistry);

        $selected = $selector->select($tools, 'how much do we owe each vendor?', [], []);
        $this->assertSame(['search_knowledge', 'find_vendor_bill', 'show_vendor', 'find_tools'], array_keys($selected));

        // A no-signal turn still gets a bounded slice, never all 303 tools.
        $greeting = $selector->select($tools, 'hi', [], []);
        $this->assertCount(6, $greeting);
        $this->assertArrayHasKey('find_tools', $greeting);

        // Small registries and the null opt-out keep the full set.
        config()->set('ai-agent.ai_native.tool_selection.unscoped_limit', null);
        $this->assertCount(303, $selector->select($tools, 'hi', [], []));
    }

    public function test_falls_back_to_all_when_scope_would_be_empty(): void
    {
        $matcher = Mockery::mock(AiNativeSkillMatcher::class);
        $matcher->shouldReceive('selectedSkillIdForActiveTask')->andReturn('');
        $matcher->shouldReceive('matchedSkillId')->andReturn('invoice');

        $registry = Mockery::mock(AgentSkillRegistry::class);
        // Skill declares a tool that is not registered, and no core matches -> empty scope.
        $registry->shouldReceive('skills')->andReturn([$this->skill('invoice', ['ghost_tool'])]);
        config()->set('ai-agent.ai_native.tool_selection.always', []);

        $selector = new SkillScopedToolSelector($registry, $matcher);
        $tools = $this->tools(['a', 'b']);

        $this->assertSame($tools, $selector->select($tools, 'create an invoice', [], []));
    }
}
