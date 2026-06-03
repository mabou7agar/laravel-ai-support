<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeResponseFactory
{
    public function __construct(
        private readonly AiNativeStateStore $stateStore,
        private readonly ToolRegistry $tools,
        private readonly AiNativeConfirmationPresenter $confirmationPresenter
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $data
     */
    public function final(UnifiedActionContext $context, array $state, string $message, array $data = []): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, $message, $this->stateStore->redactedArray($data));
    }

    /**
     * @param array<string, mixed> $state
     */
    public function alreadyCompleted(UnifiedActionContext $context, array $state): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, 'That action has already been completed.', [
            'already_completed' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function nonActionContext(UnifiedActionContext $context, array $state): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success($context, $state, 'I noted that context. Tell me what you want to do with it next.', [
            'awaiting_action_request' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function toolCompleted(UnifiedActionContext $context, array $state, string $toolName, ActionResult $result): AgentResponse
    {
        $this->stateStore->put($context, $state);

        return $this->success(
            $context,
            $state,
            $result->message ?? str_replace('_', ' ', $toolName).' completed.',
            is_array($result->data) ? $this->stateStore->redactedArray($result->data) : []
        );
    }

    /**
     * @param array<string, mixed> $state
     * @param array<int, mixed> $requiredInputs
     * @param array<string, mixed> $data
     */
    public function needsUserInput(UnifiedActionContext $context, array $state, string $message, array $requiredInputs = [], array $data = []): AgentResponse
    {
        $response = AgentResponse::needsUserInput(
            message: $message,
            data: $this->stateStore->redactedArray(array_replace(['ai_native' => $state], $data)),
            context: $context,
            requiredInputs: $requiredInputs === [] ? null : $requiredInputs
        );
        $response->strategy = 'ai_native';
        $response->metadata = ['ai_native' => $this->stateStore->redactedState($state)];
        $this->attachReasoningTrace($response, $state);
        $this->attachPlanTimeline($response, $state);

        return $response;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $params
     */
    public function confirmation(UnifiedActionContext $context, array $state, string $toolName, array $params, string $message, ?array $summary = null): AgentResponse
    {
        $tool = $this->tools->get($toolName);
        $message = $this->withPendingChangeNotice(
            $state,
            $this->confirmationPresenter->confirmationMessage($tool, $toolName, $params, $message, $summary)
        );

        return $this->needsUserInput($context, $state, $message, [[
            'name' => 'confirmation',
            'type' => 'select',
            'label' => 'Confirmation',
            'required' => true,
            'options' => [
                ['value' => 'confirm', 'label' => 'Confirm'],
                ['value' => 'change', 'label' => 'Change'],
            ],
        ]], [
            'pending_tool' => [
                'name' => $toolName,
                'params' => $params,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function withPendingChangeNotice(array $state, string $message): string
    {
        if (!$this->hasRuntimeFeedback($state, 'pending_confirmation_changed_by_user')) {
            return $message;
        }

        $notice = trim((string) config(
            'ai-agent.ai_native.confirmation_summary.changed_draft_notice',
            ''
        ));

        if ($notice === '' || str_starts_with($message, $notice)) {
            return $message;
        }

        return $notice."\n\n".$message;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasRuntimeFeedback(array $state, string $reason): bool
    {
        foreach ((array) ($state['runtime_feedback'] ?? []) as $feedback) {
            if (is_array($feedback) && ($feedback['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $validation
     * @return array<int, array<string, mixed>>
     */
    public function requiredInputsFromValidation(array $validation): array
    {
        return array_map(static fn (string $error): array => [
            'name' => $error,
            'type' => 'text',
            'required' => true,
        ], $validation);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $data
     */
    private function success(UnifiedActionContext $context, array $state, string $message, array $data): AgentResponse
    {
        $response = AgentResponse::success(
            message: $message,
            data: $data,
            context: $context
        );
        $response->strategy = 'ai_native';
        $response->metadata = ['ai_native' => $this->stateStore->redactedState($state)];
        $this->attachReasoningTrace($response, $state);
        $this->attachPlanTimeline($response, $state);

        return $response;
    }

    /**
     * Copy any accumulated per-turn planner rationale onto the returned response as
     * a top-level metadata['reasoning_trace'][] (alongside metadata['ai_native']).
     * No-op when the trace is absent (expose_reasoning OFF), preserving today's
     * metadata byte-for-byte.
     *
     * @param array<string, mixed> $state
     */
    private function attachReasoningTrace(AgentResponse $response, array $state): void
    {
        if (empty($state['reasoning_trace'])) {
            return;
        }

        $trace = array_values(array_filter(
            (array) $state['reasoning_trace'],
            static fn (mixed $entry): bool => is_string($entry) && trim($entry) !== ''
        ));

        if ($trace === []) {
            return;
        }

        $response->metadata['reasoning_trace'] = $trace;
    }

    /**
     * Copy the per-turn live plan snapshot onto the returned response as a
     * top-level metadata['plan'] = {steps, current} (alongside metadata['ai_native']).
     * No-op when the snapshot is absent or has no steps (plan_timeline OFF),
     * preserving today's metadata byte-for-byte.
     *
     * @param array<string, mixed> $state
     */
    private function attachPlanTimeline(AgentResponse $response, array $state): void
    {
        $snapshot = $state['plan_timeline'] ?? null;
        if (!is_array($snapshot)) {
            return;
        }

        $steps = array_values(array_filter(
            (array) ($snapshot['steps'] ?? []),
            static fn (mixed $step): bool => is_string($step) && trim($step) !== ''
        ));

        if ($steps === []) {
            return;
        }

        $current = (int) ($snapshot['current'] ?? 1);
        $current = max(1, min($current, count($steps)));

        $response->metadata['plan'] = ['steps' => $steps, 'current' => $current];
    }
}
