<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Contracts\AIScopeResolver;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphEventMapper;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphInterruptMapper;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper;
use LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentRuntimeManagerTest extends UnitTestCase
{
    public function test_laravel_runtime_wraps_current_processor_and_adds_runtime_metadata(): void
    {
        $context = new UnifiedActionContext('runtime-session', 7);
        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('hello', 'runtime-session', 7, ['engine' => 'openai'])
            ->andReturn(AgentResponse::conversational('Hi', $context));

        $response = (new LaravelAgentRuntime($processor))
            ->process('hello', 'runtime-session', 7, ['engine' => 'openai']);

        $this->assertTrue($response->success);
        $this->assertSame('laravel', $response->metadata['agent_runtime']);
        $this->assertTrue($response->metadata['agent_runtime_capabilities']['tools']);
        $this->assertTrue($response->metadata['agent_runtime_capabilities']['sub_agents']);
    }

    public function test_runtime_manager_selects_langgraph_when_requested(): void
    {
        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);

        $laravel->shouldReceive('capabilities')->andReturn(AgentRuntimeCapabilities::laravel());
        $langGraph->shouldReceive('capabilities')->andReturn(AgentRuntimeCapabilities::langGraph(true));
        $langGraph->shouldReceive('name')->once()->andReturn('langgraph');
        $langGraph->shouldReceive('process')
            ->once()
            ->with('run graph', 'runtime-session', 7, ['agent_runtime' => 'langgraph'])
            ->andReturn(AgentResponse::success('Graph runtime selected', context: new UnifiedActionContext('runtime-session', 7)));

        $manager = new AgentRuntimeManager($laravel, $langGraph);

        $response = $manager->process('run graph', 'runtime-session', 7, ['agent_runtime' => 'langgraph']);

        $this->assertTrue($response->success);
        $this->assertSame('Graph runtime selected', $response->message);
        $this->assertArrayHasKey('laravel', $manager->availableCapabilities());
        $this->assertArrayHasKey('langgraph', $manager->availableCapabilities());
    }

    public function test_runtime_manager_blocks_denied_runtime_before_dispatch(): void
    {
        config()->set('ai-agent.execution_policy.runtime_deny', ['langgraph']);

        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);
        $langGraph->shouldReceive('name')->andReturn('langgraph');
        $langGraph->shouldNotReceive('process');

        $manager = new AgentRuntimeManager($laravel, $langGraph);
        $response = $manager->process('run graph', 'runtime-session', 7, ['agent_runtime' => 'langgraph']);

        $this->assertFalse($response->success);
        $this->assertStringContainsString('blocked by execution policy', $response->message);
    }

    public function test_runtime_manager_checks_required_capabilities_before_dispatch(): void
    {
        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);
        $laravel->shouldReceive('name')->andReturn('laravel');
        $laravel->shouldReceive('capabilities')->andReturn(AgentRuntimeCapabilities::laravel());
        $laravel->shouldNotReceive('process');

        $manager = new AgentRuntimeManager($laravel, $langGraph);
        $response = $manager->process('stream this', 'runtime-session', 7, [
            'requires_capabilities' => ['streaming'],
        ]);

        $this->assertFalse($response->success);
        $this->assertSame(['streaming'], $response->data['missing_capabilities']);
    }

    public function test_runtime_manager_infers_tool_and_sub_agent_capabilities(): void
    {
        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);
        $laravel->shouldReceive('name')->andReturn('laravel');
        $laravel->shouldReceive('capabilities')->andReturn(AgentRuntimeCapabilities::laravel());
        $laravel->shouldReceive('process')->once()->andReturn(AgentResponse::success('ok'));

        $manager = new AgentRuntimeManager($laravel, $langGraph);
        $response = $manager->process('use tools', 'runtime-session', 7, [
            'tools' => ['search'],
            'sub_agents' => ['writer'],
        ]);

        $this->assertTrue($response->success);
    }

    public function test_runtime_manager_merges_resolved_scope_before_dispatch(): void
    {
        $resolver = new class implements AIScopeResolver {
            public function resolve(mixed $userId = null, array $options = []): array
            {
                return [
                    'tenant_id' => 'tenant-a',
                    'workspace_id' => 'workspace-a',
                ];
            }
        };

        $laravel = Mockery::mock(LaravelAgentRuntime::class);
        $langGraph = Mockery::mock(LangGraphAgentRuntime::class);
        $laravel->shouldReceive('name')->andReturn('laravel');
        $laravel->shouldReceive('capabilities')->andReturn(AgentRuntimeCapabilities::laravel());
        $laravel->shouldReceive('process')
            ->once()
            ->withArgs(function (string $message, string $sessionId, mixed $userId, array $options): bool {
                return $message === 'scoped request'
                    && $sessionId === 'runtime-session'
                    && $userId === 7
                    && ($options['tenant_id'] ?? null) === 'tenant-explicit'
                    && ($options['workspace_id'] ?? null) === 'workspace-a';
            })
            ->andReturn(AgentResponse::success('ok'));

        $manager = new AgentRuntimeManager(
            $laravel,
            $langGraph,
            null,
            new AIScopeOptionsService($resolver)
        );
        $response = $manager->process('scoped request', 'runtime-session', 7, [
            'tenant_id' => 'tenant-explicit',
        ]);

        $this->assertTrue($response->success);
    }

    public function test_disabled_langgraph_runtime_falls_back_to_laravel_when_configured(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', false);
        config()->set('ai-agent.runtime.langgraph.base_url', null);
        config()->set('ai-agent.runtime.langgraph.fallback_to_laravel', true);

        $context = new UnifiedActionContext('fallback-session', 3);
        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with('use langgraph', 'fallback-session', 3, [])
            ->andReturn(AgentResponse::conversational('Fallback response', $context));

        $laravel = new LaravelAgentRuntime($processor);
        $langGraph = new LangGraphAgentRuntime($laravel, $this->app->make(ContextManager::class));

        $response = $langGraph->process('use langgraph', 'fallback-session', 3);

        $this->assertTrue($response->success);
        $this->assertSame('laravel', $response->metadata['agent_runtime']);
        $this->assertSame('langgraph', $response->metadata['requested_agent_runtime']);
        $this->assertSame('laravel', $response->metadata['agent_runtime_fallback']);
        $this->assertSame('langgraph_disabled', $response->metadata['agent_runtime_fallback_reason']);
    }

    public function test_langgraph_runtime_redacts_sensitive_options_before_fallback(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', false);
        config()->set('ai-agent.runtime.langgraph.fallback_to_laravel', true);

        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->withArgs(function (string $message, string $sessionId, mixed $userId, array $options): bool {
                return $message === 'use langgraph'
                    && ($options['api_key'] ?? null) === '[redacted]'
                    && ($options['nested']['token'] ?? null) === '[redacted]';
            })
            ->andReturn(AgentResponse::conversational('Fallback response', new UnifiedActionContext('fallback-session', 3)));

        $runtime = new LangGraphAgentRuntime(new LaravelAgentRuntime($processor), $this->app->make(ContextManager::class));
        $response = $runtime->process('use langgraph', 'fallback-session', 3, [
            'api_key' => 'secret',
            'nested' => ['token' => 'secret-token'],
        ]);

        $this->assertTrue($response->success);
    }

    public function test_enabled_langgraph_runtime_starts_remote_run_and_maps_completed_response(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');
        config()->set('ai-agent.runtime.langgraph.api_token', 'test-token');
        config()->set('ai-agent.runtime.langgraph.signature_secret', 'test-secret');

        Http::fake([
            'https://langgraph.test/runs' => Http::response([
                'id' => 'lg-run-1',
                'thread_id' => 'thread-1',
                'status' => 'completed',
                'output' => ['message' => 'Graph completed.'],
            ]),
        ]);

        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldNotReceive('process');

        $runtime = new LangGraphAgentRuntime(new LaravelAgentRuntime($processor), $this->app->make(ContextManager::class));
        $response = $runtime->process('use graph', 'session-graph', 77, [
            'agent_run_id' => 100,
            'agent_run_uuid' => 'run-uuid',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-b',
            'rag_collections' => ['docs'],
            'tools' => ['search'],
            'sub_agents' => ['writer'],
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Graph completed.', $response->message);
        $this->assertSame('langgraph', $response->metadata['agent_runtime']);
        $this->assertSame('lg-run-1', $response->metadata['langgraph_run_id']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://langgraph.test/runs'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request->hasHeader('X-AI-Agent-Signature')
                && ($payload['thread_id'] ?? null) === 'run-uuid'
                && ($payload['input']['tenant_id'] ?? null) === 'tenant-a'
                && ($payload['input']['workspace_id'] ?? null) === 'workspace-b'
                && ($payload['rag_tools'][0]['name'] ?? null) === 'laravel_rag'
                && ($payload['sub_agents'][0]['agent_id'] ?? null) === 'writer';
        });
    }

    public function test_enabled_langgraph_runtime_maps_interrupted_response_to_user_input(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');

        Http::fake([
            'https://langgraph.test/runs' => Http::response([
                'id' => 'lg-run-2',
                'thread_id' => 'session-interrupt',
                'status' => 'interrupted',
                'interrupt' => [
                    'message' => 'Approve browser control?',
                    'required_inputs' => [['name' => 'approved', 'type' => 'boolean']],
                ],
            ]),
        ]);

        $runtime = new LangGraphAgentRuntime(
            new LaravelAgentRuntime(Mockery::mock(LaravelAgentProcessor::class)),
            $this->app->make(ContextManager::class)
        );

        $response = $runtime->process('use graph', 'session-interrupt', 77);

        $this->assertTrue($response->needsUserInput);
        $this->assertSame('Approve browser control?', $response->message);
        $this->assertSame('lg-run-2', $response->data['langgraph_run_id']);
    }

    public function test_enabled_langgraph_runtime_falls_back_when_remote_request_fails(): void
    {
        config()->set('ai-agent.runtime.langgraph.enabled', true);
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');
        config()->set('ai-agent.runtime.langgraph.fallback_to_laravel', true);

        Http::fake([
            'https://langgraph.test/runs' => Http::response(['error' => 'down'], 503),
        ]);

        $processor = Mockery::mock(LaravelAgentProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::conversational('Fallback works.', new UnifiedActionContext('session-down', 9)));

        $runtime = new LangGraphAgentRuntime(new LaravelAgentRuntime($processor), $this->app->make(ContextManager::class));
        $response = $runtime->process('use graph', 'session-down', 9);

        $this->assertTrue($response->success);
        $this->assertSame('laravel', $response->metadata['agent_runtime_fallback']);
        $this->assertSame('langgraph_request_failed', $response->metadata['agent_runtime_fallback_reason']);
    }

    public function test_langgraph_client_health_events_resume_and_cancel_contracts(): void
    {
        config()->set('ai-agent.runtime.langgraph.base_url', 'https://langgraph.test');

        Http::fake([
            'https://langgraph.test/health' => Http::response(['status' => 'ok']),
            'https://langgraph.test/runs/lg-run-1/events' => Http::response(['events' => [['event' => 'node.completed']]]),
            'https://langgraph.test/runs/lg-run-1/resume' => Http::response(['status' => 'completed']),
            'https://langgraph.test/runs/lg-run-1/cancel' => Http::response(['status' => 'cancelled']),
            'https://langgraph.test/runs/lg-run-1' => Http::response(['status' => 'running']),
        ]);

        $client = new LangGraphRuntimeClient();

        $this->assertSame('ok', $client->health()['status']);
        $this->assertSame('running', $client->getRun('lg-run-1')['status']);
        $this->assertSame('completed', $client->resumeRun('lg-run-1', ['approved' => true])['status']);
        $this->assertSame('cancelled', $client->cancelRun('lg-run-1')['status']);
        $this->assertSame('node.completed', $client->events('lg-run-1')['events'][0]['event']);
    }

    public function test_langgraph_event_mapper_converts_events_to_agent_step_attributes(): void
    {
        $attributes = (new LangGraphEventMapper())->toStepAttributes([
            'event' => 'node.completed',
            'node' => 'research',
            'output' => ['answer' => 'done'],
            'trace_id' => 'trace-1',
        ]);

        $this->assertSame('langgraph:research', $attributes['step_key']);
        $this->assertSame('langgraph', $attributes['type']);
        $this->assertSame('completed', $attributes['status']);
        $this->assertSame('research', $attributes['action']);
        $this->assertSame('trace-1', $attributes['metadata']['trace_id']);
    }

    public function test_langgraph_run_mapper_maps_failed_cancelled_and_running_statuses(): void
    {
        $mapper = new LangGraphRunMapper(new LangGraphInterruptMapper());
        $context = new UnifiedActionContext('session-map', 1);

        $failed = $mapper->toResponse(['id' => 'failed-1', 'status' => 'failed', 'error' => 'Bad graph'], $context);
        $cancelled = $mapper->toResponse(['id' => 'cancelled-1', 'status' => 'cancelled'], $context);
        $running = $mapper->toResponse(['id' => 'running-1', 'status' => 'running'], $context);

        $this->assertFalse($failed->success);
        $this->assertSame('Bad graph', $failed->message);
        $this->assertFalse($cancelled->success);
        $this->assertSame('LangGraph run was cancelled.', $cancelled->message);
        $this->assertTrue($running->needsUserInput);
        $this->assertSame('running', $running->metadata['langgraph_status']);
    }

    public function test_agent_runtime_contract_resolves_to_runtime_manager(): void
    {
        $runtime = $this->app->make(AgentRuntimeContract::class);

        $this->assertInstanceOf(AgentRuntimeManager::class, $runtime);
        $this->assertSame('laravel', $runtime->name());
        $this->assertTrue($runtime->capabilities()->tools);
    }

    public function test_runtime_config_defaults_are_safe_for_existing_apps(): void
    {
        $this->assertSame('laravel', config('ai-agent.runtime.default'));
        $this->assertFalse((bool) config('ai-agent.runtime.langgraph.enabled'));
        $this->assertNull(config('ai-agent.runtime.langgraph.base_url'));
        $this->assertSame(120, config('ai-agent.runtime.langgraph.timeout'));
        $this->assertTrue((bool) config('ai-agent.runtime.langgraph.fallback_to_laravel'));
    }

    public function test_runtime_capability_service_reports_current_and_available_runtimes(): void
    {
        $service = $this->app->make(AgentRuntimeCapabilityService::class);

        $report = $service->report();

        $this->assertSame('laravel', $report['current']['runtime']);
        $this->assertTrue($report['current']['capabilities']['tools']);
        $this->assertArrayHasKey('laravel', $report['available']);
        $this->assertArrayHasKey('langgraph', $report['available']);
        $this->assertArrayHasKey('remote_callbacks', $report['available']['langgraph']);
    }
}
