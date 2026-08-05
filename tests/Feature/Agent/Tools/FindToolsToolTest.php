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
    /** @param array<int, string> $aliases */
    private function tool(string $name, string $description, array $aliases = []): AgentTool
    {
        return new class($name, $description, $aliases) extends AgentTool {
            public function __construct(
                private string $n,
                private string $d,
                private array $aliases,
            )
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

            public function getDiscoveryAliases(): array
            {
                return $this->aliases;
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
        $result = $this->registry()->get('find_tools')->execute(['query' => 'find_customer'], new UnifiedActionContext('s'));

        $this->assertSame(1, $result->data['found'] ?? null);
        $this->assertSame('find_customer', $result->data['tools'][0]['name'] ?? null);
    }

    public function test_empty_query_fails(): void
    {
        $result = $this->registry()->get('find_tools')->execute(['query' => ''], new UnifiedActionContext('s'));

        $this->assertFalse($result->success);
    }

    public function test_discovery_never_returns_a_tool_outside_the_turn_allowlist(): void
    {
        $context = new UnifiedActionContext('scoped-tools');
        $context->requestOptions = [
            'tool_selection' => [
                'exposed_tools' => ['find_customer'],
            ],
        ];

        $result = $this->registry()->get('find_tools')->execute(
            ['query' => 'create_invoice'],
            $context,
        );

        $this->assertTrue($result->success);
        $this->assertSame(0, $result->data['found'] ?? null);
        $this->assertSame([], $result->data['tools'] ?? null);
    }

    public function test_unicode_aliases_support_arabic_and_code_switched_discovery(): void
    {
        $registry = $this->registry();
        $registry->register(
            'create_invoice',
            $this->tool(
                'create_invoice',
                'Create a draft invoice.',
                ['إنشاء فاتورة', 'اعمل invoice'],
            ),
        );

        $arabic = $registry->get('find_tools')->execute(
            ['query' => 'إنشاء فاتورة'],
            new UnifiedActionContext('arabic-tools'),
        );
        $mixed = $registry->get('find_tools')->execute(
            ['query' => 'اعمل invoice'],
            new UnifiedActionContext('mixed-tools'),
        );

        $this->assertSame('create_invoice', $arabic->data['tools'][0]['name'] ?? null);
        $this->assertSame('create_invoice', $mixed->data['tools'][0]['name'] ?? null);
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

    public function test_per_request_can_disable_find_tools_for_a_closed_roster(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.disclosure', 'progressive');

        $builder = new AiNativePromptBuilder($this->registry(), app(AgentSkillRegistry::class));
        $method = new \ReflectionMethod($builder, 'toolDocuments');
        $method->setAccessible(true);

        $docs = collect($method->invoke($builder, 'create an invoice', [], [
            'tool_selection' => [
                'disclosure' => 'progressive',
                'exposed_tools' => ['create_invoice'],
                'disclosure_full_tools' => ['create_invoice'],
                'find_tools_enabled' => false,
            ],
        ]))->keyBy('name');

        $this->assertArrayHasKey('create_invoice', $docs);
        $this->assertArrayHasKey('parameters', $docs['create_invoice']);
        $this->assertArrayNotHasKey('find_tools', $docs);
    }

    public function test_hybrid_disclosure_keeps_hot_core_full_and_defers_the_rest(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'all');
        config()->set('ai-agent.ai_native.tool_selection.disclosure', 'progressive');
        // Hot core: this tool keeps its full schema so the planner calls it directly.
        config()->set('ai-agent.ai_native.tool_selection.disclosure_full_tools', ['create_invoice']);

        $builder = new AiNativePromptBuilder($this->registry(), app(AgentSkillRegistry::class));
        $method = new \ReflectionMethod($builder, 'toolDocuments');
        $method->setAccessible(true);

        $docs = collect($method->invoke($builder, 'create an invoice', [], []))->keyBy('name');

        // Hot-core tool is FULL (no find_tools round-trip on the hot path).
        $this->assertArrayHasKey('parameters', $docs['create_invoice']);
        // A non-core tool stays name + summary only.
        $this->assertArrayNotHasKey('parameters', $docs['translate_text']);
        $this->assertArrayHasKey('description', $docs['translate_text']);
        // find_tools is always full so the deferred tail can be loaded on demand.
        $this->assertArrayHasKey('parameters', $docs['find_tools']);
    }

    public function test_per_request_disclosure_full_tools_overrides_config(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'all');
        // Global config is FULL disclosure with an empty hot core; the per-request
        // options turn on progressive AND supply the hot core — proving one agent can
        // opt in without changing the global default (how the theme builder wires it).
        config()->set('ai-agent.ai_native.tool_selection.disclosure', 'full');
        config()->set('ai-agent.ai_native.tool_selection.disclosure_full_tools', []);

        $builder = new AiNativePromptBuilder($this->registry(), app(AgentSkillRegistry::class));
        $method = new \ReflectionMethod($builder, 'toolDocuments');
        $method->setAccessible(true);

        $options = ['tool_selection' => ['disclosure' => 'progressive', 'disclosure_full_tools' => ['find_customer']]];
        $docs = collect($method->invoke($builder, 'look up a customer', [], $options))->keyBy('name');

        // The per-request hot core is full; everything else defers; find_tools stays full.
        $this->assertArrayHasKey('parameters', $docs['find_customer']);
        $this->assertArrayNotHasKey('parameters', $docs['create_invoice']);
        $this->assertArrayHasKey('parameters', $docs['find_tools']);
    }

    public function test_deferred_summary_is_truncated_but_find_tools_keeps_full_text(): void
    {
        config()->set('ai-agent.ai_native.tool_selection.strategy', 'all');
        config()->set('ai-agent.ai_native.tool_selection.disclosure', 'progressive');
        config()->set('ai-agent.ai_native.tool_selection.summary_max_chars', 180);

        // A realistic multi-paragraph tool description (the kind that bloats the prompt).
        $long = trim('Generate a fully custom HTML section as one opaque block. '
            . str_repeat('Extensive guidance about tokens, layout, direction, and design quality follows here. ', 8));

        $registry = $this->registry();
        $registry->register('generate_view', $this->tool('generate_view', $long));

        $builder = new AiNativePromptBuilder($registry, app(AgentSkillRegistry::class));
        $method = new \ReflectionMethod($builder, 'toolDocuments');
        $method->setAccessible(true);

        // Deferred: the listed summary is just the first sentence, not the whole essay.
        $docs = collect($method->invoke($builder, 'build a custom section', [], []))->keyBy('name');
        $this->assertSame('Generate a fully custom HTML section as one opaque block.', $docs['generate_view']['description']);
        $this->assertArrayNotHasKey('parameters', $docs['generate_view']);

        // find_tools still returns the FULL description + parameter schema — nothing lost.
        $found = $registry->get('find_tools')->execute(['query' => 'generate_view', 'limit' => 1], new UnifiedActionContext('s'));
        $this->assertSame($long, trim((string) $found->data['tools'][0]['description']));
        $this->assertArrayHasKey('parameters', $found->data['tools'][0]);

        // summary_max_chars = 0 (per request) disables truncation — full description listed.
        $docsFull = collect($method->invoke($builder, 'build a custom section', [], ['tool_selection' => ['summary_max_chars' => 0]]))->keyBy('name');
        $this->assertSame($long, $docsFull['generate_view']['description']);
    }
}
