<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Jobs\ContinueAgentRunJob;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentRunAsyncJobsTest extends TestCase
{
    public function test_run_agent_job_processes_run_and_persists_response(): void
    {
        config()->set('ai-agent.run_safety.queue.tries', 4);
        config()->set('ai-agent.run_safety.queue.timeout', 90);
        config()->set('ai-agent.run_safety.queue.backoff', [5, 10]);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'queued-session',
            'user_id' => '9',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->once()->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->with('hello async', 'queued-session', '9', Mockery::on(
                fn (array $options): bool => ($options['tenant_id'] ?? null) === 'tenant-a'
                    && ($options['agent_run_id'] ?? null) === $run->id
                    && ($options['agent_run_step_id'] ?? null) !== null
                    && ($options['trace_id'] ?? null) !== null
                    && ($options['runtime'] ?? null) === 'laravel'
            ))
            ->andReturnUsing(function (): AgentResponse {
                $response = AgentResponse::success('Async response.');
                $response->metadata = [
                    'routing_decision' => ['action' => 'conversational', 'source' => 'classifier'],
                    'routing_trace' => [['action' => 'conversational', 'source' => 'classifier']],
                ];

                return $response;
            });

        $job = new RunAgentJob($run->id, 'hello async', 'queued-session', '9', ['tenant_id' => 'tenant-a']);
        $job->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));

        $this->assertSame(4, $job->tries);
        $this->assertSame(90, $job->timeout);
        $this->assertSame([5, 10], $job->backoff);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame('Async response.', $run->final_response['message']);
        $this->assertSame($run->metadata['trace_id'], $run->final_response['metadata']['trace_id']);
        $this->assertSame('conversational', $run->routing_trace[0]['action']);
        $this->assertSame('run', $run->current_step);
        $this->assertDatabaseHas('ai_agent_run_steps', [
            'run_id' => $run->id,
            'type' => 'agent_run',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);
        $step = $run->steps()->first();
        $this->assertSame($run->metadata['trace_id'], $step->metadata['otel']['trace_id']);
        $this->assertSame('conversational', $step->routing_decision['action']);
        $this->assertSame('agent.agent_run.process', $step->metadata['otel']['name']);
        $this->assertSame('laravel', $step->metadata['otel']['attributes']['ai.agent.runtime']);
    }

    public function test_continue_agent_run_job_uses_persisted_session_and_waiting_input_status(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'continue-session',
            'user_id' => '11',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->once()->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->with('continue please', 'continue-session', '11', Mockery::on(
                fn (array $options): bool => ($options['agent_run_id'] ?? null) === $run->id
                    && ($options['trace_id'] ?? null) !== null
            ))
            ->andReturn(AgentResponse::needsUserInput('Need one more field.'));

        $job = new ContinueAgentRunJob($run->id, 'continue please');
        $job->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));

        $this->assertSame(AIAgentRun::STATUS_WAITING_INPUT, $run->refresh()->status);
        $this->assertSame('Need one more field.', $run->final_response['message']);
        $this->assertDatabaseHas('ai_agent_run_steps', [
            'run_id' => $run->id,
            'type' => 'agent_continuation',
            'action' => 'continue',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
        ]);
    }

    public function test_duplicate_continuation_jobs_with_same_idempotency_key_process_once(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'duplicate-continuation',
            'user_id' => '12',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->once()->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::success('Processed once.'));

        $first = new ContinueAgentRunJob($run->id, 'continue', ['idempotency_key' => 'continue-1']);
        $second = new ContinueAgentRunJob($run->id, 'continue', ['idempotency_key' => 'continue-1']);

        $first->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));
        $second->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));

        $this->assertSame(1, $run->steps()->count());
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->refresh()->status);
    }

    public function test_run_agent_job_enforces_max_step_limit_before_processing(): void
    {
        config()->set('ai-agent.run_safety.queue.max_steps', 1);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'limited-session',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        app(AgentRunStepRepository::class)->create($run, [
            'type' => 'agent_run',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);

        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldNotReceive('process');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent run exceeded max step limit [1].');

        (new RunAgentJob($run->id, 'hello', 'limited-session'))
            ->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));
    }

    public function test_run_agent_job_enforces_token_and_cost_policy_before_processing(): void
    {
        config()->set('ai-agent.run_safety.queue.max_tokens', 100);
        config()->set('ai-agent.run_safety.queue.max_cost', 0.5);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'budget-session',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldNotReceive('process');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent run exceeded max token limit [100].');

        (new RunAgentJob($run->id, 'hello', 'budget-session', null, [
            'estimated_tokens' => 101,
            'estimated_cost' => 0.25,
        ]))->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));
    }

    public function test_run_agent_job_enforces_cost_policy_before_processing(): void
    {
        config()->set('ai-agent.run_safety.queue.max_tokens', null);
        config()->set('ai-agent.run_safety.queue.max_cost', 0.5);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'cost-session',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldNotReceive('process');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent run exceeded max cost limit [0.5].');

        (new RunAgentJob($run->id, 'hello', 'cost-session', null, [
            'estimated_cost' => 0.75,
        ]))->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));
    }

    public function test_run_agent_job_blocks_mismatched_tenant_scope_before_processing(): void
    {
        config()->set('vector-access-control.enable_tenant_scope', true);

        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'tenant-isolated-run',
            'tenant_id' => 'tenant-a',
            'status' => AIAgentRun::STATUS_PENDING,
        ]);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldNotReceive('process');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('tenant scope');

        (new RunAgentJob($run->id, 'hello', 'tenant-isolated-run', null, [
            'tenant_id' => 'tenant-b',
        ]))->handle($runtime, app(AgentRunRepository::class), app(AgentRunStepRepository::class), app(AgentRunSafetyService::class));
    }

    public function test_interrupted_queue_worker_marks_non_terminal_run_failed(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'interrupted-worker',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        (new RunAgentJob($run->id, 'hello', 'interrupted-worker'))
            ->failed(new \RuntimeException('Worker interrupted.'));

        $run->refresh();

        $this->assertSame(AIAgentRun::STATUS_FAILED, $run->status);
        $this->assertSame('Worker interrupted.', $run->failure_reason);
    }
}
