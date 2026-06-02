<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Models\AIAgentRunStep;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Tests\TestCase;

/**
 * The AiNative runtime executes tools INSIDE its planning loop without its own
 * event sink, so a turn it handles would otherwise surface no tool.* timeline
 * events (unlike the dispatcher path). RunAgentJob reconstructs them from the
 * recorded metadata['ai_native']['tool_results'] so a UI still gets the
 * "Searching for customer… ✓/✗" steps. This pins that reconstruction.
 */
class AiNativeToolEventReconstructionTest extends TestCase
{
    private function step(AIAgentRun $run): AIAgentRunStep
    {
        return app(AgentRunStepRepository::class)->create($run, [
            'step_key' => 'run',
            'type' => 'agent_run',
            'status' => AIAgentRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function test_tool_started_and_completed_are_reconstructed_from_ai_native_tool_results(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'ainative-tool-events',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);
        $step = $this->step($run);

        $response = new AgentResponse(
            success: true,
            message: 'Done',
            metadata: ['ai_native' => ['tool_results' => [
                ['tool' => 'find_customer', 'params' => ['query' => 'Acme'], 'result' => ['success' => true]],
                ['tool' => 'create_invoice', 'params' => ['customer_id' => 5], 'result' => ['success' => false, 'error' => 'nope']],
            ]]]
        );

        $job = new class (0, '', '', null) extends RunAgentJob {
            public function emitToolEvents(AgentRunEventStreamService $e, AIAgentRun $r, AIAgentRunStep $s, AgentResponse $resp): void
            {
                $this->emitAiNativeToolEvents($e, $r, $s, $resp, []);
            }
        };

        $job->emitToolEvents(app(AgentRunEventStreamService::class), $run, $step, $response);

        $events = collect(app(AgentRunEventStreamService::class)->fallbackEvents($run));
        $names = $events->pluck('name')->all();

        // Both tools surface a started event, and the success/failure terminal maps
        // to tool.completed vs tool.failed respectively.
        $this->assertSame(2, collect($names)->filter(fn ($n) => $n === AgentRunEventStreamService::TOOL_STARTED)->count());
        $this->assertContains(AgentRunEventStreamService::TOOL_COMPLETED, $names);
        $this->assertContains(AgentRunEventStreamService::TOOL_FAILED, $names);

        $started = $events->where('name', AgentRunEventStreamService::TOOL_STARTED)->pluck('payload.tool_name')->all();
        $this->assertSame(['find_customer', 'create_invoice'], $started);

        $completed = $events->firstWhere('name', AgentRunEventStreamService::TOOL_COMPLETED);
        $failed = $events->firstWhere('name', AgentRunEventStreamService::TOOL_FAILED);
        $this->assertSame('find_customer', $completed['payload']['tool_name'] ?? null);
        $this->assertSame('create_invoice', $failed['payload']['tool_name'] ?? null);
    }

    public function test_non_ai_native_turn_emits_no_reconstructed_tool_events(): void
    {
        $run = app(AgentRunRepository::class)->create([
            'session_id' => 'non-ainative-tool-events',
            'status' => AIAgentRun::STATUS_COMPLETED,
        ]);
        $step = $this->step($run);

        // A dispatcher-path response has no metadata['ai_native'] — reconstruction
        // must be a no-op so it never double-emits over the native tool events.
        $response = new AgentResponse(success: true, message: 'Done', metadata: ['strategy' => 'rag']);

        $job = new class (0, '', '', null) extends RunAgentJob {
            public function emitToolEvents(AgentRunEventStreamService $e, AIAgentRun $r, AIAgentRunStep $s, AgentResponse $resp): void
            {
                $this->emitAiNativeToolEvents($e, $r, $s, $resp, []);
            }
        };

        $job->emitToolEvents(app(AgentRunEventStreamService::class), $run, $step, $response);

        $names = collect(app(AgentRunEventStreamService::class)->fallbackEvents($run))->pluck('name')->all();
        $this->assertNotContains(AgentRunEventStreamService::TOOL_STARTED, $names);
        $this->assertNotContains(AgentRunEventStreamService::TOOL_COMPLETED, $names);
    }
}
