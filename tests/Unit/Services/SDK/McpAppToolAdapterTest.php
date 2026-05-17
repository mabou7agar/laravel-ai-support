<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\SDK\McpAppToolAdapter;
use LaravelAIEngine\Tests\UnitTestCase;

class McpAppToolAdapterTest extends UnitTestCase
{
    public function test_exports_registered_tools_as_mcp_tool_schema_and_calls_them(): void
    {
        $registry = new ToolRegistry();
        $registry->register('send_reply', new class extends SimpleAgentTool {
            public string $name = 'send_reply';
            public string $description = 'Send a reply.';
            public array $parameters = [
                'body' => ['type' => 'string', 'required' => true, 'description' => 'Reply body'],
            ];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Reply sent.', ['body' => $parameters['body']]);
            }
        });

        $adapter = new McpAppToolAdapter($registry);
        $tools = $adapter->listTools();

        $this->assertSame('send_reply', $tools[0]['name']);
        $this->assertSame(['body'], $tools[0]['inputSchema']['required']);

        $result = $adapter->callTool('send_reply', ['body' => 'Thanks'], new UnifiedActionContext('mcp-session'));

        $this->assertTrue($result['success']);
        $this->assertSame('Thanks', $result['data']['body']);
    }
}
