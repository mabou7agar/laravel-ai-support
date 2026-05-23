<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseSuggestionService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentResponseSuggestionServiceTest extends UnitTestCase
{
    public function test_suggests_related_actions_skills_and_tools_from_registered_capabilities(): void
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->once()->andReturn([
            new AgentSkillDefinition(
                id: 'invoice_creator',
                name: 'Invoice creator',
                description: 'Create invoices from customer and product data.',
                triggers: ['invoice', 'bill customer'],
                tools: ['prepare_invoice'],
                actions: ['create_invoice']
            ),
        ]);

        $tools = new ToolRegistry();
        $tools->register('send_email_reply', new SuggestionToolStub());

        $actions = Mockery::mock(ActionFlowHandler::class);
        $actions->shouldReceive('catalog')->once()->andReturn([
            'success' => true,
            'actions' => [[
                'id' => 'create_invoice',
                'label' => 'Create invoice',
                'description' => 'Create an invoice for a customer from provided line items.',
                'operation' => 'create',
                'required' => ['customer_id', 'items'],
                'parameters' => [],
                'confirmation_required' => true,
            ]],
        ]);
        $actions->shouldReceive('suggest')->once()->andReturn([
            'success' => true,
            'suggestions' => [[
                'action_id' => 'send_reply',
                'label' => 'Send reply',
                'description' => 'Send a reply to the current email.',
            ]],
        ]);

        $suggestions = (new AgentResponseSuggestionService($skills, $tools, $actions))->suggest(
            message: 'Can you create an invoice and reply to this email?',
            response: 'I found customer and line item data.',
            metadata: [],
            context: new UnifiedActionContext('suggestions', 7),
            options: ['response_suggestions' => true, 'response_suggestion_limit' => 10]
        );

        $ids = array_column($suggestions, 'id');

        $this->assertContains('create_invoice', $ids);
        $this->assertContains('invoice_creator', $ids);
        $this->assertContains('send_email_reply', $ids);
        $this->assertContains('send_reply', $ids);
    }

    public function test_suggestions_ignore_noisy_raw_metadata_and_internal_tools(): void
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->once()->andReturn([]);

        $tools = new ToolRegistry();
        $tools->register('run_skill', new InternalSuggestionToolStub('run_skill', 'Run a matched skill.'));
        $tools->register('send_email_reply', new SuggestionToolStub());

        $actions = Mockery::mock(ActionFlowHandler::class);
        $actions->shouldReceive('catalog')->once()->andReturn(['actions' => []]);
        $actions->shouldReceive('suggest')->once()->andReturn(['suggestions' => []]);

        $suggestions = (new AgentResponseSuggestionService($skills, $tools, $actions))->suggest(
            message: 'Thanks, that answers my question.',
            response: 'You are welcome.',
            metadata: [
                'debug_payload' => [
                    'tool_descriptions' => [
                        'Run a matched skill.',
                        'Send a reply to the current email conversation.',
                    ],
                ],
            ],
            context: new UnifiedActionContext('suggestions-noise', 7),
            options: ['response_suggestions' => true, 'response_suggestion_limit' => 10]
        );

        $this->assertSame([], $suggestions);
    }
}

class SuggestionToolStub extends AgentTool
{
    public function getName(): string
    {
        return 'send_email_reply';
    }

    public function getDescription(): string
    {
        return 'Send a reply to the current email conversation.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}

class InternalSuggestionToolStub extends AgentTool
{
    public function __construct(private readonly string $name, private readonly string $description)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
