<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeSuggestedToolContinuation
{
    private AiNativeSuggestedToolArgumentResolver $argumentResolver;
    private AiNativeSuggestedToolStateUpdater $stateUpdater;

    public function __construct(
        private readonly ToolRegistry $tools,
        ?AiNativeSuggestedToolArgumentResolver $argumentResolver = null,
        ?AiNativeSuggestedToolStateUpdater $stateUpdater = null
    ) {
        $this->argumentResolver = $argumentResolver ?? new AiNativeSuggestedToolArgumentResolver();
        $this->stateUpdater = $stateUpdater ?? new AiNativeSuggestedToolStateUpdater();
    }

    public function shouldContinueFromSuggestedTools(ActionResult $result): bool
    {
        if (!$result->requiresUserInput()) {
            return false;
        }

        return $this->suggestedToolsFromResult($result) !== [];
    }

    /**
     * @param array<string, mixed>|null $confirmedWriteArguments
     */
    public function mark(array &$state, ?string $confirmedWriteTool, ActionResult $result, ?array $confirmedWriteArguments = null): void
    {
        $confirmedWriteTool = $this->confirmedWriteTool($state, $confirmedWriteTool);
        $confirmedWriteArguments ??= $this->confirmedWriteArguments($state);
        $toolResult = $this->resultWithLatestPayload($state, $result, $confirmedWriteArguments);

        if ($confirmedWriteTool !== null && $confirmedWriteTool !== '') {
            $state['confirmed_write_tools'][$confirmedWriteTool] = [
                'arguments' => $confirmedWriteArguments,
            ];
        }

        $state['runtime_feedback'][] = [
            'reason' => 'suggested_tool_continuation',
            'message' => 'The previous tool requested additional tool work. Use suggested tools and retry the confirmed write with resolved values.',
            'tool_result' => $toolResult,
        ];

        $state['suggested_tool_continuation'] = [
            'confirmed_write_tool' => $confirmedWriteTool,
            'confirmed_write_arguments' => $confirmedWriteArguments,
            'suggested_tools' => $this->suggestedToolsFromResult($result),
            'tool_result' => $toolResult,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function suggestedToolsFromResult(ActionResult $result): array
    {
        $data = is_array($result->data) ? $result->data : [];
        $metadata = is_array($result->metadata) ? $result->metadata : [];
        $values = [];

        foreach ([$data['suggested_tools'] ?? null, $metadata['suggested_tools'] ?? null] as $candidate) {
            foreach ((array) $candidate as $tool) {
                if (is_string($tool) && trim($tool) !== '') {
                    $values[] = trim($tool);
                }
            }
        }

        foreach ([$data['suggested_tool'] ?? null, $metadata['suggested_tool'] ?? null] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $values[] = trim($candidate);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     */
    public function writeContinuationIsConfirmed(string $toolName, array $state, array $arguments = []): bool
    {
        $confirmed = $state['confirmed_write_tools'][$toolName] ?? null;
        if (!is_array($confirmed)) {
            return false;
        }

        $confirmedArguments = is_array($confirmed['arguments'] ?? null) ? $confirmed['arguments'] : [];
        if ($confirmedArguments === []) {
            return false;
        }

        return $this->containsConfirmedArguments($arguments, $confirmedArguments);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function mustContinue(array $state): bool
    {
        return is_array($state['suggested_tool_continuation'] ?? null);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function clear(array &$state, string $completedToolName): void
    {
        $continuation = is_array($state['suggested_tool_continuation'] ?? null) ? $state['suggested_tool_continuation'] : [];
        $confirmedWriteTool = (string) ($continuation['confirmed_write_tool'] ?? '');

        if ($confirmedWriteTool === '' || $confirmedWriteTool !== $completedToolName) {
            return;
        }

        unset($state['suggested_tool_continuation'], $state['suggested_tool_attempts']);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function cancelForUserChange(array &$state, string $message): void
    {
        $continuation = is_array($state['suggested_tool_continuation'] ?? null) ? $state['suggested_tool_continuation'] : [];
        $confirmedWriteTool = trim((string) ($continuation['confirmed_write_tool'] ?? ''));

        if ($confirmedWriteTool !== '') {
            unset($state['confirmed_write_tools'][$confirmedWriteTool]);
            if (($state['confirmed_write_tools'] ?? []) === []) {
                unset($state['confirmed_write_tools']);
            }
        }

        $state['runtime_feedback'][] = [
            'reason' => 'suggested_tool_continuation_cancelled_by_user',
            'message' => 'The user changed or clarified the request before suggested tool continuation finished. Interpret the latest message before continuing old tool work.',
            'latest_message' => mb_substr(trim($message), 0, 500),
            'tool_result' => is_array($continuation['tool_result'] ?? null) ? $continuation['tool_result'] : null,
        ];

        unset($state['suggested_tool_continuation'], $state['suggested_tool_attempts']);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function run(
        UnifiedActionContext $context,
        array &$state,
        callable $executeTool,
        callable $recordToolResult,
        ?callable $confirmSuggestedWrite = null,
        ?callable $confirmedWriteCompleted = null
    ): ?AiNativeActionOutcome
    {
        $continuation = is_array($state['suggested_tool_continuation'] ?? null) ? $state['suggested_tool_continuation'] : null;
        if ($continuation === null) {
            return null;
        }

        $suggestedTools = array_values(array_filter((array) ($continuation['suggested_tools'] ?? []), static fn (mixed $tool): bool => is_string($tool) && trim($tool) !== ''));
        if ($suggestedTools === []) {
            $this->abandon($state, 'no_suggested_tools');

            return null;
        }

        $candidates = $this->argumentResolver->candidates($continuation);
        foreach ($suggestedTools as $toolName) {
            $tool = $this->tools->get((string) $toolName);
            if (!$tool instanceof AgentTool) {
                continue;
            }

            if ($tool->requiresConfirmation() && $confirmSuggestedWrite === null) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $arguments = $this->argumentResolver->argumentsFor($tool, $candidate, $continuation);
                if ($arguments === []) {
                    continue;
                }

                $signature = (string) $toolName.':'.json_encode($arguments);
                if (isset($state['suggested_tool_attempts'][$signature])) {
                    continue;
                }

                $validation = $tool->validate($arguments);
                if ($validation !== []) {
                    $state['suggested_tool_attempts'][$signature] = [
                        'valid' => false,
                        'errors' => $validation,
                    ];

                    continue;
                }

                if ($tool->requiresConfirmation()) {
                    if ($this->canAutoConfirmSuggestedWrite($continuation)) {
                        $confirmedArguments = $this->withConfirmedFlag($tool, $arguments);
                        $validation = $tool->validate($confirmedArguments);
                        if ($validation !== []) {
                            $state['suggested_tool_attempts'][$signature] = [
                                'valid' => false,
                                'errors' => $validation,
                            ];

                            continue;
                        }

                        $state['suggested_tool_attempts'][$signature] = [
                            'valid' => true,
                            'auto_confirmed' => true,
                        ];
                        $result = $executeTool($tool, $confirmedArguments, $context);
                        $recordToolResult($state, (string) $toolName, $confirmedArguments, $result, true);
                        $this->stateUpdater->recordAttemptResult($state, $signature, $result);
                        $this->stateUpdater->applyResultToContinuation($state, $candidate, $result);

                        if ($this->shouldContinueFromSuggestedTools($result)) {
                            $this->mark($state, null, $result);
                        }

                        return AiNativeActionOutcome::continueLoop();
                    }

                    $state['suggested_tool_attempts'][$signature] = [
                        'valid' => true,
                        'pending_confirmation' => true,
                    ];

                    $response = $confirmSuggestedWrite($tool, (string) $toolName, $arguments, $context, $state, $this->confirmationMessageForSuggestedTool((string) $toolName));
                    if ($response instanceof AgentResponse) {
                        return AiNativeActionOutcome::response($response);
                    }

                    return AiNativeActionOutcome::continueLoop();
                }

                $state['suggested_tool_attempts'][$signature] = ['valid' => true];
                $result = $executeTool($tool, $arguments, $context);
                $recordToolResult($state, (string) $toolName, $arguments, $result);
                $this->stateUpdater->recordAttemptResult($state, $signature, $result);
                $this->stateUpdater->applyResultToContinuation($state, $candidate, $result);

                if ($this->shouldContinueFromSuggestedTools($result)) {
                    $this->mark($state, null, $result);
                }

                if ($this->argumentResolver->isLookupMissResult($tool, (string) $toolName, $result)) {
                    $state['suggested_tool_attempts'][$signature]['outcome'] = 'not_found';

                    continue;
                }

                return AiNativeActionOutcome::continueLoop();
            }
        }

        if ($this->stateUpdater->hasSuccessfulAttempt($state)) {
            $retryOutcome = $this->retryConfirmedWriteAfterSuggestedTools($context, $state, $executeTool, $recordToolResult, $confirmedWriteCompleted);
            if ($retryOutcome instanceof AiNativeActionOutcome) {
                return $retryOutcome;
            }

            return null;
        }

        $this->abandon($state, 'no_executable_suggested_tools');

        return null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function abandon(array &$state, string $reason): void
    {
        $state['runtime_feedback'][] = [
            'reason' => 'suggested_tool_continuation_abandoned',
            'message' => 'No suggested tool could be executed automatically. Continue planning normally: ask the user for the missing data or use another available tool.',
            'detail' => $reason,
            'tool_result' => is_array($state['suggested_tool_continuation']['tool_result'] ?? null)
                ? $state['suggested_tool_continuation']['tool_result']
                : null,
        ];

        unset($state['suggested_tool_continuation'], $state['suggested_tool_attempts']);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function confirmedWriteTool(array $state, ?string $confirmedWriteTool): ?string
    {
        if ($confirmedWriteTool !== null && trim($confirmedWriteTool) !== '') {
            return trim($confirmedWriteTool);
        }

        $existing = data_get($state, 'suggested_tool_continuation.confirmed_write_tool');

        return is_string($existing) && trim($existing) !== '' ? trim($existing) : $confirmedWriteTool;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private function confirmedWriteArguments(array $state): ?array
    {
        $existing = data_get($state, 'suggested_tool_continuation.confirmed_write_arguments');

        return is_array($existing) ? $existing : null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed>|null $confirmedWriteArguments
     * @return array<string, mixed>
     */
    private function resultWithLatestPayload(array $state, ActionResult $result, ?array $confirmedWriteArguments): array
    {
        $toolResult = $result->toArray();
        $payload = is_array(data_get($toolResult, 'data.current_payload'))
            ? data_get($toolResult, 'data.current_payload')
            : [];

        foreach ([
            $confirmedWriteArguments,
            data_get($state, 'task_frame.current_payload'),
        ] as $candidate) {
            if (is_array($candidate)) {
                $payload = $this->mergeNonEmptyPayload($payload, $candidate);
            }
        }

        if ($payload !== []) {
            data_set($toolResult, 'data.current_payload', $payload);
        }

        return $toolResult;
    }

    /**
     * @param array<int|string, mixed> $candidate
     * @param array<int|string, mixed> $confirmed
     */
    private function containsConfirmedArguments(array $candidate, array $confirmed): bool
    {
        foreach ($confirmed as $key => $value) {
            if ((string) $key === 'confirmed') {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (!array_key_exists($key, $candidate)) {
                return false;
            }

            $candidateValue = $candidate[$key];
            if (is_array($value)) {
                if (!is_array($candidateValue) || !$this->containsConfirmedArguments($candidateValue, $value)) {
                    return false;
                }

                continue;
            }

            if (!$this->sameValue($candidateValue, $value)) {
                return false;
            }
        }

        return true;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (string) (0 + $left) === (string) (0 + $right);
        }

        return (string) $left === (string) $right;
    }

    /**
     * @param array<string, mixed> $continuation
     */
    private function canAutoConfirmSuggestedWrite(array $continuation): bool
    {
        if (!config('ai-agent.ai_native.auto_confirm_suggested_writes_after_final_confirmation', true)) {
            return false;
        }

        $confirmedWriteTool = trim((string) ($continuation['confirmed_write_tool'] ?? ''));
        $confirmedWriteArguments = is_array($continuation['confirmed_write_arguments'] ?? null)
            ? $continuation['confirmed_write_arguments']
            : [];

        return $confirmedWriteTool !== '' && $confirmedWriteArguments !== [];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function withConfirmedFlag(AgentTool $tool, array $arguments): array
    {
        $parameters = $tool->getParameters();
        if (array_key_exists('confirmed', $parameters) && !array_key_exists('confirmed', $arguments)) {
            $arguments['confirmed'] = true;
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function retryConfirmedWriteAfterSuggestedTools(
        UnifiedActionContext $context,
        array &$state,
        callable $executeTool,
        callable $recordToolResult,
        ?callable $confirmedWriteCompleted
    ): ?AiNativeActionOutcome {
        $continuation = is_array($state['suggested_tool_continuation'] ?? null) ? $state['suggested_tool_continuation'] : [];
        $toolName = trim((string) ($continuation['confirmed_write_tool'] ?? ''));
        if ($toolName === '') {
            return null;
        }

        $tool = $this->tools->get($toolName);
        if (!$tool instanceof AgentTool) {
            return null;
        }

        $arguments = $this->confirmedWriteRetryArguments($tool, $state, $continuation);
        if ($arguments === [] || $tool->validate($arguments) !== []) {
            return null;
        }

        $result = $executeTool($tool, $arguments, $context);
        $recordToolResult($state, $toolName, $arguments, $result, true);

        if ($this->shouldContinueFromSuggestedTools($result)) {
            $this->mark($state, $toolName, $result, $arguments);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($result->success && !$result->requiresUserInput()) {
            $this->clear($state, $toolName);

            if ($confirmedWriteCompleted !== null) {
                $response = $confirmedWriteCompleted($state, $toolName, $result, $context);
                if ($response instanceof AgentResponse) {
                    return AiNativeActionOutcome::response($response);
                }
            }

            return AiNativeActionOutcome::continueLoop();
        }

        return null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $continuation
     * @return array<string, mixed>
     */
    private function confirmedWriteRetryArguments(AgentTool $tool, array $state, array $continuation): array
    {
        $arguments = is_array($continuation['confirmed_write_arguments'] ?? null)
            ? $continuation['confirmed_write_arguments']
            : [];

        foreach ([
            data_get($continuation, 'tool_result.data.current_payload'),
            data_get($state, 'task_frame.current_payload'),
        ] as $payload) {
            if (is_array($payload)) {
                $arguments = $this->mergeNonEmptyPayload($arguments, $payload);
            }
        }

        return $this->withConfirmedFlag($tool, $arguments);
    }

    /**
     * @param array<int|string, mixed> $existing
     * @param array<int|string, mixed> $incoming
     * @return array<int|string, mixed>
     */
    private function mergeNonEmptyPayload(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergeNonEmptyPayload($existing[$key], $value);
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    private function confirmationMessageForSuggestedTool(string $toolName): string
    {
        return 'Please confirm before I run '.str_replace('_', ' ', $toolName).'.';
    }
}
