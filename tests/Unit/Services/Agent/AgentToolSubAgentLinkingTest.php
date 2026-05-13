<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\SubAgentResult;
use LaravelAIEngine\DTOs\SubAgentTask;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentOrchestrationInspector;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentToolSubAgentLinkingTest extends UnitTestCase
{
    public function test_sub_agent_without_handler_can_execute_declared_tools(): void
    {
        $tools = new ToolRegistry();
        $tools->register('echo', new LinkedEchoTool());
        $this->app->instance(ToolRegistry::class, $tools);

        $subAgents = new SubAgentRegistry($this->app, [
            'research' => [
                'name' => 'Research',
                'tools' => ['echo'],
            ],
        ]);
        $this->app->instance(SubAgentRegistry::class, $subAgents);

        $handler = $subAgents->resolveHandler('research');

        $this->assertInstanceOf(ToolCallingSubAgentHandler::class, $handler);

        $result = $handler->handle(
            new SubAgentTask(
                id: 'research_task',
                agentId: 'research',
                name: 'Research',
                objective: 'Find current facts',
                input: ['input' => 'facts']
            ),
            new UnifiedActionContext('linking-session', 1)
        );

        $this->assertTrue($result->success);
        $this->assertSame('Tool-backed sub-agent completed.', $result->message);
        $this->assertSame('facts', $result->data['tool_results']['echo']['data']['input']);
    }

    public function test_run_sub_agent_tool_delegates_to_goal_agent(): void
    {
        $registry = new SubAgentRegistry($this->app, [
            'writer' => [
                'handler' => fn (SubAgentTask $task, UnifiedActionContext $context, array $previousResults, array $options) => SubAgentResult::success(
                    $task->id,
                    $task->agentId,
                    'Writer done',
                    ['target' => $task->objective]
                ),
            ],
        ]);

        $this->app->instance(GoalAgentService::class, new GoalAgentService(
            new SubAgentPlanner($registry),
            new SubAgentExecutionService($registry)
        ));

        $result = (new RunSubAgentTool())->execute([
            'target' => 'Write a release note',
            'sub_agents' => ['writer'],
        ], new UnifiedActionContext('tool-session', 5));

        $this->assertTrue($result->success);
        $this->assertSame('run_sub_agent', $result->metadata['agent_strategy']);
        $this->assertArrayHasKey('results', $result->data['data']);
    }

    public function test_orchestration_inspector_reports_missing_links_and_complexity(): void
    {
        $tools = new ToolRegistry();
        $tools->register('echo', new LinkedEchoTool());

        $subAgents = new SubAgentRegistry($this->app, [
            'research' => [
                'name' => 'Research',
                'tools' => ['echo', 'missing_tool'],
                'sub_agents' => ['writer'],
            ],
        ]);

        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')
            ->once()
            ->with([], true)
            ->andReturn([
                new AgentSkillDefinition(
                    id: 'compose',
                    name: 'Compose',
                    description: 'Compose content',
                    tools: ['echo'],
                    metadata: ['sub_agents' => ['missing_agent']]
                ),
            ]);

        $report = (new AgentOrchestrationInspector($subAgents, $tools, $skills))->inspect([
            'max_complexity' => 1,
        ]);

        $this->assertFalse($report->passed());
        $this->assertContains('missing_tool', array_column($report->issues, 'code'));
        $this->assertContains('missing_sub_agent', array_column($report->issues, 'code'));
        $this->assertContains('orchestration_complexity_high', array_column($report->issues, 'code'));
        $this->assertSame(1, $report->metrics['tool_count']);
    }
}

class LinkedEchoTool extends AgentTool
{
    public function getName(): string
    {
        return 'echo';
    }

    public function getDescription(): string
    {
        return 'Echo input for orchestration tests.';
    }

    public function getParameters(): array
    {
        return [
            'input' => [
                'type' => 'string',
                'required' => true,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('Echoed', $parameters);
    }
}
