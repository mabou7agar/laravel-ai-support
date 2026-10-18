<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager;
use LaravelAIEngine\Tests\Support\Stubs\RecordingAgentRuntime;
use LaravelAIEngine\Tests\TestCase;

/**
 * A host application registers a third AgentRuntimeContract implementation
 * through `ai-agent.runtime.drivers` and the real RunAgentJob executes against
 * it through the container-bound runtime manager.
 */
class AgentRuntimeDriverRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordingAgentRuntime::reset();
        config()->set('ai-agent.runtime.drivers', ['recording' => RecordingAgentRuntime::class]);
        config()->set('ai-agent.runtime.default', 'recording');
    }

    protected function tearDown(): void
    {
        RecordingAgentRuntime::reset();

        parent::tearDown();
    }

    public function test_registered_runtime_is_visible_through_container_bound_manager_and_capability_report(): void
    {
        $manager = $this->app->make(AgentRuntimeContract::class);

        $this->assertInstanceOf(AgentRuntimeManager::class, $manager);
        $this->assertSame(0, RecordingAgentRuntime::$instances, 'binding the manager must not build registered runtimes');

        $report = $this->app->make(AgentRuntimeCapabilityService::class)->report();

        $this->assertSame('recording', $report['current']['runtime']);
        $this->assertTrue($report['current']['capabilities']['tools']);
        $this->assertSame(['recording', 'laravel', 'langgraph'], array_keys($report['available']));
        $this->assertSame(1, RecordingAgentRuntime::$instances);
    }

    public function test_run_agent_job_executes_registered_runtime_and_persists_run_lifecycle(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'driver-session',
            'user_id' => '9',
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);

        $job = new RunAgentJob($run->id, 'hello registered runtime', 'driver-session', '9', [
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'idempotency_key' => 'driver-run-1',
        ]);

        $this->handle($job);

        // The runtime received the request exactly once, with scope and identifiers.
        $this->assertCount(1, RecordingAgentRuntime::$calls);
        $call = RecordingAgentRuntime::$calls[0];
        $this->assertSame('hello registered runtime', $call['message']);
        $this->assertSame('driver-session', $call['session_id']);
        $this->assertSame('9', $call['user_id']);
        $this->assertSame('tenant-a', $call['options']['tenant_id']);
        $this->assertSame('workspace-a', $call['options']['workspace_id']);
        $this->assertNotEmpty($call['options']['scope_key']);

        $run->refresh();
        $step = $run->steps()->first();

        $this->assertSame($run->id, $call['options']['agent_run_id']);
        $this->assertSame($run->uuid, $call['options']['agent_run_uuid']);
        $this->assertSame($step->id, $call['options']['agent_run_step_id']);
        $this->assertSame($step->uuid, $call['options']['agent_run_step_uuid']);
        $this->assertSame('recording', $call['options']['runtime']);
        $this->assertNotEmpty($call['options']['trace_id']);

        // Completion, final response and runtime attribution persisted on run and step.
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('recording', $run->runtime);
        $this->assertSame('tenant-a', (string) $run->tenant_id);
        $this->assertSame('workspace-a', (string) $run->workspace_id);
        $this->assertSame('Recorded response.', $run->final_response['message']);
        $this->assertSame('recording', $run->final_response['metadata']['agent_runtime']);
        $this->assertNotNull($run->completed_at);
        $this->assertSame(1, $run->steps()->count());
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $step->status);
        $this->assertSame('recording', $step->source);
        $this->assertSame('recording', $step->metadata['otel']['attributes']['ai.agent.runtime']);
        $this->assertNotNull($step->completed_at);

        // Usage accounting reached the run budget.
        $this->assertSame(42, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(0.1, $run->metadata['budget']['usage']['cost']);
        $this->assertSame(1, $run->metadata['budget']['usage']['requests']);
        $this->assertSame(42, $run->metadata['tokens_used']);

        // Lifecycle envelopes: exactly one start and one terminal event, both attributed.
        $events = collect($run->metadata['events'] ?? []);
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_STARTED)->count());
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_COMPLETED)->count());
        $this->assertSame(0, $events->where('name', AgentRunEventStreamService::RUN_FAILED)->count());

        $started = $events->firstWhere('name', AgentRunEventStreamService::RUN_STARTED);
        $completed = $events->firstWhere('name', AgentRunEventStreamService::RUN_COMPLETED);
        $this->assertSame('recording', $started['payload']['runtime']);
        $this->assertSame('driver-session', $started['payload']['session_id']);
        $this->assertSame('Recorded response.', $completed['payload']['message']);
        $this->assertTrue($completed['payload']['success']);
        $this->assertSame('recording', $started['metadata']['runtime']);
        $this->assertSame('recording', $completed['metadata']['runtime']);
        $this->assertSame($run->uuid, $completed['run_id']);
        $this->assertSame($step->uuid, $completed['step_id']);
    }

    public function test_rerunning_the_same_job_does_not_invoke_runtime_again_or_duplicate_usage_and_events(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'driver-rerun',
            'user_id' => '9',
            'tenant_id' => 'tenant-a',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);

        $job = new RunAgentJob($run->id, 'run once', 'driver-rerun', '9', [
            'tenant_id' => 'tenant-a',
            'idempotency_key' => 'driver-rerun-1',
        ]);

        $this->handle($job);
        $this->handle($job);

        // A duplicate delivery of the same job with the same key is a no-op.
        $duplicate = new RunAgentJob($run->id, 'run once', 'driver-rerun', '9', [
            'tenant_id' => 'tenant-a',
            'idempotency_key' => 'driver-rerun-1',
        ]);
        $this->handle($duplicate);

        $run->refresh();
        $events = collect($run->metadata['events'] ?? []);

        $this->assertCount(1, RecordingAgentRuntime::$calls);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->steps()->count());
        $this->assertSame(42, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(1, $run->metadata['budget']['usage']['requests']);
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_STARTED)->count());
        $this->assertSame(1, $events->where('name', AgentRunEventStreamService::RUN_COMPLETED)->count());
    }

    private function handle(RunAgentJob $job): void
    {
        $job->handle(
            $this->app->make(AgentRuntimeContract::class),
            app(AgentRunRepository::class),
            app(AgentRunStepRepository::class),
            app(AgentRunSafetyService::class)
        );
    }
}
