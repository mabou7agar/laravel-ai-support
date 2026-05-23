<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeToolCallActionHandler
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ToolResultAuthorityService $authority,
        private readonly AgentTaskStateService $taskState,
        private readonly ActionIntentGuard $actionIntentGuard,
        private readonly AiNativeSkillPolicy $skillPolicy,
        private readonly AiNativeSuggestedToolContinuation $suggestedToolContinuation,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeConfirmationPreviewService $confirmationPreview,
        private readonly AiNativeResponseFactory $responses,
        private readonly AiNativeToolExecutor $toolExecutor
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function handle(
        string $message,
        UnifiedActionContext $context,
        array &$state,
        array $options,
        array $plan
    ): AiNativeActionOutcome {
        $toolName = trim((string) ($plan['tool'] ?? $plan['tool_name'] ?? ''));
        $tool = $toolName !== '' ? $this->tools->get($toolName) : null;
        if (!$tool instanceof AgentTool) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, "Tool {$toolName} is not available."));
        }

        if ($this->shouldGateNewActionPlan($message, $state, $options, $plan, $tool)) {
            if ($this->skillPolicy->hasRuntimeFeedback($state, 'latest_message_not_action_request')) {
                return AiNativeActionOutcome::response($this->responses->nonActionContext($context, $state));
            }

            $state['runtime_feedback'][] = $this->actionIntentGuard->nonActionFeedback();
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        $arguments = $this->authority->sanitizeArguments((array) ($plan['arguments'] ?? $plan['tool_params'] ?? []), $state);
        $isConfirmedSuggestedWrite = $tool->requiresConfirmation()
            && $this->suggestedToolContinuation->writeContinuationIsConfirmed($toolName, $state, $arguments);

        if ($tool->requiresConfirmation() && $this->shouldRememberConfirmingToolPayload($message, $state, $options, $toolName)) {
            $this->taskState->rememberCurrentPayload($state, $arguments, 'tool_call');
        }

        if ($tool->requiresConfirmation()) {
            if (!$isConfirmedSuggestedWrite && $this->skillPolicy->relationCreateNeedsLookupMiss($toolName, $arguments, $state, $options)) {
                $state['runtime_feedback'][] = [
                    'reason' => 'relation_write_without_lookup_miss',
                    'message' => 'This write tool creates a related record for the active skill. Call the relation lookup tool for the same record first, and only use the create tool after that lookup returns not found.',
                    'write_tool' => $toolName,
                ];
                $this->stateStore->put($context, $state);

                return AiNativeActionOutcome::continueLoop();
            }

            if (!$isConfirmedSuggestedWrite && $this->skillPolicy->needsLookupBeforeWrite($toolName, $arguments, $state, $options)) {
                $state['runtime_feedback'][] = [
                    'reason' => 'write_without_lookup',
                    'message' => 'A matching lookup/search/find tool exists for this write. Call the lookup tool for the same record first, then create or update only when the lookup result shows it is missing or the user explicitly confirms the write.',
                    'write_tool' => $toolName,
                    'lookup_tools' => $this->skillPolicy->matchingLookupToolsForWrite($toolName, $state, $options),
                ];
                $this->stateStore->put($context, $state);

                return AiNativeActionOutcome::continueLoop();
            }
        }

        $validation = $tool->validate($this->toolExecutor->withConfirmationForValidation($tool, $arguments));
        if ($validation !== []) {
            if ($this->shouldRetryAfterValidationFailure($state, $toolName, $arguments, $validation)) {
                $this->stateStore->put($context, $state);

                return AiNativeActionOutcome::continueLoop();
            }

            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, implode("\n", $validation), $this->responses->requiredInputsFromValidation($validation), [
                'tool_name' => $toolName,
            ]));
        }

        if ($isConfirmedSuggestedWrite) {
            return $this->executeConfirmedSuggestedWrite($tool, $toolName, $arguments, $context, $state);
        }

        if ($tool->requiresConfirmation()) {
            if ($this->taskState->hasCompletedWrite($state, $toolName, $arguments)) {
                return AiNativeActionOutcome::response($this->responses->alreadyCompleted($context, $state));
            }

            $preview = $this->confirmationPreview->preview($tool, $arguments, $context);
            $previewResult = $preview['result'];
            if ($previewResult instanceof ActionResult && (!$previewResult->success || $previewResult->requiresUserInput())) {
                $this->stateStore->put($context, $state);

                return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, $previewResult->message ?? $previewResult->error ?? 'More information is required.', (array) ($previewResult->metadata['required_inputs'] ?? []), [
                    'tool_name' => $toolName,
                    'tool_result' => $previewResult->toArray(),
                ]));
            }

            $arguments = $preview['arguments'];
            $state['pending_tool'] = [
                'name' => $toolName,
                'params' => $arguments,
                'message' => $this->planMessage($plan),
            ];
            $this->taskState->markPendingConfirmation($state, $toolName, $arguments);
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->confirmation($context, $state, $toolName, $arguments, $this->planMessage($plan), $preview['summary']));
        }

        $result = $this->toolExecutor->execute($tool, $arguments, $context);
        $this->toolExecutor->recordResult($state, $toolName, $arguments, $result);

        if ($this->suggestedToolContinuation->shouldContinueFromSuggestedTools($result)) {
            $this->suggestedToolContinuation->mark($state, null, $result);
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($result->requiresUserInput()) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, $result->message ?? $result->error ?? 'More information is required.', (array) ($result->metadata['required_inputs'] ?? []), [
                'tool_name' => $toolName,
                'tool_result' => $result->toArray(),
            ]));
        }

        if (!$result->success && $this->isLookupMiss($toolName, $tool, $result)) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if (!$result->success) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, $result->message ?? $result->error ?? 'Tool execution failed.', [], [
                'tool_name' => $toolName,
                'tool_result' => $result->toArray(),
            ]));
        }

        $this->stateStore->put($context, $state);

        return AiNativeActionOutcome::continueLoop();
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     */
    private function executeConfirmedSuggestedWrite(
        AgentTool $tool,
        string $toolName,
        array $arguments,
        UnifiedActionContext $context,
        array &$state
    ): AiNativeActionOutcome {
        if ($this->taskState->hasCompletedWrite($state, $toolName, $arguments)) {
            return AiNativeActionOutcome::response($this->responses->alreadyCompleted($context, $state));
        }

        $result = $this->toolExecutor->execute($tool, $this->toolExecutor->withConfirmationForValidation($tool, $arguments), $context);
        unset($state['confirmed_write_tools'][$toolName]);
        $this->toolExecutor->recordResult($state, $toolName, $arguments, $result, true);

        if ($this->suggestedToolContinuation->shouldContinueFromSuggestedTools($result)) {
            $this->suggestedToolContinuation->mark($state, $toolName, $result, $arguments);
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($result->requiresUserInput()) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, $result->message ?? $result->error ?? 'More information is required.', (array) ($result->metadata['required_inputs'] ?? []), [
                'tool_name' => $toolName,
                'tool_result' => $result->toArray(),
            ]));
        }

        if (!$result->success) {
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::response($this->responses->needsUserInput($context, $state, $result->message ?? $result->error ?? 'Tool execution failed.', [], [
                'tool_name' => $toolName,
                'tool_result' => $result->toArray(),
            ]));
        }

        $this->suggestedToolContinuation->clear($state, $toolName);
        $this->stateStore->put($context, $state);

        return AiNativeActionOutcome::continueLoop();
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    private function shouldGateNewActionPlan(
        string $message,
        array $state,
        array $options,
        array $plan,
        AgentTool $tool
    ): bool {
        return $this->actionIntentGuard->shouldGatePlan(
            $message,
            $state,
            $options,
            $plan,
            $tool,
            $this->skillPolicy->payloadFromPlan($plan, $state, $options) !== []
        );
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    private function shouldRememberConfirmingToolPayload(string $message, array $state, array $options, string $toolName): bool
    {
        $requiredFinalTools = $this->skillPolicy->requiredFinalTools($message, $options, $state);

        return $requiredFinalTools === [] || in_array($toolName, $requiredFinalTools, true);
    }

    private function isLookupMiss(string $toolName, AgentTool $tool, ActionResult $result): bool
    {
        $data = is_array($result->data) ? $result->data : [];
        if (($data['found'] ?? null) !== false) {
            return false;
        }

        $name = mb_strtolower(trim($toolName));
        $kind = mb_strtolower(trim((string) $tool->getToolKind()));
        $capabilities = array_map('mb_strtolower', $tool->getCapabilities());

        return in_array($kind, ['lookup', 'search', 'find', 'read'], true)
            || array_intersect($capabilities, ['lookup', 'search', 'find', 'read']) !== []
            || preg_match('/^(find|lookup|search|get|fetch)_/i', $name) === 1;
    }

    private function planMessage(array $plan): string
    {
        $message = trim((string) ($plan['message'] ?? ''));

        return $message !== '' ? $message : 'Done.';
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $arguments
     * @param array<int, string> $validation
     */
    private function shouldRetryAfterValidationFailure(array &$state, string $toolName, array $arguments, array $validation): bool
    {
        $state['last_tool_validation_failure'] = [
            'tool' => $toolName,
            'validation_errors' => $validation,
            'arguments' => $arguments,
        ];

        $signature = hash('sha256', $toolName.'|'.json_encode($arguments).'|'.json_encode($validation));
        if (isset($state['tool_validation_attempts'][$signature])) {
            return false;
        }

        $state['tool_validation_attempts'][$signature] = true;
        $state['runtime_feedback'][] = [
            'reason' => 'tool_validation_failed',
            'message' => 'The planned tool call was missing required data. Repair the plan using the current payload, ask the user for missing fields, or call a lookup tool before retrying.',
            'tool' => $toolName,
            'validation_errors' => $validation,
            'arguments' => $arguments,
        ];

        return true;
    }
}
