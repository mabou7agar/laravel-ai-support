<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Jobs\ContinueAgentRunJob;
use LaravelAIEngine\Jobs\RunAgentJob;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Repositories\AgentRunRepository;
use LaravelAIEngine\Repositories\AgentRunStepRepository;
use LaravelAIEngine\Repositories\ProviderToolApprovalRepository;
use LaravelAIEngine\Services\Agent\AgentRunApprovalService;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;
use LaravelAIEngine\Services\Agent\AgentRunRuntimeControlService;
use LaravelAIEngine\Services\Agent\AgentRunSafetyService;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiGateway;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiInvocation;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiTurn;
use LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService;
use LaravelAIEngine\Tests\Support\Stubs\FakeLaravelAiGateway;
use LaravelAIEngine\Tests\TestCase;

/**
 * Step 3 of the staged laravel/ai adoption: the `laravel_ai` runtime run
 * through the real RunAgentJob / ContinueAgentRunJob pipeline must keep every
 * guarantee the built-in runtime gives — streaming, approval resume, recovery,
 * cancellation, tenant scope and usage accounting.
 *
 * The SDK side is replaced by a deterministic gateway so this suite runs on
 * every supported PHP/Laravel version; LaravelAiSdkGatewayTest covers the
 * gateway against the real SDK where laravel/ai is installed.
 */
class LaravelAiRuntimeParityTest extends TestCase
{
    private FakeLaravelAiGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeLaravelAiGateway();
        $this->app->instance(LaravelAiGateway::class, $this->gateway);

