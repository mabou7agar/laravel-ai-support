<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Console;

use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Tests\TestCase;

class ReplayFailedAgentRunStepCommandTest extends TestCase
{
    public function test_replay_failed_step_command_creates_pending_replay_step(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'replay-command',
            'status' => AIAgentRun::STATUS_FAILED,
            'failure_reason' => 'boom',
        ]);
        $failed = app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'tool',
            'type' => 'tool',
            'status' => AIAgentRun::STATUS_FAILED,
            'action' => 'web_search',
            'metadata' => ['trace_id' => 'trace-1'],
        ]);

        $this->artisan('ai:agent-runs:replay-step', [
            'step' => $failed->uuid,
            '--reason' => 'retry after fix',
            '--json' => true,
        ])->assertExitCode(0);

        $replay = $run->steps()->where('status', AIAgentRun::STATUS_PENDING)->firstOrFail();

        $this->assertSame('tool', $replay->step_key);
        $this->assertSame($failed->id, $replay->metadata['replay_of_step_id']);
        $this->assertTrue($replay->metadata['replayed_by_command']);
        $this->assertSame('retry after fix', $replay->metadata['replay_reason']);
        $this->assertSame(AIAgentRun::STATUS_PENDING, $run->refresh()->status);
    }
}
