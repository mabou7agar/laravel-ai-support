<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
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
            'throwing_stub_action' => [
                'parameters' => [],
                'handler' => static function (): array {
                    throw new \RuntimeException('boom from handler');
                },
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

class AgentActionExecutionRegistryEntityToolStub extends AgentTool
{
    public function getName(): string
    {
        return 'registry_entity_action';
    }

    public function getDescription(): string
    {
        return 'Acts on the selected entity.';
    }

    public function getParameters(): array
    {
        return [
            'invoice_id' => ['type' => 'integer', 'required' => true],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): \LaravelAIEngine\DTOs\ActionResult
    {
        return \LaravelAIEngine\DTOs\ActionResult::success(
            message: 'Registry entity tool executed.',
            data: ['invoice_id' => $parameters['invoice_id'] ?? null],
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

    public function test_execute_use_tool_returns_failure_when_handler_throws(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('throwing-tool-session', 1);

        $ragInvoked = false;
        $response = $service->executeUseTool(
            'throwing_stub_action',
            'run throwing tool',
            $context,
            ['model_configs' => [AgentActionExecutionServiceToolConfigStub::class]],
            function () use (&$ragInvoked): AgentResponse {
                $ragInvoked = true;
                $this->fail('RAG fallback should not be called when a matched tool handler throws');
            }
        );

        // A tool that EXISTS but ERRORED must be reported as a failure, not as
        // "tool not registered" (which would redirect to the RAG fallback).
        $this->assertFalse($ragInvoked);
        $this->assertFalse($response->success);
        $this->assertTrue($response->isComplete);
        $this->assertStringContainsString('boom from handler', $response->message);
        $this->assertSame('throwing_stub_action', $response->metadata['tool_name'] ?? null);
        $this->assertSame('boom from handler', $response->metadata['tool_error'] ?? null);
    }

    public function test_execute_use_tool_plain_fallback_tags_tool_fallback_decision_source(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('plain-fallback-session', 1);

        $captured = null;
        $service->executeUseTool(
            'unknown_tool',
            'show me the latest record',
            $context,
            ['model_configs' => []],
            function (string $message, UnifiedActionContext $ctx, array $options) use (&$captured): AgentResponse {
                $captured = $options;

                return AgentResponse::conversational(message: 'rag fallback', context: $ctx);
            }
        );

        $this->assertNotNull($captured);
        $this->assertSame('tool_fallback', $captured['decision_source']);
        $this->assertSame('tool_fallback', $captured['decision_path']);

        // Diagnostics: a tool-not-found fallback must be observable, not silent.
        $this->assertTrue($captured['tool_not_found'] ?? false);
        $this->assertSame('unknown_tool', $captured['requested_tool'] ?? null);
        $this->assertIsArray($captured['available_tools'] ?? null);
    }

    public function test_execute_use_tool_structured_fallback_tags_tool_fallback_decision_source(): void
    {
        $service = new AgentActionExecutionService(
            new SelectedEntityContextService()
        );

        $context = new UnifiedActionContext('structured-fallback-session', 1);

        $captured = null;
        $service->executeUseTool(
            'unknown_tool',
            'how many invoices do I have?',
            $context,
            ['model_configs' => []],
            function (string $message, UnifiedActionContext $ctx, array $options) use (&$captured): AgentResponse {
                $captured = $options;

                return AgentResponse::conversational(message: 'rag fallback', context: $ctx);
            }
        );

        $this->assertNotNull($captured);
        $this->assertSame('tool_fallback', $captured['decision_source']);
        $this->assertSame('tool_fallback_structured_query', $captured['decision_path']);
        $this->assertSame('structured_query', $captured['preclassified_route_mode']);
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

    public function test_execute_registry_tool_binds_selected_entity_context(): void
    {
        $toolRegistry = new ToolRegistry();
        $toolRegistry->register('registry_entity_action', new AgentActionExecutionRegistryEntityToolStub());

        $service = new AgentActionExecutionService(
            new SelectedEntityContextService(),
            null,
            null,
            $toolRegistry
        );

        $context = new UnifiedActionContext('registry-entity-session', 99);
        $context->metadata['selected_entity_context'] = ['entity_id' => 7];

        // No tool_params provided, so binding must inject the selected entity id and pass validation.
        $response = $service->executeUseTool(
            'registry_entity_action',
            'pay it',
            $context,
            ['model_configs' => []],
            function () {
                $this->fail('Fallback RAG should not be called when registry tool resolves');
            }
        );

        $this->assertTrue($response->success);
        $this->assertSame(7, $response->data['invoice_id']);
    }

}
