<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\TestCase;

class McpRealtimeIntegrationApiTest extends TestCase
{
    public function test_mcp_tools_api_lists_and_calls_registered_tools(): void
    {
        $this->registerEchoTool();

        $this->getJson('/api/v1/ai/mcp/tools')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['name' => 'echo_tool']);

        $this->postJson('/api/v1/ai/mcp/tools/echo_tool/call', [
            'arguments' => ['text' => 'hello'],
            'session_id' => 'mcp-api-session',
            'user_id' => 'user-7',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.data.text', 'hello')
            ->assertJsonPath('data.result.data.user_id', 'user-7');
    }

    public function test_realtime_tool_dispatch_api_dispatches_registered_tool(): void
    {
        $this->registerEchoTool();

        $this->postJson('/api/v1/ai/realtime/tools/dispatch', [
            'event' => [
                'id' => 'call_api_1',
                'name' => 'echo_tool',
                'arguments' => ['text' => 'live hello'],
            ],
            'session_id' => 'realtime-api-session',
            'user_id' => 'user-8',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.result.success', true)
            ->assertJsonPath('data.result.tool_call_id', 'call_api_1')
            ->assertJsonPath('data.result.output.data.text', 'live hello');
    }

    protected function registerEchoTool(): void
    {
        $registry = app(ToolRegistry::class);
        $registry->register('echo_tool', new class extends SimpleAgentTool {
            public string $name = 'echo_tool';
            public string $description = 'Echo input for integration tests.';
            public array $parameters = [
                'text' => ['type' => 'string', 'required' => true],
            ];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', [
                    'text' => $parameters['text'],
                    'user_id' => $context->userId,
                ]);
            }
        });
    }
}
