<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\Contracts\ConversationMemory;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSkillTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RunSkillToolTest extends UnitTestCase
{
    public function test_it_asks_user_when_planner_requests_missing_input(): void
    {
        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['echo_tool'],
            metadata: ['planner' => 'skill_tool_auto', 'target_json' => ['customer_name' => null]]
        );

        $tool = $this->makeTool($skill, [
            'action' => 'ask_user',
            'message' => 'What customer name should I use?',
            'payload_patch' => ['items' => [['product_name' => 'Macbook Pro', 'quantity' => 2]]],
        ]);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice for 2 Macbook Pro',
            'reset' => true,
        ], new UnifiedActionContext('run-skill-ask-test'));

        $this->assertFalse($result->success);
        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('What customer name should I use?', $result->message);
        $this->assertSame('create_invoice', $result->data['skill_id']);
        $this->assertSame('Macbook Pro', $result->data['payload']['items'][0]['product_name']);
    }

    public function test_it_runs_declared_tool_and_keeps_runtime_state(): void
    {
        config()->set('ai-agent.skill_tool_planner.max_steps', 1);

        $skill = new AgentSkillDefinition(
            id: 'create_invoice',
            name: 'Create Invoice',
            description: 'Create invoices.',
            tools: ['echo_tool'],
            metadata: ['planner' => 'skill_tool_auto', 'target_json' => ['customer_name' => null]]
        );

        $tool = $this->makeTool($skill, [
            'action' => 'run_tool',
            'message' => 'Looking up the customer.',
            'payload_patch' => ['customer_name' => 'Acme'],
            'tool_name' => 'echo_tool',
            'tool_params' => ['value' => 'Acme'],
        ]);

        $result = $tool->execute([
            'skill_id' => 'create_invoice',
            'message' => 'create invoice for Acme',
            'reset' => true,
        ], new UnifiedActionContext('run-skill-tool-test'));

        $this->assertTrue($result->success);
        $this->assertSame('Skill draft updated.', $result->message);
        $this->assertSame('collecting', $result->data['status']);
        $this->assertSame('Acme', $result->data['payload']['customer_name']);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function makeTool(AgentSkillDefinition $skill, array $plan): RunSkillTool
    {
        config()->set('ai-agent.skill_tool_planner.extract_before_plan', false);

        $registry = Mockery::mock(AgentSkillRegistry::class);
        $registry->shouldReceive('skills')->andReturn([$skill]);

        $tools = new ToolRegistry();
        $tools->register('echo_tool', new class extends AgentTool {
            public function getName(): string
            {
                return 'echo_tool';
            }

            public function getDescription(): string
            {
                return 'Echo a value.';
            }

            public function getParameters(): array
            {
                return ['value' => ['type' => 'string', 'required' => true]];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', ['value' => $parameters['value'] ?? null]);
            }
        });

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(json_encode($plan), 'openai', 'gpt-4o-mini'));

        return new RunSkillTool(
            $registry,
            app(ConversationMemory::class),
            $ai,
            $tools
        );
    }
}
