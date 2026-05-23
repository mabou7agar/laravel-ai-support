<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativePendingConfirmationHandler
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly IntentSignalService $signals,
        private readonly ToolResultAuthorityService $authority,
        private readonly AgentTaskStateService $taskState,
        private readonly AiNativeSkillPolicy $skillPolicy,
        private readonly AiNativeConfirmationPresenter $confirmationPresenter,
        private readonly AiNativeSuggestedToolContinuation $suggestedToolContinuation,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeConfirmationPreviewService $confirmationPreview,
        private readonly AiNativeResponseFactory $responses,
        private readonly AiNativeToolExecutor $toolExecutor,
        private readonly ?AiNativeConfirmationIntent $confirmationIntent = null
    ) {
        $this->approval = $confirmationIntent ?? new AiNativeConfirmationIntent($signals);
    }

    private AiNativeConfirmationIntent $approval;

    /**
     * @param array<string, mixed> $state
     */
    public function handlePendingTool(string $message, UnifiedActionContext $context, array &$state): ?AgentResponse
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            $this->taskState->clearPendingConfirmation($state);

            return null;
        }

        $changesPendingTool = $this->messageChangesPendingTool($normalized);
        if ($this->signals->isNegative($normalized)) {
            $this->taskState->clearPendingConfirmation($state);
            if ($changesPendingTool) {
                $this->recordPendingChangeFeedback($state);
            }

            return null;
        }

        if (!$this->isPendingToolApproval($normalized)) {
            if (!$changesPendingTool) {
                $this->stateStore->put($context, $state);

                return null;
            }

            $this->taskState->clearPendingConfirmation($state);
            $this->recordPendingChangeFeedback($state);

            return null;
        }

        $pending = (array) ($state['pending_tool'] ?? []);
        $toolName = (string) ($pending['name'] ?? '');
        $tool = $this->tools->get($toolName);
        if (!$tool instanceof AgentTool) {
            unset($state['pending_tool']);
            $this->stateStore->put($context, $state);

            return $this->responses->needsUserInput($context, $state, "Tool {$toolName} is not available.");
        }

        $params = $this->toolExecutor->withConfirmationForValidation($tool, (array) ($pending['params'] ?? []));
        if ($this->taskState->hasCompletedWrite($state, $toolName, $params)) {
            unset($state['pending_tool']);
            $this->stateStore->put($context, $state);

            return $this->responses->alreadyCompleted($context, $state);
        }

        $isRequiredFinalTool = in_array($toolName, $this->skillPolicy->requiredFinalTools('', [], $state), true);
        $result = $this->toolExecutor->execute($tool, $params, $context);
        unset($state['pending_tool']);
        $this->toolExecutor->recordResult($state, $toolName, $params, $result, $tool->requiresConfirmation());
        $this->stateStore->put($context, $state);

        if ($this->suggestedToolContinuation->shouldContinueFromSuggestedTools($result)) {
            $this->suggestedToolContinuation->mark($state, $toolName, $result, $params);
            $this->stateStore->put($context, $state);

            return null;
        }

        if (!$result->success || $result->requiresUserInput()) {
            return $this->responses->needsUserInput($context, $state, $result->message ?? $result->error ?? 'More information is required.', (array) ($result->metadata['required_inputs'] ?? []), [
                'tool_name' => $toolName,
                'tool_result' => $result->toArray(),
            ]);
        }

        if ($isRequiredFinalTool) {
            return $this->responses->toolCompleted($context, $state, $toolName, $result);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function confirmCurrentPayloadIfRequested(string $message, UnifiedActionContext $context, array &$state, array $options): ?AgentResponse
    {
        $normalized = mb_strtolower(trim($message));
        if (!$this->isPendingToolApproval($normalized)) {
            return null;
        }

        if (is_array($state['suggested_tool_continuation'] ?? null)) {
            return null;
        }

        return $this->currentPayloadConfirmationResponse($context, $state, $options);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function currentPayloadConfirmationResponse(UnifiedActionContext $context, array &$state, array $options): ?AgentResponse
    {
        $payload = data_get($state, 'task_frame.current_payload');
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $toolName = $this->skillPolicy->requiredFinalTools('', $options, $state)[0] ?? null;
        if (!is_string($toolName) || trim($toolName) === '') {
            return null;
        }

        $tool = $this->tools->get($toolName);
        if (!$tool instanceof AgentTool || !$tool->requiresConfirmation()) {
            return null;
        }

        $arguments = $this->authority->sanitizeArguments($payload, $state);
        $validation = $tool->validate($this->toolExecutor->withConfirmationForValidation($tool, $arguments));
        if ($validation !== []) {
            $this->stateStore->put($context, $state);

            return $this->responses->needsUserInput($context, $state, implode("\n", $validation), $this->responses->requiredInputsFromValidation($validation), [
                'tool_name' => $toolName,
            ]);
        }

        if ($this->taskState->hasCompletedWrite($state, $toolName, $arguments)) {
            return $this->responses->alreadyCompleted($context, $state);
        }

        $preview = $this->confirmationPreview->preview($tool, $arguments, $context);
        $previewResult = $preview['result'];
        if ($previewResult instanceof ActionResult && (!$previewResult->success || $previewResult->requiresUserInput())) {
            $this->stateStore->put($context, $state);

            return $this->responses->needsUserInput($context, $state, $previewResult->message ?? $previewResult->error ?? 'More information is required.', (array) ($previewResult->metadata['required_inputs'] ?? []), [
                'tool_name' => $toolName,
                'tool_result' => $previewResult->toArray(),
            ]);
        }

        $arguments = $preview['arguments'];
        $message = $this->confirmationPresenter->defaultConfirmationMessage($toolName);
        $state['pending_tool'] = [
            'name' => $toolName,
            'params' => $arguments,
            'message' => $message,
        ];
        $this->taskState->markPendingConfirmation($state, $toolName, $arguments);
        $this->stateStore->put($context, $state);

        return $this->responses->confirmation($context, $state, $toolName, $arguments, $message, $preview['summary']);
    }

    private function isPendingToolApproval(string $message): bool
    {
        return $this->approval->isApproval($message);
    }

    private function messageChangesPendingTool(string $message): bool
    {
        foreach ($this->pendingChangeTerms() as $term) {
            $term = trim((string) $term);
            if ($term !== '' && preg_match('/\b'.preg_quote($term, '/').'\b/u', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function recordPendingChangeFeedback(array &$state): void
    {
        $state['runtime_feedback'][] = [
            'reason' => 'pending_confirmation_changed_by_user',
            'message' => 'The user did not approve the pending write and provided a new instruction. Treat the previous pending confirmation as cancelled and continue from the new instruction.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function pendingChangeTerms(): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $term): string => mb_strtolower(trim((string) $term)), (array) config('ai-agent.ai_native.pending_confirmation_change_terms', [
                'change',
                'edit',
                'update',
                'modify',
                'remove',
                'delete',
                'cancel',
                'replace',
                'add',
                'instead',
                'continue with',
            ])),
            static fn (string $term): bool => $term !== ''
        ));
    }
}
