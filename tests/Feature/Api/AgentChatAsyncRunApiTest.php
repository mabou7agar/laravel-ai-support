<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use Illuminate\Support\Facades\Queue;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Services\Agent\AgentChatRunService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentChatAsyncRunApiTest extends TestCase
{
    public function test_agent_chat_api_queues_durable_run_when_async_is_true(): void
    {
        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldReceive('start')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool =>
                ($payload['message'] ?? null) === 'Create invoice from this conversation'
                && ($payload['session_id'] ?? null) === 'async-chat-api'
                && ($payload['user_id'] ?? null) === 'user-async'
                && ($payload['options']['engine'] ?? null) === 'openai'
                && ($payload['options']['model'] ?? null) === 'gpt-4o-mini'
                && ($payload['options']['use_rag'] ?? null) === false
            ))
            ->andReturn([
                'queued' => true,
                'run' => [
                    'uuid' => 'run-async-1',
                    'status' => 'pending',
                    'session_id' => 'async-chat-api',
                ],
                'agent_run_id' => 'run-async-1',
                'status_url' => '/api/v1/ai/agent-runs/run-async-1',
                'trace_url' => '/api/v1/ai/agent-runs/run-async-1/trace',
                'stream_url' => '/api/v1/ai/agent-runs/run-async-1/stream',
                'broadcast' => ['enabled' => false],
            ]);

        $this->app->instance(AgentChatRunService::class, $service);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Create invoice from this conversation',
            'session_id' => 'async-chat-api',
            'user_id' => 'user-async',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'memory' => false,
            'actions' => true,
            'use_rag' => false,
            'async' => true,
        ])
            ->assertAccepted()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.agent_run_id', 'run-async-1')
            ->assertJsonPath('data.stream_url', '/api/v1/ai/agent-runs/run-async-1/stream');
    }

    public function test_agent_chat_api_auto_execution_mode_queues_goal_requests(): void
    {
        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldReceive('start')
            ->once()
            ->with(Mockery::on(fn (array $payload): bool =>
                ($payload['message'] ?? null) === 'Plan this work with sub agents'
                && ($payload['options']['execution_mode'] ?? null) === 'auto'
                && ($payload['options']['execution_mode_resolved'] ?? null) === 'async'
                && ($payload['options']['execution_mode_reason'] ?? null) === 'goal_or_sub_agent'
            ))
            ->andReturn([
                'queued' => true,
                'run' => [
                    'uuid' => 'run-auto-1',
                    'status' => 'pending',
                    'session_id' => 'auto-chat-api',
                ],
                'agent_run_id' => 'run-auto-1',
                'status_url' => '/api/v1/ai/agent-runs/run-auto-1',
                'trace_url' => '/api/v1/ai/agent-runs/run-auto-1/trace',
                'stream_url' => '/api/v1/ai/agent-runs/run-auto-1/stream',
                'broadcast' => ['enabled' => false],
            ]);

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldNotReceive('processMessage');

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Plan this work with sub agents',
            'session_id' => 'auto-chat-api',
            'user_id' => 'user-auto',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'actions' => true,
            'use_rag' => false,
            'agent_goal' => true,
            'execution_mode' => 'auto',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.execution_mode', 'async')
            ->assertJsonPath('data.execution_mode_reason', 'goal_or_sub_agent');
    }

    public function test_agent_chat_api_auto_execution_mode_keeps_simple_chat_synchronous(): void
    {
        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldNotReceive('start');

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function (...$args): AIResponse {
                $this->assertSame('Hi', $args[0]);
                $this->assertSame('auto-sync-chat-api', $args[1]);
                $this->assertFalse($args[6]);
                $this->assertSame('user-auto', $args[8]);
                $options = end($args);
                $this->assertSame('auto', $options['execution_mode'] ?? null);
                $this->assertSame('sync', $options['execution_mode_resolved'] ?? null);
                $this->assertSame('simple_chat', $options['execution_mode_reason'] ?? null);

                return AIResponse::success('Hello.', 'openai', 'gpt-4o-mini');
            });

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Hi',
            'session_id' => 'auto-sync-chat-api',
            'user_id' => 'user-auto',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'actions' => true,
            'use_rag' => false,
            'execution_mode' => 'auto',
        ])
            ->assertOk()
            ->assertJsonPath('data.response', 'Hello.')
            ->assertJsonPath('data.execution_mode', 'sync')
            ->assertJsonPath('data.execution_mode_reason', 'simple_chat');
    }

    public function test_agent_chat_api_casts_authenticated_numeric_user_id_to_string(): void
    {
        $user = $this->createTestUser();

        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldNotReceive('start');

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function (...$args) use ($user): AIResponse {
                $this->assertSame((string) $user->getAuthIdentifier(), $args[8]);

                return AIResponse::success('Authenticated response.', 'openai', 'gpt-4o-mini');
            });

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->actingAs($user)
            ->postJson('/api/v1/agent/chat', [
                'message' => 'Hi',
                'session_id' => 'authenticated-chat-api',
                'engine' => 'openai',
                'model' => 'gpt-4o-mini',
                'use_rag' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.response', 'Authenticated response.');
    }

    public function test_agent_chat_api_explicit_sync_overrides_async_default(): void
    {
        config()->set('ai-agent.chat.async_default', true);

        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldNotReceive('start');

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->andReturn(AIResponse::success('Sync response.', 'openai', 'gpt-4o-mini'));

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Hi',
            'session_id' => 'explicit-sync-chat-api',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'use_rag' => false,
            'execution_mode' => 'sync',
        ])
            ->assertOk()
            ->assertJsonPath('data.response', 'Sync response.')
            ->assertJsonPath('data.execution_mode', 'sync')
            ->assertJsonPath('data.execution_mode_reason', 'explicit_sync');
    }

    public function test_agent_chat_api_falls_back_to_sync_when_async_is_disabled(): void
    {
        config()->set('ai-agent.chat.async_enabled', false);

        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldNotReceive('start');

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function (...$args): AIResponse {
                $options = end($args);
                $this->assertSame('sync', $options['execution_mode_resolved'] ?? null);
                $this->assertSame('async_disabled', $options['execution_mode_reason'] ?? null);

                return AIResponse::success('Sync fallback.', 'openai', 'gpt-4o-mini');
            });

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Hi',
            'session_id' => 'async-disabled-chat-api',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'use_rag' => false,
            'async' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.response', 'Sync fallback.')
            ->assertJsonPath('data.execution_mode', 'sync')
            ->assertJsonPath('data.execution_mode_reason', 'async_disabled');
    }

    public function test_agent_chat_api_accepts_async_auto_alias_for_auto_execution_mode(): void
    {
        $service = Mockery::mock(AgentChatRunService::class);
        $service->shouldNotReceive('start');

        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->once()
            ->withAnyArgs()
            ->andReturnUsing(function (...$args): AIResponse {
                $options = end($args);
                $this->assertSame('auto', $options['execution_mode'] ?? null);
                $this->assertSame('sync', $options['execution_mode_resolved'] ?? null);

                return AIResponse::success('Hello from auto.', 'openai', 'gpt-4o-mini');
            });

        $this->app->instance(AgentChatRunService::class, $service);
        $this->app->instance(ChatService::class, $chat);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Hi',
            'session_id' => 'async-auto-alias-chat-api',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'use_rag' => false,
            'async' => 'auto',
        ])
            ->assertOk()
            ->assertJsonPath('data.response', 'Hello from auto.')
            ->assertJsonPath('data.execution_mode', 'sync');
    }

    public function test_agent_chat_run_service_creates_run_and_dispatches_job(): void
    {
        Queue::fake();

        $response = app(AgentChatRunService::class)->start([
            'message' => 'Generate invoice preview',
            'session_id' => 'async-chat-service',
            'user_id' => 'user-service',
            'options' => [
                'engine' => 'openai',
                'model' => 'gpt-4o-mini',
                'tenant_id' => 'tenant-1',
                'workspace_id' => 'workspace-1',
                'use_rag' => false,
            ],
        ]);

        $this->assertTrue($response['queued']);
        $this->assertSame('async-chat-service', $response['run']['session_id']);
        $this->assertSame('pending', $response['run']['status']);
        $this->assertSame($response['agent_run_id'], $response['run']['uuid']);
        $this->assertSame("/api/v1/ai/agent-runs/{$response['agent_run_id']}", $response['status_url']);
        $this->assertSame("/api/v1/ai/agent-runs/{$response['agent_run_id']}/stream", $response['stream_url']);

        Queue::assertPushed(RunAgentJob::class, fn (RunAgentJob $job): bool =>
            $job->message === 'Generate invoice preview'
            && $job->sessionId === 'async-chat-service'
            && $job->userId === 'user-service'
            && ($job->options['tenant_id'] ?? null) === 'tenant-1'
            && ($job->options['workspace_id'] ?? null) === 'workspace-1'
        );
    }
}
