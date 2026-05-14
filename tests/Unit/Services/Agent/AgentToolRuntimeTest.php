<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentToolRuntimeTest extends UnitTestCase
{
    public function test_default_tool_registry_registers_run_sub_agent_tool(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertTrue($registry->has('run_sub_agent'));
        $this->assertInstanceOf(RunSubAgentTool::class, $registry->get('run_sub_agent'));
        $this->assertArrayHasKey('target', $registry->get('run_sub_agent')->getParameters());
    }

    public function test_run_sub_agent_tool_requires_target(): void
    {
        $result = (new RunSubAgentTool())->execute([], new UnifiedActionContext('tool-runtime-missing-target', 1));

        $this->assertFalse($result->success);
        $this->assertTrue($result->requiresUserInput());
        $this->assertSame('A target is required to run a sub-agent.', $result->message);
        $this->assertSame(['target'], $result->metadata['required_inputs']);
    }

    public function test_action_execution_service_runs_registered_run_sub_agent_tool(): void
    {
        $context = new UnifiedActionContext('tool-runtime-sub-agent', 7);
        $goalAgent = Mockery::mock(GoalAgentService::class);
        $goalAgent->shouldReceive('execute')
            ->once()
            ->withArgs(function (string $target, UnifiedActionContext $receivedContext, array $options): bool {
                return $target === 'Summarize validation status'
                    && $receivedContext->sessionId === 'tool-runtime-sub-agent'
                    && ($options['agent_goal'] ?? false) === true
                    && ($options['sub_agents'][0] ?? null) === 'general'
                    && ($options['stop_on_failure'] ?? null) === true;
            })
            ->andReturn(AgentResponse::success(
                message: 'Target completed.',
                data: ['results' => [['agent_id' => 'general', 'success' => true]]],
                context: $context
            ));

        $this->app->instance(GoalAgentService::class, $goalAgent);

        $response = $this->toolExecutionService()->executeUseTool(
            'run_sub_agent',
            'summarize',
            $context,
            [
                'model_configs' => [],
                'tool_params' => [
                    'target' => 'Summarize validation status',
                    'sub_agents' => ['general'],
                    'stop_on_failure' => true,
                ],
            ],
            function () {
                $this->fail('Fallback RAG should not be called for registered run_sub_agent tool.');
            }
        );

        $this->assertTrue($response->success);
        $this->assertSame('Target completed.', $response->message);
        $this->assertSame('run_sub_agent', $response->strategy);
        $this->assertSame('run_sub_agent', $response->metadata['agent_strategy']);
        $this->assertSame('run_sub_agent', $response->metadata['tool_name']);
    }

    public function test_unknown_tool_falls_back_to_rag_callback(): void
    {
        $context = new UnifiedActionContext('tool-runtime-fallback', 9);
        $called = false;

        $response = $this->toolExecutionService()->executeUseTool(
            'missing_tool',
            'find launch notes',
            $context,
            ['model_configs' => []],
            function (string $message, UnifiedActionContext $receivedContext, array $options) use (&$called): AgentResponse {
                $called = true;

                $this->assertSame('find launch notes', $message);
                $this->assertSame('tool-runtime-fallback', $receivedContext->sessionId);
                $this->assertSame([], $options['model_configs']);

                return AgentResponse::conversational('RAG fallback used.', $receivedContext);
            }
        );

        $this->assertTrue($called);
        $this->assertTrue($response->success);
        $this->assertSame('RAG fallback used.', $response->message);
    }

    private function toolExecutionService(): AgentActionExecutionService
    {
        return new AgentActionExecutionService(
            $this->createMock(AutonomousCollectorRegistry::class),
            $this->createMock(AutonomousCollectorDiscoveryService::class),
            $this->createMock(AutonomousCollectorHandler::class),
            new SelectedEntityContextService(),
            null,
            null,
            app(ToolRegistry::class)
        );
    }
}
