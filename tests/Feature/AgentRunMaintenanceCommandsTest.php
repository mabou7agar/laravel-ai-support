<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Tests\TestCase;

class AgentRunMaintenanceCommandsTest extends TestCase
{
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
