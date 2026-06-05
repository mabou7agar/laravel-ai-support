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
