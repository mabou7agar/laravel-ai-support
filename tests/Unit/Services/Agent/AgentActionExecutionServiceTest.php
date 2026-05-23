<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentActionExecutionServiceToolConfigStub
{
    public static function getName(): string
    {
        return 'stub_tools';
    }

    public static function getTools(): array
    {
        return [
            'prepare_stub_action' => [
                'parameters' => [],
                'handler' => static fn (): array => [
                    'success' => false,
                    'message' => 'I need the customer and due date before I can prepare this.',
                    'needs_user_input' => true,
                    'missing_fields' => ['customer_id', 'due_date'],
                ],
            ],
            'echo_stub_action' => [
                'parameters' => [
                    'payload' => ['type' => 'array', 'required' => true],
                ],
                'handler' => static fn (array $params): array => [
                    'success' => true,
                    'message' => 'Tool parameters received.',
                    'data' => $params,
                ],
            ],
            'context_stub_action' => [
                'parameters' => [],
                'handler' => static fn (array $params, UnifiedActionContext $context): array => [
                    'success' => true,
                    'message' => 'Context received.',
                    'data' => [
                        'session_id' => $context->sessionId,
                        'user_id' => $context->userId,
                    ],
                    'metadata' => [
                        'agent_strategy' => 'context_tool_strategy',
                    ],
                ],
            ],
        ];
    }
}

class AgentActionExecutionRegistryToolStub extends AgentTool
{
    public function getName(): string
    {
        return 'registry_echo';
    }

    public function getDescription(): string
    {
        return 'Echoes registry tool parameters.';
    }

    public function getParameters(): array
    {
        return [
            'value' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): \LaravelAIEngine\DTOs\ActionResult
    {
        return \LaravelAIEngine\DTOs\ActionResult::success(
            message: 'Registry tool executed.',
            data: [
                'value' => $parameters['value'],
                'session_id' => $context->sessionId,
            ],
            metadata: ['agent_strategy' => 'registry_tool_strategy']
        );
    }
}

class AgentActionExecutionServiceTest extends UnitTestCase
{
    public function test_execute_use_tool_preserves_needs_user_input_results(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('tool-session', 1);

        $response = $service->executeUseTool(
            'prepare_stub_action',
            'create an invoice',
            $context,
            ['model_configs' => [AgentActionExecutionServiceToolConfigStub::class]],
            function () {
                $this->fail('Fallback RAG should not be called for configured tool');
            }
        );

        $this->assertTrue($response->success);
        $this->assertTrue($response->needsUserInput);
        $this->assertFalse($response->isComplete);
        $this->assertSame('I need the customer and due date before I can prepare this.', $response->message);
        $this->assertSame(['customer_id', 'due_date'], $response->requiredInputs);
    }

    public function test_execute_use_tool_prefers_router_params_over_message_extraction(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('tool-param-session', 1);

        $response = $service->executeUseTool(
            'echo_stub_action',
            'create an invoice',
            $context,
            [
                'model_configs' => [AgentActionExecutionServiceToolConfigStub::class],
                'tool_params' => ['payload' => ['customer_id' => 10]],
            ],
            function () {
                $this->fail('Fallback RAG should not be called for configured tool');
            }
        );

        $this->assertTrue($response->success);
        $this->assertSame('Tool parameters received.', $response->message);
        $this->assertSame(['customer_id' => 10], $response->data['data']['payload']);
    }

    public function test_execute_use_tool_passes_context_to_model_config_handler(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('context-tool-session', 42);

        $response = $service->executeUseTool(
            'context_stub_action',
            'run context tool',
            $context,
            ['model_configs' => [AgentActionExecutionServiceToolConfigStub::class]],
            function () {
                $this->fail('Fallback RAG should not be called for configured tool');
            }
        );

        $this->assertTrue($response->success);
        $this->assertSame('Context received.', $response->message);
        $this->assertSame('context-tool-session', $response->data['data']['session_id']);
        $this->assertSame(42, $response->data['data']['user_id']);
        $this->assertSame('context_tool_strategy', $response->strategy);
        $this->assertSame('context_tool_strategy', $response->metadata['agent_strategy']);
        $this->assertSame('context_stub_action', $response->metadata['tool_name']);
    }

    public function test_execute_use_tool_runs_agent_tool_registry_when_model_config_does_not_match(): void
    {
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register('registry_echo', new AgentActionExecutionRegistryToolStub());

        $service = new AgentActionExecutionService(
            new SelectedEntityContextService(),
            null,
            null,
            $toolRegistry
        );

        $context = new UnifiedActionContext('registry-tool-session', 99);

        $response = $service->executeUseTool(
            'registry_echo',
            'run registry tool',
            $context,
            ['model_configs' => [], 'tool_params' => ['value' => 'abc']],
            function () {
                $this->fail('Fallback RAG should not be called for registered AgentTool');
            }
        );

        $this->assertTrue($response->success);
        $this->assertSame('Registry tool executed.', $response->message);
        $this->assertSame('abc', $response->data['value']);
        $this->assertSame('registry-tool-session', $response->data['session_id']);
        $this->assertSame('registry_tool_strategy', $response->strategy);
        $this->assertSame('registry_echo', $response->metadata['tool_name']);
    }

}
