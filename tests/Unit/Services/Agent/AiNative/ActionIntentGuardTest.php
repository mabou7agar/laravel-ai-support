<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\ActionIntentGuard;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Tests\UnitTestCase;

class ActionIntentGuardTest extends UnitTestCase
{
    public function test_background_context_gates_required_input_and_write_tool_plans(): void
    {
        config()->set('ai-agent.ai_native.action_intent_terms', ['create', 'update']);

        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        $this->assertTrue($guard->shouldGatePlan(
            'Ahmed wants two laptops next month.',
            [],
            [],
            [
                'action' => 'ask_user',
                'required_inputs' => ['customer_email'],
            ],
            null,
            false
        ));

        $this->assertTrue($guard->shouldGatePlan(
            'Ahmed wants two laptops next month.',
            [],
            [],
            ['action' => 'tool_call'],
            new ActionIntentWriteTool(),
            false
        ));
    }

    public function test_explicit_action_intent_and_active_state_can_continue_actions(): void
    {
        config()->set('ai-agent.ai_native.action_intent_terms', ['create']);

        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        $this->assertFalse($guard->shouldGatePlan(
            'Create an invoice for Ahmed.',
            [],
            [],
            [
                'action' => 'ask_user',
                'required_inputs' => ['items'],
            ],
            null,
            false
        ));

        $this->assertFalse($guard->shouldGatePlan(
            'Ahmed should use five laptops instead.',
            [
                'task_frame' => [
                    'active_objective' => 'create_invoice',
                    'status' => 'working',
                ],
            ],
            [],
            ['action' => 'tool_call'],
            new ActionIntentWriteTool(),
            false
        ));
    }

    public function test_completed_tool_history_does_not_make_new_background_context_actionable(): void
    {
        config()->set('ai-agent.ai_native.action_intent_terms', ['create']);

        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        $this->assertTrue($guard->shouldGatePlan(
            'Ahmed may need more laptops next month.',
            [
                'tool_results' => [
                    ['tool' => 'create_invoice', 'result' => ['success' => true]],
                ],
                'task_frame' => [
                    'status' => 'completed',
                    'current_payload' => ['invoice_number' => 'INV-1'],
                ],
            ],
            [],
            ['action' => 'tool_call'],
            new ActionIntentWriteTool(),
            false
        ));
    }

    public function test_non_action_feedback_is_reusable_runtime_feedback(): void
    {
        $this->assertSame(
            'latest_message_not_action_request',
            (new ActionIntentGuard(app(IntentSignalService::class)))->nonActionFeedback()['reason']
        );
    }

    public function test_read_intent_guard_is_off_by_default(): void
    {
        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        // Even a clear read message + write tool is not blocked unless the guard is enabled.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'search for the invoice for Mohamed',
            ['task_frame' => ['status' => 'completed']],
            [],
            new ActionIntentWriteTool()
        ));
    }

    public function test_read_intent_blocks_a_write_tool_when_enabled(): void
    {
        config()->set('ai-agent.ai_native.guard_read_intent_writes', true);
        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        $this->assertTrue($guard->readIntentBlocksWrite(
            'search for the invoice for Mohamed',
            ['task_frame' => ['status' => 'completed', 'current_payload' => ['invoice_number' => 'INV-1']]],
            [],
            new ActionIntentWriteTool()
        ));
        $this->assertSame('read_intent_no_write', $guard->readIntentFeedback()['reason']);
    }

    public function test_read_intent_does_not_block_a_read_tool(): void
    {
        config()->set('ai-agent.ai_native.guard_read_intent_writes', true);
        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        $this->assertFalse($guard->readIntentBlocksWrite(
            'search for the invoice for Mohamed',
            ['task_frame' => ['status' => 'completed']],
            [],
            new ActionIntentReadTool()
        ));
    }

    public function test_message_with_write_intent_is_not_blocked(): void
    {
        config()->set('ai-agent.ai_native.guard_read_intent_writes', true);
        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        // Pure write request.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'create the invoice',
            ['task_frame' => ['status' => 'completed']],
            [],
            new ActionIntentWriteTool()
        ));

        // Mixed read+write ("show ... and create ...") fails open — write intent present.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'show me a new invoice for Mohamed',
            ['task_frame' => ['status' => 'completed']],
            [],
            new ActionIntentWriteTool()
        ));
    }

    public function test_active_write_in_progress_is_never_blocked(): void
    {
        config()->set('ai-agent.ai_native.guard_read_intent_writes', true);
        $guard = new ActionIntentGuard(app(IntentSignalService::class));

        // Active (not completed) objective — mid write flow.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'show the draft',
            ['task_frame' => ['status' => 'working', 'active_objective' => 'create_invoice']],
            [],
            new ActionIntentWriteTool()
        ));

        // Pending tool awaiting confirmation.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'show the draft',
            ['pending_tool' => ['name' => 'create_invoice']],
            [],
            new ActionIntentWriteTool()
        ));

        // Explicit skill scope.
        $this->assertFalse($guard->readIntentBlocksWrite(
            'show the draft',
            ['task_frame' => ['status' => 'completed']],
            ['runtime_scope' => 'skill'],
            new ActionIntentWriteTool()
        ));
    }
}

class ActionIntentWriteTool extends AgentTool
{
    public function getName(): string
    {
        return 'create_invoice';
    }

    public function getDescription(): string
    {
        return 'Create invoice.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('done');
    }
}

class ActionIntentReadTool extends AgentTool
{
    public function getName(): string
    {
        return 'show_invoice';
    }

    public function getDescription(): string
    {
        return 'Show invoice.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('done');
    }
}
