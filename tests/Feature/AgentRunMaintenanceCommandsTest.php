<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunMaintenanceService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunMaintenanceCommandsTest extends TestCase
{
    public function test_recover_stuck_emits_failed_event_recovery_step_and_final_response(): void
    {
        $repo = app(AgentRunRepository::class);
        $run = $repo->create([
            'session_id' => 'stuck-run',
            'status' => AIAgentRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $report = app(AgentRunMaintenanceService::class)->recoverStuck(30);

        $this->assertSame(1, $report['updated']);

        $run->refresh();
        $this->assertSame(AIAgentRun::STATUS_FAILED, $run->status);

        // final_response describes the auto-fail
        $this->assertNotNull($run->final_response);
        $this->assertFalse($run->final_response['success']);
        $this->assertTrue($run->final_response['metadata']['auto_failed']);

        // recovery metadata appended to the run
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $run->metadata['recovery']['previous_status']);
        $this->assertArrayHasKey('recovered_at', $run->metadata['recovery']);

        // a recovery step was created
        $recoveryStep = $run->steps()->where('type', 'recovery')->first();
        $this->assertNotNull($recoveryStep);
        $this->assertSame(AIAgentRun::STATUS_FAILED, $recoveryStep->status);
        $this->assertSame('auto_fail', $recoveryStep->action);

        // RUN_FAILED event emitted so subscribers learn the run auto-failed
        $events = collect(app(AgentRunEventStreamService::class)->fallbackEvents($run))
            ->pluck('name')
            ->all();
        $this->assertContains(AgentRunEventStreamService::RUN_FAILED, $events);
    }

    public function test_recover_stuck_agent_runs_command_marks_old_running_runs_failed(): void
    {
        $repo = app(AgentRunRepository::class);
        $old = $repo->create([
            'session_id' => 'old-run',
            'status' => AIAgentRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
        $fresh = $repo->create([
            'session_id' => 'fresh-run',
            'status' => AIAgentRun::STATUS_RUNNING,
        ]);

        $exitCode = Artisan::call('ai:agent-runs:recover-stuck', [
            '--minutes' => 30,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['matched']);
        $this->assertSame(1, $payload['updated']);
        $this->assertSame(AIAgentRun::STATUS_FAILED, $old->refresh()->status);
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $fresh->refresh()->status);
    }

    public function test_cleanup_expired_agent_runs_command_deletes_old_expired_runs(): void
    {
        $repo = app(AgentRunRepository::class);
        $old = $repo->create([
            'session_id' => 'old-expired-run',
            'status' => AIAgentRun::STATUS_EXPIRED,
            'expired_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);
        $fresh = $repo->create([
            'session_id' => 'fresh-expired-run',
            'status' => AIAgentRun::STATUS_EXPIRED,
            'expired_at' => now(),
        ]);

        $exitCode = Artisan::call('ai:agent-runs:cleanup-expired', [
            '--days' => 7,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['matched']);
        $this->assertSame(1, $payload['deleted']);
        $this->assertNull($repo->find($old->id));
        $this->assertNotNull($repo->find($fresh->id));
    }

    public function test_agent_run_maintenance_commands_support_dry_run(): void
    {
        $repo = app(AgentRunRepository::class);
        $run = $repo->create([
            'session_id' => 'dry-run',
            'status' => AIAgentRun::STATUS_RUNNING,
            'started_at' => now()->subHour(),
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $exitCode = Artisan::call('ai:agent-runs:recover-stuck', [
            '--minutes' => 30,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['matched']);
        $this->assertSame(0, $payload['updated']);
        $this->assertSame(AIAgentRun::STATUS_RUNNING, $run->refresh()->status);
    }
}
