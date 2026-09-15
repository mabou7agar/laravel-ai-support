<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AgentTaskStateService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationPresenter;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseFactory;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeStateStore;
use LaravelAIEngine\Services\Agent\AiNative\ToolOutcomeNormalizer;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * A task's working draft and skill scope must end when the task ends, so the next request
 * in the same conversation starts clean.
 */
class AiNativeTaskLifecycleTest extends UnitTestCase
{
    private function taskState(): AgentTaskStateService
    {
        return new AgentTaskStateService(new ToolOutcomeNormalizer());
    }

    private function draftState(string $objective): array
    {
        return [
            'task_frame' => [
                'active_objective' => $objective,
                'status' => 'confirming',
                'current_payload' => ['invoice_type' => 'sales', 'party_name' => 'First Customer'],
                'current_payload_source' => 'tool_call',
            ],
        ];
    }

    public function test_completed_write_clears_the_working_draft_when_skill_id_names_the_same_entity(): void
    {
        $state = $this->draftState('invoice_create');

        $this->taskState()->recordToolResult(
            $state,
            'create_invoice',
            ['party_name' => 'First Customer'],
            ActionResult::success('Created.', ['invoice_id' => 1, 'draft' => ['payload' => ['party_name' => 'First Customer']]]),
            true
        );

        $this->assertSame('completed', $state['task_frame']['status']);
        $this->assertArrayNotHasKey('current_payload', $state['task_frame'], 'The written draft must not seed the next request.');

        // The next draft for the same skill starts from scratch.
        $this->taskState()->rememberCurrentPayload($state, ['invoice_type' => 'purchase', 'vendor_name' => 'Acme'], 'tool_call');
        $this->assertSame(['invoice_type' => 'purchase', 'vendor_name' => 'Acme'], $state['task_frame']['current_payload']);
    }

    public function test_plural_objective_also_matches_its_tool(): void
    {
        $state = $this->draftState('invoices');

        $this->taskState()->recordToolResult($state, 'create_invoice', [], ActionResult::success('Created.'), true);

        $this->assertSame('completed', $state['task_frame']['status']);
        $this->assertArrayNotHasKey('current_payload', $state['task_frame']);
    }

    public function test_write_for_a_different_entity_keeps_the_parent_draft(): void
    {
        $state = $this->draftState('invoice_create');

        // A supporting write (e.g. creating the customer first) is not the invoice itself.
        $this->taskState()->recordToolResult($state, 'create_customer', ['name' => 'First Customer'], ActionResult::success('Customer created.'), true);

        $this->assertSame('working', $state['task_frame']['status']);
        $this->assertSame('First Customer', $state['task_frame']['current_payload']['party_name']);
    }

    public function test_successful_write_drops_reads_that_predate_it(): void
    {
        $state = ['task_frame' => ['active_objective' => 'invoice_create', 'status' => 'confirming']];
        $tasks = $this->taskState();

        $state['tool_results'][] = ['tool' => 'data_query', 'result' => ['message' => 'You have 3 sales invoices.']];
        $tasks->recordToolResult($state, 'data_query', ['query' => 'count invoices'], ActionResult::success('You have 3 sales invoices.', ['count' => 3]));
        $state['outcomes_at_turn_start'] = count($state['recent_outcomes']);

        $state['tool_results'][] = ['tool' => 'create_invoice', 'result' => ['message' => 'Created.']];
        $tasks->recordToolResult($state, 'create_invoice', ['party_name' => 'Acme'], ActionResult::success('Created.'), true);

        $this->assertSame(['create_invoice'], array_column($state['tool_results'], 'tool'));
        $this->assertSame(['create_invoice'], array_column($state['recent_outcomes'], 'tool'));
        $this->assertSame(['create_invoice'], array_column($state['task_frame']['recent_outcomes'], 'tool'));
        $this->assertSame(0, $state['outcomes_at_turn_start']);
    }

