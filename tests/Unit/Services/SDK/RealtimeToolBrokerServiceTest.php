<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\SDK\RealtimeToolBrokerService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RealtimeToolBrokerServiceTest extends UnitTestCase
{
    public function test_agent_chat_ignores_client_supplied_identity_by_default(): void
    {
        // Default (trust_client_identity=false): the argument-supplied user_id/session_id
        // must be ignored so a caller cannot load/act on another user's conversation —
        // only the authenticated request context is used.
        $captured = [];
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured): AIResponse {
                $captured['session_id'] = $args[1];
                $captured['user_id'] = $args[8];

                return AIResponse::success('ok', 'openai', 'gpt-4o-mini');
            });
        $this->app->instance(ChatService::class, $chat);

        (new RealtimeToolBrokerService(new ToolRegistry()))->dispatch([
            'id' => 'call_agent_chat',
            'name' => 'agent_chat',
            'arguments' => [
                'message' => 'hello',
                'user_id' => 'attacker-victim',
                'session_id' => 'victim-session',
            ],
        ], new UnifiedActionContext('ctx-session', 'ctx-user'));

        $this->assertSame('ctx-session', $captured['session_id']);
        $this->assertSame('ctx-user', $captured['user_id']);
    }

    public function test_agent_chat_trusts_client_supplied_identity_when_opted_in(): void
    {
        config()->set('ai-engine.realtime.trust_client_identity', true);

        $captured = [];
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->andReturnUsing(function (...$args) use (&$captured): AIResponse {
                $captured['session_id'] = $args[1];
                $captured['user_id'] = $args[8];

                return AIResponse::success('ok', 'openai', 'gpt-4o-mini');
            });
        $this->app->instance(ChatService::class, $chat);

        (new RealtimeToolBrokerService(new ToolRegistry()))->dispatch([
            'id' => 'call_agent_chat',
            'name' => 'agent_chat',
            'arguments' => [
                'message' => 'hello',
                'user_id' => 'relayed-user',
                'session_id' => 'relayed-session',
            ],
        ], new UnifiedActionContext('ctx-session', 'ctx-user'));

        $this->assertSame('relayed-session', $captured['session_id']);
        $this->assertSame('relayed-user', $captured['user_id']);
    }

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

    public function test_execution_policy_deny_blocks_realtime_tool_without_executing(): void
    {
        // The org deny-list must gate the realtime SDK path too — otherwise a realtime
        // client could invoke a forbidden tool that the agent loop would refuse.
        config()->set('ai-agent.execution_policy.tool_deny', ['echo_tool']);

        $tool = new class extends SimpleAgentTool {
            public string $name = 'echo_tool';
            public string $description = 'Echo input.';
            public bool $executed = false;

            protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
            {
                $this->executed = true;

                return ActionResult::success('Echoed.');
            }
        };

        $registry = new ToolRegistry();
        $registry->register('echo_tool', $tool);

        $result = (new RealtimeToolBrokerService($registry))->dispatch([
            'call_id' => 'call_deny',
            'name' => 'echo_tool',
            'arguments' => json_encode(['text' => 'hello']),
        ], new UnifiedActionContext('rt-session', 'user-1'));

        $this->assertFalse($result['success']);
        $this->assertSame('policy_blocked', $result['status']);
        $this->assertSame('echo_tool', $result['tool_name']);
        $this->assertFalse($tool->executed, 'A policy-denied realtime tool must never execute.');
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
