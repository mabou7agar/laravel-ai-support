<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AiNativePromptBuilderTest extends UnitTestCase
{
    public function test_prompt_excludes_internal_runtime_tools_by_default(): void
    {
        $tools = new ToolRegistry();
        $tools->register('find_customer', $this->tool('find_customer'));
        $tools->register('run_skill', $this->tool('run_skill'));

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('Create invoice', new UnifiedActionContext('prompt-tools'), []);

        $this->assertStringContainsString('"name": "find_customer"', $prompt);
        $this->assertStringNotContainsString('"name": "run_skill"', $prompt);
    }

    public function test_prompt_includes_compact_context_snapshot(): void
    {
        $tools = new ToolRegistry();
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('confirm', new UnifiedActionContext('prompt-snapshot'), [
                'task_frame' => [
                    'status' => 'confirming',
                    'active_objective' => 'invoice',
                    'pending_tool' => [
                        'name' => 'create_invoice',
                        'summary' => ['Customer' => 'Ahmed'],
                    ],
                    'current_payload' => [
                        'customer_name' => 'Ahmed',
                        'items' => [
                            ['product_name' => 'Laptop', 'quantity' => 2],
                        ],
                    ],
                    'completed_writes' => [
                        ['tool' => 'create_customer', 'label' => 'Ahmed'],
                    ],
                ],
                'recent_outcomes' => [
                    [
                        'tool' => 'find_customer',
                        'outcome' => 'found',
                        'entity_type' => 'customer',
                        'entity_id' => 501,
                        'label' => 'Ahmed',
                        'visible_to_user' => false,
                    ],
                ],
            ]);

        $this->assertStringContainsString('Context snapshot JSON:', $prompt);
        $this->assertStringContainsString('Use the context snapshot to continue active tasks', $prompt);
        $this->assertStringContainsString('context_snapshot.current_payload', $prompt);
        $this->assertStringContainsString('before asking the user to identify the record again', $prompt);
        $this->assertStringContainsString('only shares background facts', $prompt);
        $this->assertStringContainsString('let suggestions expose possible next actions', $prompt);
        $this->assertStringContainsString('Only begin a write/action flow', $prompt);
        $this->assertStringContainsString('data.current_payload', $prompt);
        $this->assertStringContainsString('Laravel will pause for confirmation before executing it.', $prompt);
        $this->assertStringContainsString('"pending_confirmation"', $prompt);
        $this->assertStringContainsString('"current_payload"', $prompt);
        $this->assertStringContainsString('"quantity": 2', $prompt);
        $this->assertStringContainsString('"tool": "create_invoice"', $prompt);
        $this->assertStringContainsString('"Customer": "Ahmed"', $prompt);
        $this->assertStringContainsString('"already_completed"', $prompt);
    }

    public function test_prompt_sanitizes_internal_runtime_metadata_from_conversation_history(): void
    {
        $tools = new ToolRegistry();
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $context = new UnifiedActionContext('prompt-history');
        $context->conversationHistory = [
            [
                'role' => 'assistant',
                'content' => 'Please provide the invoice ID.',
                'metadata' => [
                    'ai_native' => [
                        'runtime_feedback' => [
                            ['reason' => 'final_without_required_final_tool'],
                        ],
                        'task_frame' => [
                            'current_payload' => ['customer_name' => 'Ahmed'],
                        ],
                    ],
                ],
                'timestamp' => '2026-05-20T08:00:00Z',
            ],
        ];

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('show the latest state', $context, []);

        $this->assertStringContainsString('"content": "Please provide the invoice ID."', $prompt);
        $this->assertStringContainsString('"timestamp": "2026-05-20T08:00:00Z"', $prompt);
        $this->assertStringNotContainsString('runtime_feedback', $prompt);
        $this->assertStringNotContainsString('final_without_required_final_tool', $prompt);
        $this->assertStringNotContainsString('"metadata"', $prompt);
    }

    public function test_prompt_omits_assistant_history_when_runtime_state_is_authoritative(): void
    {
        $tools = new ToolRegistry();
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $context = new UnifiedActionContext('prompt-authoritative-history');
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'last created invoices'],
            ['role' => 'assistant', 'content' => 'Please provide the invoice ID.'],
        ];

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('last created invoices', $context, [
                'task_frame' => [
                    'current_payload' => [
                        'customer_name' => 'Ahmed',
                    ],
                ],
            ]);

        $this->assertStringContainsString('"content": "last created invoices"', $prompt);
        $this->assertStringNotContainsString('Please provide the invoice ID.', $prompt);
        $this->assertStringContainsString('"current_payload"', $prompt);
    }

    public function test_prompt_includes_skill_prompt_and_relation_metadata(): void
    {
        $tools = new ToolRegistry();
        $tools->register('find_buyer', $this->tool('find_buyer'));

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([
            new AgentSkillDefinition(
                id: 'create_order',
                name: 'Create Order',
                description: 'Create orders.',
                tools: ['find_buyer'],
                prompt: 'Resolve the buyer before collecting order details.',
                metadata: [
                    'relations' => [
                        [
                            'name' => 'buyer',
                            'field' => 'buyer_id',
                            'lookup_tool' => 'find_buyer',
                        ],
                    ],
                ],
            ),
        ]);

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('create order for Noor', new UnifiedActionContext('prompt-skill-relations'), []);

        $this->assertStringContainsString('"prompt": "Resolve the buyer before collecting order details."', $prompt);
        $this->assertStringContainsString('"relations"', $prompt);
        $this->assertStringContainsString('"lookup_tool": "find_buyer"', $prompt);
    }

    public function test_prompt_guides_active_skills_to_prefer_declared_relation_tools_over_generic_helpers(): void
    {
        $tools = new ToolRegistry();
        $tools->register('search_options', $this->tool('search_options'));
        $tools->register('find_product', $this->tool('find_product'));
        $tools->register('create_invoice', $this->tool('create_invoice'));

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([
            new AgentSkillDefinition(
                id: 'create_invoice',
                name: 'Create Invoice',
                description: 'Create invoices.',
                tools: ['find_product', 'create_invoice'],
                metadata: [
                    'target_json' => ['items' => [['product_id' => null, 'product_name' => null]]],
                    'relations' => [
                        [
                            'name' => 'product',
                            'field' => 'items.*.product_id',
                            'lookup_tool' => 'find_product',
                        ],
                    ],
                    'final_tool' => 'create_invoice',
                ],
            ),
        ]);

        $prompt = (new AiNativePromptBuilder($tools, $skills))
            ->build('create invoice with 2 laptops', new UnifiedActionContext('prompt-skill-tool-priority'), []);

        $this->assertStringContainsString('For an active skill, prefer the tools listed in that skill', $prompt);
        $this->assertStringContainsString('Use generic field helper tools only when the user explicitly asks to validate, explain, or browse field options.', $prompt);
        $this->assertStringContainsString('Never ask the user whether you should run a non-confirming lookup/search/find tool.', $prompt);
        $this->assertStringContainsString('call that relation lookup_tool with the value', $prompt);
    }

    private function tool(string $name): AgentTool
    {
        return new class($name) extends AgentTool {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return $this->name;
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('ok');
            }
        };
    }
}
