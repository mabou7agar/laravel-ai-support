<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\SDK\RealtimeToolBrokerService;
use LaravelAIEngine\Tests\UnitTestCase;

class RealtimeToolBrokerServiceTest extends UnitTestCase
{
    public function test_dispatches_openai_realtime_tool_call_to_registered_tool(): void
    {
        $registry = new ToolRegistry();
        $registry->register('echo_tool', new class extends SimpleAgentTool {
            public string $name = 'echo_tool';
            public string $description = 'Echo input.';
            public array $parameters = ['text' => ['type' => 'string', 'required' => true]];

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Echoed.', ['text' => $parameters['text'], 'user_id' => $context->userId]);
            }
        });

        $result = (new RealtimeToolBrokerService($registry))->dispatch([
            'type' => 'response.function_call_arguments.done',
            'call_id' => 'call_1',
            'name' => 'echo_tool',
            'arguments' => json_encode(['text' => 'hello']),
        ], new UnifiedActionContext('rt-session', 'user-1'));

        $this->assertTrue($result['success']);
        $this->assertSame('call_1', $result['tool_call_id']);
        $this->assertSame('hello', $result['output']['data']['text']);
        $this->assertSame('user-1', $result['output']['data']['user_id']);
    }

    public function test_confirmation_required_tool_returns_approval_payload_without_execution(): void
    {
        $registry = new ToolRegistry();
        $registry->register('danger_tool', new class extends SimpleAgentTool {
            public string $name = 'danger_tool';
            public bool $requiresConfirmation = true;

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('Should not execute.');
            }
        });

        $result = (new RealtimeToolBrokerService($registry))->dispatch([
            'id' => 'call_2',
            'name' => 'danger_tool',
            'arguments' => [],
        ], new UnifiedActionContext('rt-session'));

        $this->assertFalse($result['success']);
        $this->assertSame('approval_required', $result['status']);
    }
}
