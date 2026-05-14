<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunRecoveryService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunRecoveryServiceTest extends TestCase
{
    public function test_replays_failed_step_resumes_from_step_and_marks_run_resolved(): void
    {
        $runs = app(AgentRunRepository::class);
        $steps = app(AgentRunStepRepository::class);
        $recovery = app(AgentRunRecoveryService::class);

        $run = $runs->create([
            'session_id' => 'recover-session',
            'status' => AIAgentRun::STATUS_FAILED,
            'current_step' => 'tool-1',
            'failure_reason' => 'Tool failed.',
        ]);
        $failedStep = $steps->create($run, [
            'step_key' => 'tool-1',
            'type' => 'provider_tool',
            'status' => AIAgentRun::STATUS_FAILED,
            'action' => 'code_interpreter',
            'source' => 'openai',
            'input' => ['prompt' => 'analyze'],
            'routing_decision' => ['action' => 'use_tool'],
            'error' => 'Tool failed.',
        ]);

        $replay = $recovery->replayFailedStep($failedStep);
        $this->assertSame(AIAgentRun::STATUS_PENDING, $replay->status);
        $this->assertSame($failedStep->id, $replay->metadata['replay_of_step_id']);
        $this->assertSame(AIAgentRun::STATUS_PENDING, $run->refresh()->status);
        $this->assertSame('failed_step_replayed', $run->metadata['recovery_events'][0]['event']);

        $resumed = $recovery->resumeFromStep($replay, ['reason' => 'retry requested']);
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $resumed->status);
        $this->assertSame('tool-1', $resumed->current_step);
        $this->assertSame('resumed_from_step', $resumed->metadata['recovery_events'][1]['event']);

        $resolved = $recovery->markManuallyResolved($resumed, 'admin-1', 'Verified outside the agent.', [
            'message' => 'Resolved manually.',
        ]);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $resolved->status);
        $this->assertSame(['message' => 'Resolved manually.'], $resolved->final_response);
        $this->assertSame('manually_resolved', $resolved->metadata['recovery_events'][2]['event']);
    }

    public function test_only_failed_steps_can_be_replayed(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'not-failed-session',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);
        $step = app(AgentRunStepRepository::class)->create($run, [
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(AgentRunRecoveryService::class)->replayFailedStep($step);
    }
}