        config()->set('ai-agent.runtime.drivers', ['laravel_ai' => LaravelAiAgentRuntime::class]);
        config()->set('ai-agent.runtime.default', 'laravel_ai');
    }

    public function test_streamed_turn_mirrors_events_and_persists_completed_run_with_usage(): void
    {
        $this->gateway->push($this->turn('Order 42 has shipped.', [
            'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
            'conversationId' => 'conv-1',
            'toolCalls' => [['id' => 'call-1', 'name' => 'lookup_order', 'arguments' => ['order' => 42]]],
            'toolResults' => [['id' => 'call-1', 'name' => 'lookup_order', 'result' => 'shipped', 'successful' => true, 'denied' => false]],
        ]));

        $run = $this->createRun('stream-session', ['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a']);
        $this->handle(new RunAgentJob($run->id, 'Where is order 42?', 'stream-session', '9', [
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
            'idempotency_key' => 'stream-1',
        ]));

        $run->refresh();
        $step = $run->steps()->first();
        $invocation = $this->gateway->invocations[0];

        // Scope and run identifiers reach the SDK seam.
        $this->assertTrue($invocation->stream);
        $this->assertSame(['tenant_id' => 'tenant-a', 'workspace_id' => 'workspace-a'], $invocation->scope);
        $this->assertSame($run->id, $invocation->run['agent_run_id']);
        $this->assertSame($step->id, $invocation->run['agent_run_step_id']);
        $this->assertNull($invocation->conversationId);
        $this->assertFalse($invocation->isResume());

        // Completion and attribution.
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('laravel_ai', $run->runtime);
        $this->assertSame('laravel_ai', $step->source);
        $this->assertSame('Order 42 has shipped.', $run->final_response['message']);
        $this->assertSame('conv-1', $run->final_response['metadata']['laravel_ai']['conversation_id']);

        // Usage lands in the run budget.
        $this->assertSame(20, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(1, $run->metadata['budget']['usage']['requests']);
        $this->assertSame('gpt-test', $run->metadata['provider_model'] ?? $run->metadata['budget']['last_usage']['provider_model']);

        // Streaming: tool and token events are mirrored in order, between start and terminal.
        $names = $this->eventNames($run);
        $this->assertSame([
            AgentRunEventStreamService::RUN_STARTED,
            AgentRunEventStreamService::TOOL_STARTED,
            AgentRunEventStreamService::TOOL_COMPLETED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED,
            AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED,
            AgentRunEventStreamService::RUN_COMPLETED,
        ], $names);

        $events = collect($run->metadata['events']);
        $tokens = $events->where('name', AgentRunEventStreamService::FINAL_RESPONSE_TOKEN_STREAMED)->pluck('payload.token')->implode('');
        $this->assertSame('Order 42 has shipped.', $tokens);
        $this->assertSame('lookup_order', $events->firstWhere('name', AgentRunEventStreamService::TOOL_STARTED)['payload']['tool_name']);
        $this->assertSame('laravel_ai', $events->firstWhere('name', AgentRunEventStreamService::TOOL_STARTED)['metadata']['runtime']);
        $this->assertSame(4, $events->firstWhere('name', AgentRunEventStreamService::FINAL_RESPONSE_STREAM_COMPLETED)['payload']['token_count']);
    }

    public function test_next_turn_in_the_same_session_continues_the_sdk_conversation(): void
    {
        $this->gateway
            ->push($this->turn('Hi Sam.', ['conversationId' => 'conv-7']))
            ->push($this->turn('You said your name is Sam.', ['conversationId' => 'conv-7']));

        $first = $this->createRun('memory-session');
        $this->handle(new RunAgentJob($first->id, 'I am Sam', 'memory-session', '9'));

        $second = $this->createRun('memory-session');
        $this->handle(new RunAgentJob($second->id, 'What is my name?', 'memory-session', '9'));

        $this->assertNull($this->gateway->invocations[0]->conversationId);
        $this->assertSame('conv-7', $this->gateway->invocations[1]->conversationId);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $second->refresh()->status);
    }

    public function test_tool_approval_pauses_the_run_and_resume_replays_the_decision_into_the_sdk(): void
    {
        $this->gateway
            ->push($this->turn('', [
                'usage' => ['input_tokens' => 5, 'output_tokens' => 3],
                'conversationId' => 'conv-9',
                'pendingApprovals' => [['id' => 'call-9', 'tool' => 'refund_order', 'arguments' => ['order' => 42], 'reason' => 'Refunds need approval.']],
            ]))
            ->push($this->turn('Refund issued.', [
                'usage' => ['input_tokens' => 7, 'output_tokens' => 4],
                'conversationId' => 'conv-9',
            ]));

        $run = $this->createRun('approval-session');
        $this->handle(new RunAgentJob($run->id, 'Refund order 42', 'approval-session', '9', ['idempotency_key' => 'approval-1']));

        $run->refresh();
        $step = $run->steps()->first();
        $approvalKey = $run->final_response['data']['approval_key'] ?? null;

        // Paused, not completed.
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->status);
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $step->status);
        $this->assertNull($run->completed_at);
        $this->assertIsString($approvalKey);
        $approval = app(ProviderToolApprovalRepository::class)->findByKeyOrFail($approvalKey);
        $this->assertSame('pending', $approval->status);
        $this->assertSame('refund_order', $approval->tool_name);
        $this->assertSame(['call-9'], $approval->metadata['resume_payload']['laravel_ai_pending_approvals']);
        $this->assertSame('conv-9', $approval->metadata['resume_payload']['laravel_ai_conversation_id']);

        $names = $this->eventNames($run);
        $this->assertContains(AgentRunEventStreamService::APPROVAL_REQUIRED, $names);
        $this->assertContains(AgentRunEventStreamService::RUN_WAITING_APPROVAL, $names);
        $this->assertNotContains(AgentRunEventStreamService::RUN_COMPLETED, $names);

        // Approve and resume through the package's own control surface.
        app(AgentRunRuntimeControlService::class)->resume($run, ['approval_key' => $approvalKey, 'actor_id' => 'admin-1']);

        $run->refresh();
        $resume = $this->gateway->invocations[1];

        $this->assertTrue($resume->isResume());
        $this->assertSame(['call-9' => true], $resume->decisions);
        $this->assertSame('conv-9', $resume->conversationId);
        $this->assertSame('approved', $approval->refresh()->status);

        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('laravel_ai', $run->runtime);
        $this->assertSame('Refund issued.', $run->final_response['message']);
        $this->assertSame(19, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(2, $run->metadata['budget']['usage']['requests']);
        $this->assertSame(1, collect($this->eventNames($run))->filter(fn (string $name): bool => $name === AgentRunEventStreamService::RUN_COMPLETED)->count());
    }

    public function test_rejected_approval_fails_the_run_without_calling_the_sdk_again(): void
    {
        $this->gateway->push($this->turn('', [
            'conversationId' => 'conv-r',
            'pendingApprovals' => [['id' => 'call-r', 'tool' => 'delete_account', 'arguments' => [], 'reason' => null]],
        ]));

        $run = $this->createRun('reject-session');
        $this->handle(new RunAgentJob($run->id, 'Delete my account', 'reject-session', '9'));

        $approvalKey = $run->refresh()->final_response['data']['approval_key'];
        app(AgentRunApprovalService::class)->rejectStep($approvalKey, 'admin-1', 'Not allowed.');

        $this->assertSame(AIAgentRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertSame('Not allowed.', $run->failure_reason);
        $this->assertCount(1, $this->gateway->invocations);
    }

    public function test_a_rejected_approval_is_replayed_into_the_sdk_as_a_denial(): void
    {
        $this->gateway
            ->push($this->turn('', [
                'conversationId' => 'conv-d',
                'pendingApprovals' => [['id' => 'call-d', 'tool' => 'drop_table', 'arguments' => [], 'reason' => null]],
            ]))
            ->push($this->turn('Understood, I will not drop the table.', ['conversationId' => 'conv-d']));

        $run = $this->createRun('deny-session');
        $this->handle(new RunAgentJob($run->id, 'Drop the users table', 'deny-session', '9'));
        $approvalKey = $run->refresh()->final_response['data']['approval_key'];

        // Rejected at the approval level, then continued so the model can respond to the denial.
        app(ProviderToolApprovalService::class)->reject($approvalKey, 'admin-1', 'Too dangerous.');
        $this->handle(new ContinueAgentRunJob($run->id, 'continue', [
            'agent_runtime' => 'laravel_ai',
            'resume_payload' => ['approval_key' => $approvalKey],
        ]));

        $this->assertSame(['call-d' => false], $this->gateway->invocations[1]->decisions);
        $this->assertSame('conv-d', $this->gateway->invocations[1]->conversationId);
        $this->assertSame('Understood, I will not drop the table.', $run->refresh()->final_response['message']);
    }

    public function test_cancelled_run_is_not_resurrected_by_a_queued_job(): void
    {
        $this->gateway->push($this->turn('Should never run.'));

        $run = $this->createRun('cancel-before');
        app(AgentRunRuntimeControlService::class)->cancel($run, ['reason' => 'User closed the chat.']);

        $this->handle(new RunAgentJob($run->id, 'hello', 'cancel-before', '9'));

        $run->refresh();
        $names = $this->eventNames($run);

        $this->assertSame([], $this->gateway->invocations);
        $this->assertSame(AIAgentRun::STATUS_CANCELLED, $run->status);
        $this->assertNotContains(AgentRunEventStreamService::RUN_STARTED, $names);
        $this->assertSame([AgentRunEventStreamService::RUN_CANCELLED], $names);
    }

    public function test_cancelling_mid_stream_stops_consuming_the_sdk_stream_and_keeps_the_cancellation(): void
    {
        config()->set('ai-agent.runtime.laravel_ai.cancellation_check_every', 1);

        $this->gateway->push($this->turn('one two three four five six', [
            'usage' => ['input_tokens' => 9, 'output_tokens' => 2],
        ]));

        $run = $this->createRun('cancel-mid');
        $this->gateway->beforeEvent = function (int $position, array $event) use ($run): void {
            // Cancel from "another request" once two words have streamed.
            if ($position === 3) {
                app(AgentRunRuntimeControlService::class)->cancel($run->fresh(), ['reason' => 'Stop.']);
            }
        };

        $this->handle(new RunAgentJob($run->id, 'count', 'cancel-mid', '9', ['idempotency_key' => 'cancel-mid-1']));

        $run->refresh();
        $step = $run->steps()->where('step_key', 'run')->first();
        $names = $this->eventNames($run);

        // The SDK stream was abandoned well before its 8 events were consumed.
        $this->assertSame(4, $this->gateway->consumedEvents[0]);
        $this->assertSame(AIAgentRun::STATUS_CANCELLED, $run->status);
        $this->assertSame(AIAgentRun::STATUS_CANCELLED, $step->status);
        $this->assertTrue($step->metadata['stopped_during_runtime']);
        $this->assertTrue($step->output['metadata']['agent_run_cancelled']);
        $this->assertNull($run->final_response);
        $this->assertNotContains(AgentRunEventStreamService::RUN_COMPLETED, $names);
        $this->assertSame(1, collect($names)->filter(fn (string $name): bool => $name === AgentRunEventStreamService::RUN_CANCELLED)->count());
        // Tokens already spent are still accounted for.
        $this->assertSame(11, $run->metadata['budget']['usage']['tokens']);
    }

    public function test_provider_failure_fails_the_run_and_a_retry_recovers_without_double_counting(): void
    {
        $this->gateway
            ->push(new \RuntimeException('Provider overloaded.'))
            ->push($this->turn('Recovered.', ['usage' => ['input_tokens' => 4, 'output_tokens' => 4]]));

        $run = $this->createRun('recovery-session');
        $job = new RunAgentJob($run->id, 'try', 'recovery-session', '9', ['idempotency_key' => 'recovery-1']);

        try {
            $this->handle($job);
            $this->fail('Expected the provider failure to propagate for a queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Provider overloaded.', $e->getMessage());
        }

        $this->assertSame(AIAgentRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertContains(AgentRunEventStreamService::RUN_FAILED, $this->eventNames($run));

        // The queue retries the same job; the idempotency claim was released.
        $this->handle($job);

        $run->refresh();
        $this->assertCount(2, $this->gateway->invocations);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('Recovered.', $run->final_response['message']);
        $this->assertSame(8, $run->metadata['budget']['usage']['tokens']);
        $this->assertSame(1, $run->metadata['budget']['usage']['requests']);
        $this->assertSame(1, collect($this->eventNames($run))->filter(fn (string $name): bool => $name === AgentRunEventStreamService::RUN_COMPLETED)->count());
    }

    public function test_tenant_scope_mismatch_is_rejected_before_the_sdk_is_called(): void
    {
        $this->gateway->push($this->turn('Should never run.'));

        $run = $this->createRun('scope-session', ['tenant_id' => 'tenant-a']);

        try {
            $this->handle(new RunAgentJob($run->id, 'leak?', 'scope-session', '9', ['tenant_id' => 'tenant-b']));
            $this->fail('Expected the scope mismatch to be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tenant scope does not match', $e->getMessage());
        }

        $this->assertSame([], $this->gateway->invocations);
    }

    public function test_per_request_selection_is_attributed_to_laravel_ai_and_resumes_there(): void
    {
        config()->set('ai-agent.runtime.default', 'laravel');

        $this->gateway
            ->push($this->turn('', [
                'conversationId' => 'conv-p',
                'pendingApprovals' => [['id' => 'call-p', 'tool' => 'send_invoice', 'arguments' => [], 'reason' => null]],
            ]))
            ->push($this->turn('Invoice sent.', ['conversationId' => 'conv-p']));

        $run = $this->createRun('per-request');
        $this->handle(new RunAgentJob($run->id, 'Send the invoice', 'per-request', '9', ['agent_runtime' => 'laravel_ai']));

        $run->refresh();
        $this->assertSame('laravel_ai', $run->runtime);
        $this->assertSame(AIAgentRun::STATUS_WAITING_APPROVAL, $run->status);

        app(AgentRunRuntimeControlService::class)->resume($run, [
            'approval_key' => $run->final_response['data']['approval_key'],
        ]);

        $this->assertCount(2, $this->gateway->invocations);
        $this->assertSame(['call-p' => true], $this->gateway->invocations[1]->decisions);
        $this->assertSame('Invoice sent.', $run->refresh()->final_response['message']);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $run->status);
    }

    public function test_resume_ignores_an_approval_that_belongs_to_another_run(): void
    {
        $this->gateway
            ->push($this->turn('', [
                'conversationId' => 'conv-victim',
                'pendingApprovals' => [['id' => 'call-v', 'tool' => 'wire_money', 'arguments' => [], 'reason' => null]],
            ]))
            ->push(fn (LaravelAiInvocation $invocation): LaravelAiTurn => $this->turn('Plain answer.'));

        $victim = $this->createRun('victim-session');
        $this->handle(new RunAgentJob($victim->id, 'Wire money', 'victim-session', '9'));
        $foreignKey = $victim->refresh()->final_response['data']['approval_key'];
        // Even an approved approval must not be replayable from another run.
        app(AgentRunApprovalService::class)->approveStep($foreignKey, 'admin-1');

        $attacker = $this->createRun('attacker-session');
        $this->handle(new RunAgentJob($attacker->id, 'hello', 'attacker-session', '9', [
            'resume_payload' => ['approval_key' => $foreignKey],
        ]));

        $invocation = $this->gateway->invocations[1];
        $this->assertNull($invocation->decisions);
        $this->assertNotSame('conv-victim', $invocation->conversationId);
        $this->assertSame(AIAgentRun::STATUS_COMPLETED, $attacker->refresh()->status);
    }

    private function turn(string $text, array $overrides = []): LaravelAiTurn
    {
        return new LaravelAiTurn(
            text: $text,
            usage: $overrides['usage'] ?? ['input_tokens' => 1, 'output_tokens' => 1],
            invocationId: $overrides['invocationId'] ?? 'inv-' . md5($text),
            conversationId: $overrides['conversationId'] ?? null,
            provider: 'openai',
            model: 'gpt-test',
            toolCalls: $overrides['toolCalls'] ?? [],
            toolResults: $overrides['toolResults'] ?? [],
            pendingApprovals: $overrides['pendingApprovals'] ?? []
        );
    }

    private function createRun(string $sessionId, array $attributes = []): AIAgentRun
    {
        return app(AgentRunRepository::class)->create(array_merge([
            'session_id' => $sessionId,
            'user_id' => '9',
            'status' => AIAgentRun::STATUS_PENDING,
        ], $attributes));
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

    /**
     * @return array<int, string>
     */
    private function eventNames(AIAgentRun $run): array
    {
        return collect($run->refresh()->metadata['events'] ?? [])->pluck('name')->values()->all();
    }
}