    public function test_entity_lookups_survive_the_write_as_tool_evidence(): void
    {
        $state = ['task_frame' => ['active_objective' => 'invoice_create', 'status' => 'confirming']];
        $tasks = $this->taskState();

        $state['tool_results'][] = ['tool' => 'lookup_customer', 'result' => ['data' => ['id' => 501]]];
        $tasks->recordToolResult($state, 'lookup_customer', ['query' => 'Ahmed'], ActionResult::success('Customer found.', ['id' => 501, 'name' => 'Ahmed']), false);
        $state['tool_results'][] = ['tool' => 'create_invoice', 'result' => ['message' => 'Created.']];
        $tasks->recordToolResult($state, 'create_invoice', ['customer_id' => 501], ActionResult::success('Created.'), true);

        $this->assertContains('lookup_customer', array_column($state['tool_results'], 'tool'));
        $this->assertContains('lookup_customer', array_column($state['recent_outcomes'], 'tool'));
    }

    public function test_a_finished_write_does_not_satisfy_the_next_task_for_the_same_tool(): void
    {
        $state = $this->draftState('invoice_create');
        $state['tool_results'][] = ['tool' => 'create_invoice', 'result' => ['success' => true]];
        $this->taskState()->recordToolResult($state, 'create_invoice', ['party_name' => 'First Customer'], ActionResult::success('Created.'), true);

        $policy = new \LaravelAIEngine\Services\Agent\AiNative\AiNativeFinalToolPolicy(
            \Mockery::mock(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            \Mockery::mock(\LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher::class),
            \Mockery::mock(\LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationIntent::class)
        );

        // Same turn, task still marked completed: the write counts, so the turn can finish.
        $this->assertTrue($policy->hasSuccessfulToolResult($state, 'create_invoice'));

        // The next request starts a new task for the same skill: that write is not its own.
        $state['task_frame'] = ['active_objective' => 'invoice_create', 'status' => 'working'];
        $this->assertFalse($policy->hasSuccessfulToolResult($state, 'create_invoice'));

        $state['tool_results'][] = ['tool' => 'create_invoice', 'result' => ['success' => true]];
        $this->assertTrue($policy->hasSuccessfulToolResult($state, 'create_invoice'));
    }

    private function responses(): AiNativeResponseFactory
    {
        return new AiNativeResponseFactory(new AiNativeStateStore(), new ToolRegistry(), new AiNativeConfirmationPresenter());
    }

    public function test_final_answer_completes_a_task_with_nothing_pending(): void
    {
        $context = new UnifiedActionContext(sessionId: 'lifecycle');
        $state = ['task_frame' => ['active_objective' => 'module_sales', 'status' => 'working']];

        $this->responses()->final($context, $state, 'You have 3 sales invoices.');

        $this->assertSame('completed', $context->metadata['ai_native']['task_frame']['status']);
    }

    public function test_auto_finalized_tool_answer_also_completes_the_task(): void
    {
        $context = new UnifiedActionContext(sessionId: 'lifecycle-tool-completed');
        $state = ['task_frame' => ['active_objective' => 'module_account', 'status' => 'working']];

        $this->responses()->toolCompleted($context, $state, 'data_query', \LaravelAIEngine\DTOs\ActionResult::success('You have 1 customer.'));

        $this->assertSame('completed', $context->metadata['ai_native']['task_frame']['status']);
    }

    public function test_final_answer_keeps_a_task_that_is_still_collecting_or_confirming(): void
    {
        $collecting = new UnifiedActionContext(sessionId: 'lifecycle-collecting');
        $this->responses()->final($collecting, ['task_frame' => ['active_objective' => 'invoice_create', 'status' => 'collecting']], 'Which customer?');
        $this->assertSame('collecting', $collecting->metadata['ai_native']['task_frame']['status']);

        $drafting = new UnifiedActionContext(sessionId: 'lifecycle-draft');
        $this->responses()->final($drafting, ['task_frame' => ['active_objective' => 'invoice_create', 'status' => 'working', 'current_payload' => ['party_name' => 'Acme']]], 'Anything else?');
        $this->assertSame('working', $drafting->metadata['ai_native']['task_frame']['status']);

        $pending = new UnifiedActionContext(sessionId: 'lifecycle-pending');
        $this->responses()->final($pending, ['pending_tool' => ['name' => 'create_invoice'], 'task_frame' => ['active_objective' => 'invoice_create', 'status' => 'confirming']], 'Confirm?');
        $this->assertSame('confirming', $pending->metadata['ai_native']['task_frame']['status']);
    }
}
