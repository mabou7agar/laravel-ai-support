<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\FindToolsTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;

class FindToolsToolTest extends TestCase
{
    private function tool(string $name, string $description): AgentTool
    {
        return new class($name, $description) extends AgentTool {
            public function __construct(private string $n, private string $d)
            {
            }

            public function getName(): string
            {
                return $this->n;
            }

            public function getDescription(): string
            {
                return $this->d;
            }

            public function getParameters(): array
            {
                return ['value' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('ok');
            }
        };
    }

    private function registry(): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register('create_invoice', $this->tool('create_invoice', 'Create a draft invoice.'));
        $registry->register('find_customer', $this->tool('find_customer', 'Look up a customer by email.'));
        $registry->register('translate_text', $this->tool('translate_text', 'Translate text between languages.'));
        $registry->register('find_tools', new FindToolsTool($registry));

        return $registry;
    }

    public function test_returns_full_schemas_for_matching_tools(): void
    {
        $result = $this->registry()->get('find_tools')->execute(['query' => 'invoice'], new UnifiedActionContext('s'));

        $this->assertTrue($result->success);
        $names = array_column((array) ($result->data['tools'] ?? []), 'name');
        $this->assertContains('create_invoice', $names);
        $this->assertNotContains('translate_text', $names);
        // The point of find_tools: the result carries the FULL parameter schema.
        $this->assertArrayHasKey('parameters', $result->data['tools'][0]);
    }

    public function test_exact_tool_name_is_ranked_first(): void
    {
        $result = $this->registry()->get('find_tools')->execute(['query' => 'find_customer', 'limit' => 1], new UnifiedActionContext('s'));

        $this->assertSame('find_customer', $result->data['tools'][0]['name'] ?? null);
    }

    public function test_empty_query_fails(): void
    {
        $result = $this->registry()->get('find_tools')->execute(['query' => ''], new UnifiedActionContext('s'));

        $this->assertFalse($result->success);
    }

    public function test_progressive_disclosure_renders_tools_compactly_but_find_tools_full(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'all');
        config()->set('ai-agent.ai_native.tool_selection.disclosure', 'progressive');

        $builder = new AiNativePromptBuilder($this->registry(), app(AgentSkillRegistry::class));
        $method = new \ReflectionMethod($builder, 'toolDocuments');
        $method->setAccessible(true);

        $docs = collect($method->invoke($builder, 'create an invoice', [], []))->keyBy('name');

        // A regular tool is name + description only (no parameter schema).
        $this->assertArrayHasKey('create_invoice', $docs);
        $this->assertArrayNotHasKey('parameters', $docs['create_invoice']);
        $this->assertArrayHasKey('description', $docs['create_invoice']);

        // find_tools keeps its full schema so the planner knows how to call it.
        $this->assertArrayHasKey('find_tools', $docs);
        $this->assertArrayHasKey('parameters', $docs['find_tools']);
    }
}
