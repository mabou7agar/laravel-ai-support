<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIProviderToolArtifact;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunRetentionService;
use LaravelAIEngine\Tests\TestCase;

class AgentRunRetentionTest extends TestCase
{
    public function test_retention_service_redacts_inputs_responses_and_provider_payloads_by_config(): void
    {
        config()->set('ai-agent.run_retention.redact_prompts', true);
        config()->set('ai-agent.run_retention.redact_responses', true);
        config()->set('ai-agent.run_retention.store_raw_provider_payloads', false);

        $service = app(AgentRunRetentionService::class);

        $this->assertSame('prompt', $service->protectInput(['message' => 'secret'])['type']);
        $this->assertTrue($service->protectInput(['message' => 'secret'])['redacted']);
        $this->assertSame('response', $service->protectResponse(['message' => 'secret'])['type']);
        $this->assertFalse($service->shouldStoreRawProviderPayloads());
    }

    public function test_retention_cleanup_command_applies_run_step_trace_and_artifact_retention(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'retention-session',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'routing_trace' => ['selected' => ['action' => 'search_rag']],
        ]);
        $run->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)])->save();

        $step = app(AgentRunStepRepository::class)->create($run, [
            'type' => 'routing',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'routing_trace' => ['decisions' => []],
            'routing_decision' => ['action' => 'search_rag'],
        ]);
        $step->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)])->save();

        $artifact = AIProviderToolArtifact::create([
            'uuid' => (string) Str::uuid(),
            'agent_run_step_id' => $step->id,
            'provider' => 'openai',
            'artifact_type' => 'file',
            'name' => 'old.txt',
        ]);
        $artifact->forceFill(['created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10)])->save();

        $exitCode = Artisan::call('ai:agent-runs:retention-cleanup', [
            '--run-days' => 7,
            '--step-days' => 7,
            '--trace-days' => 7,
            '--artifact-days' => 7,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $payload['runs_deleted']);
        $this->assertSame(1, $payload['steps_deleted']);
        $this->assertSame(1, $payload['artifacts_deleted']);
        $this->assertSame(2, $payload['traces_redacted']);
        $this->assertDatabaseMissing('ai_agent_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('ai_agent_run_steps', ['id' => $step->id]);
        $this->assertDatabaseMissing('ai_provider_tool_artifacts', ['id' => $artifact->id]);
    }
}
