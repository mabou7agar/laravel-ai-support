<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class AiNativeRuntime
{
    private AiNativePromptBuilder $promptBuilder;
    private AiNativeResponseParser $parser;
    private ToolResultAuthorityService $authority;
    private AgentTaskStateService $taskState;
    private ActionIntentGuard $actionIntentGuard;
    private AiNativeSkillPolicy $skillPolicy;
    private AiNativeConfirmationPresenter $confirmationPresenter;
    private AiNativeSuggestedToolContinuation $suggestedToolContinuation;
    private AiNativeStateStore $stateStore;
    private AiNativeConfirmationPreviewService $confirmationPreview;
    private AiNativeResponseFactory $responses;
    private AiNativeToolExecutor $toolExecutor;
    private AiNativeAskUserActionHandler $askUserHandler;
    private AiNativeToolCallActionHandler $toolCallHandler;
    private AiNativeFinalActionHandler $finalHandler;
    private AiNativePendingConfirmationHandler $pendingConfirmationHandler;
    private AiNativeConfirmationIntent $confirmationIntent;

    public function __construct(
        private readonly AIEngineService $ai,
        private readonly ToolRegistry $tools,
        private readonly AgentSkillRegistry $skills,
        private readonly IntentSignalService $signals,
        ?AiNativePromptBuilder $promptBuilder = null,
        ?AiNativeResponseParser $parser = null,
        ?ToolResultAuthorityService $authority = null,
        ?AgentTaskStateService $taskState = null,
        private readonly ?AgentExecutionPolicyService $executionPolicy = null,
        ?ActionIntentGuard $actionIntentGuard = null,
        ?AiNativeSkillPolicy $skillPolicy = null,
        ?AiNativeConfirmationPresenter $confirmationPresenter = null,
        ?AiNativeSuggestedToolContinuation $suggestedToolContinuation = null,
        ?AiNativeStateStore $stateStore = null,
        ?AiNativeConfirmationPreviewService $confirmationPreview = null,
        ?AiNativeResponseFactory $responses = null,
        ?AiNativeToolExecutor $toolExecutor = null,
        ?AiNativeAskUserActionHandler $askUserHandler = null,
        ?AiNativeToolCallActionHandler $toolCallHandler = null,
        ?AiNativeFinalActionHandler $finalHandler = null,
        ?AiNativePendingConfirmationHandler $pendingConfirmationHandler = null
    ) {
        $this->promptBuilder = $promptBuilder ?? new AiNativePromptBuilder($tools, $skills);
        $this->parser = $parser ?? new AiNativeResponseParser();
        $this->authority = $authority ?? new ToolResultAuthorityService();
        $this->taskState = $taskState ?? new AgentTaskStateService(new ToolOutcomeNormalizer());
        $this->confirmationIntent = new AiNativeConfirmationIntent($signals);
        $this->actionIntentGuard = $actionIntentGuard ?? new ActionIntentGuard($signals, $this->confirmationIntent);
        $this->skillPolicy = $skillPolicy ?? new AiNativeSkillPolicy($skills, $tools, $signals, confirmationIntent: $this->confirmationIntent);
        $this->confirmationPresenter = $confirmationPresenter ?? new AiNativeConfirmationPresenter();
        $this->stateStore = $stateStore ?? new AiNativeStateStore($executionPolicy);
        $this->confirmationPreview = $confirmationPreview ?? new AiNativeConfirmationPreviewService();
        $this->responses = $responses ?? new AiNativeResponseFactory($this->stateStore, $tools, $this->confirmationPresenter);
        $this->toolExecutor = $toolExecutor ?? new AiNativeToolExecutor($this->taskState, $this->stateStore, $executionPolicy);
        $this->suggestedToolContinuation = $suggestedToolContinuation ?? new AiNativeSuggestedToolContinuation($tools);
        $this->askUserHandler = $askUserHandler ?? new AiNativeAskUserActionHandler(
            $this->skillPolicy,
            $this->suggestedToolContinuation,
            $this->actionIntentGuard,
            $this->stateStore,
            $this->responses
        );
        $this->toolCallHandler = $toolCallHandler ?? new AiNativeToolCallActionHandler(
            $tools,
            $this->authority,
            $this->taskState,
            $this->actionIntentGuard,
            $this->skillPolicy,
            $this->suggestedToolContinuation,
            $this->stateStore,
            $this->confirmationPreview,
            $this->responses,
            $this->toolExecutor
        );
        $this->finalHandler = $finalHandler ?? new AiNativeFinalActionHandler(
            $this->skillPolicy,
            $this->suggestedToolContinuation,
            $this->stateStore,
            $this->responses
        );
        $this->pendingConfirmationHandler = $pendingConfirmationHandler ?? new AiNativePendingConfirmationHandler(
            $tools,
            $signals,
            $this->authority,
            $this->taskState,
            $this->skillPolicy,
            $this->confirmationPresenter,
            $this->suggestedToolContinuation,
            $this->stateStore,
            $this->confirmationPreview,
            $this->responses,
            $this->toolExecutor
        );
    }

    public function process(string $message, UnifiedActionContext $context, array $options = []): AgentResponse
    {
        $state = $this->stateStore->state($context);
        $state['runtime_feedback'] = [];
        unset($state['last_tool_validation_failure']);
        $hadRecentContextBeforeTurn = $this->skillPolicy->hasRecentContext($state);
        $this->skillPolicy->seedActiveTask($message, $state, $options);

        $handledPendingToolApproval = false;
        if (is_array($state['pending_tool'] ?? null)) {
            $handledPendingToolApproval = $this->confirmationIntent->isApproval($message);
            $pendingResponse = $this->pendingConfirmationHandler->handlePendingTool($message, $context, $state);
            if ($pendingResponse instanceof AgentResponse) {
                return $pendingResponse;
            }
        }

        if (!$handledPendingToolApproval) {
            $currentPayloadConfirmation = $this->pendingConfirmationHandler->confirmCurrentPayloadIfRequested($message, $context, $state, $options);
            if ($currentPayloadConfirmation instanceof AgentResponse) {
                return $currentPayloadConfirmation;
            }
        }

        $this->cancelStaleSuggestedContinuation($message, $context, $state);

        for ($step = 0; $step < $this->maxSteps($options); $step++) {
            $suggestedOutcome = $this->suggestedToolContinuation->run(
                $context,
                $state,
                fn (AgentTool $tool, array $arguments, UnifiedActionContext $context): ActionResult => $this->toolExecutor->execute($tool, $arguments, $context),
                function (array &$state, string $toolName, array $params, ActionResult $result, bool $writeTool = false): void {
                    $this->toolExecutor->recordResult($state, $toolName, $params, $result, $writeTool);
                },
                function (AgentTool $tool, string $toolName, array $arguments, UnifiedActionContext $context, array &$state, string $message): AgentResponse {
                    $validation = $tool->validate($this->toolExecutor->withConfirmationForValidation($tool, $arguments));
                    if ($validation !== []) {
                        return $this->responses->needsUserInput($context, $state, implode("\n", $validation), $this->responses->requiredInputsFromValidation($validation), [
                            'tool_name' => $toolName,
                        ]);
                    }

                    $preview = $this->confirmationPreview->preview($tool, $arguments, $context);
                    $previewResult = $preview['result'];
                    if ($previewResult instanceof ActionResult && (!$previewResult->success || $previewResult->requiresUserInput())) {
                        return $this->responses->needsUserInput($context, $state, $previewResult->message ?? $previewResult->error ?? 'More information is required.', (array) ($previewResult->metadata['required_inputs'] ?? []), [
                            'tool_name' => $toolName,
                            'tool_result' => $previewResult->toArray(),
                        ]);
                    }

                    $arguments = $preview['arguments'];
                    $state['pending_tool'] = [
                        'name' => $toolName,
                        'params' => $arguments,
                        'message' => $message,
                    ];
                    $this->taskState->markPendingConfirmation($state, $toolName, $arguments);
                    $this->stateStore->put($context, $state);

                    return $this->responses->confirmation($context, $state, $toolName, $arguments, $message, $preview['summary']);
                },
                function (array &$state, string $toolName, ActionResult $result, UnifiedActionContext $context): AgentResponse {
                    return $this->responses->toolCompleted($context, $state, $toolName, $result);
                }
            );

            if ($suggestedOutcome instanceof AiNativeActionOutcome) {
                if ($suggestedOutcome->response instanceof AgentResponse) {
                    return $suggestedOutcome->response;
                }

                $this->stateStore->put($context, $state);

                if ($suggestedOutcome->continueLoop) {
                    continue;
                }
            }

            $plan = $this->nextPlan($message, $context, $state, $options);
            $this->rememberPlanPayload($state, $plan, $options);
            $action = strtolower(trim((string) ($plan['action'] ?? 'final')));

            if ($action === 'ask_user') {
                $confirmationFallback = $this->confirmationFromCurrentPayloadAfterRepeatedWriteQuestion($context, $state, $plan);
                if ($confirmationFallback instanceof AgentResponse) {
                    return $confirmationFallback;
                }

                if ($this->shouldReplanWriteConfirmationQuestion($state, $plan)) {
                    $state['runtime_feedback'][] = [
                        'reason' => 'tool_confirmation_question_requires_tool_call',
                        'message' => 'The plan asked the user to confirm an application write in free text. Call the matching confirming tool with the collected payload instead; Laravel will present the structured confirmation and preserve pending approval state.',
                    ];
                    $this->stateStore->put($context, $state);

                    continue;
                }

                $outcome = $this->askUserHandler->handle($message, $context, $state, $options, $plan, $hadRecentContextBeforeTurn);
                if ($outcome->response instanceof AgentResponse) {
                    return $outcome->response;
                }

                if ($outcome->continueLoop) {
                    continue;
                }
            }

            if ($action === 'tool_call') {
                $outcome = $this->toolCallHandler->handle($message, $context, $state, $options, $plan);
                if ($outcome->response instanceof AgentResponse) {
                    return $outcome->response;
                }

                if ($outcome->continueLoop) {
                    continue;
                }
            }

            $outcome = $this->finalHandler->handle($message, $context, $state, $options, $plan);
            if ($outcome->response instanceof AgentResponse) {
                return $outcome->response;
            }

            if ($outcome->continueLoop) {
                continue;
            }
        }

        $this->stateStore->put($context, $state);

        $currentPayloadReview = $this->pendingConfirmationHandler->currentPayloadConfirmationResponse($context, $state, $options);
        if ($currentPayloadReview instanceof AgentResponse) {
            return $currentPayloadReview;
        }

        $lastValidation = is_array($state['last_tool_validation_failure'] ?? null) ? $state['last_tool_validation_failure'] : [];
        $validationErrors = array_values(array_filter((array) ($lastValidation['validation_errors'] ?? []), static fn (mixed $error): bool => is_string($error) && trim($error) !== ''));
        if ($validationErrors !== []) {
            return $this->responses->needsUserInput($context, $state, implode("\n", $validationErrors), $this->responses->requiredInputsFromValidation($validationErrors), [
                'tool_name' => $lastValidation['tool'] ?? null,
            ]);
        }

        return $this->responses->needsUserInput($context, $state, 'I need more information to continue.');
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function nextPlan(string $message, UnifiedActionContext $context, array $state, array $options): array
    {
        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->promptBuilder->build($message, $context, $this->stateStore->redactedState($state), $options),
                engine: $options['engine'] ?? config('ai-engine.default', 'openai'),
                model: $options['model'] ?? config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini')),
                maxTokens: (int) ($options['max_tokens'] ?? config('ai-agent.ai_native.max_tokens', 1200)),
                temperature: (float) ($options['temperature'] ?? config('ai-agent.ai_native.temperature', 0.1)),
                metadata: ['context' => 'ai_native_runtime']
            ));
        } catch (Throwable $exception) {
            return [
                'action' => 'ask_user',
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'AI runtime failed.',
            ];
        }

        if (!$response->isSuccessful()) {
            return ['action' => 'ask_user', 'message' => $response->getError() ?? 'AI runtime failed.'];
        }

        return $this->parser->parse($response->getContent());
    }

    private function maxSteps(array $options): int
    {
        return max(1, (int) ($options['max_steps'] ?? config('ai-agent.ai_native.max_steps', 6)));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function cancelStaleSuggestedContinuation(string $message, UnifiedActionContext $context, array &$state): void
    {
        if (!is_array($state['suggested_tool_continuation'] ?? null)) {
            return;
        }

        if (trim($message) === '' || $this->confirmationIntent->isApproval($message)) {
            return;
        }

        $this->suggestedToolContinuation->cancelForUserChange($state, $message);
        $this->stateStore->put($context, $state);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     */
    private function rememberPlanPayload(array &$state, array $plan, array $options): void
    {
        $payload = $this->skillPolicy->payloadFromPlan($plan, $state, $options);
        if ($payload === []) {
            return;
        }

        $this->taskState->rememberCurrentPayload($state, $payload, 'ai_plan');
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     */
    private function shouldReplanWriteConfirmationQuestion(array $state, array $plan): bool
    {
        if ($this->skillPolicy->hasRuntimeFeedback($state, 'tool_confirmation_question_requires_tool_call')) {
            return false;
        }

        $message = mb_strtolower(trim((string) ($plan['message'] ?? '')));
        if ($message === '' || !str_contains($message, '?')) {
            return false;
        }

        $missingInputTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.missing_input', [
            'what',
            'which',
            'please provide',
            'enter',
            'instead',
            ' or ',
        ]));
        if ($this->containsAnyTerm($message, $missingInputTerms)) {
            return false;
        }

        $approvalTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.approval', [
            'would you like',
            'would you like to',
            'should i',
            'do you want',
            'shall i',
            'can i',
            'may i',
        ]));
        $writeTerms = array_map('strval', (array) config('ai-agent.ai_native.write_confirmation_question_terms.actions', [
            'create',
            'update',
            'delete',
            'send',
            'generate',
            'run',
            'execute',
            'submit',
            'approve',
        ]));

        return $this->containsAnyTerm($message, $approvalTerms)
            && $this->containsAnyTerm($message, $writeTerms);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     */
    private function confirmationFromCurrentPayloadAfterRepeatedWriteQuestion(UnifiedActionContext $context, array &$state, array $plan): ?AgentResponse
    {
        if (!$this->skillPolicy->hasRuntimeFeedback($state, 'tool_confirmation_question_requires_tool_call')) {
            return null;
        }

        $message = mb_strtolower(trim((string) ($plan['message'] ?? '')));
        if ($message === '' || !str_contains($message, '?')) {
            return null;
        }

        $payload = data_get($state, 'task_frame.current_payload');
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $activeObjective = trim((string) data_get($state, 'task_frame.active_objective', ''));
        if ($activeObjective === '') {
            return null;
        }

        $toolNames = [];
        foreach ($this->skills->skills() as $skill) {
            if ($skill->id !== $activeObjective) {
                continue;
            }

            $toolNames = array_merge($toolNames, $skill->tools);
            break;
        }

        $toolNames = array_values(array_unique(array_merge(
            $toolNames,
            array_map(static fn (AgentTool $tool): string => $tool->getName(), $this->tools->all())
        )));

        foreach ($toolNames as $toolName) {
            $tool = $this->tools->get($toolName);
            if (!$tool instanceof AgentTool || !$tool->requiresConfirmation()) {
                continue;
            }

            $arguments = $this->argumentsFromPayloadForTool($payload, $tool);
            if ($arguments === []) {
                continue;
            }

            $validation = $tool->validate($this->toolExecutor->withConfirmationForValidation($tool, $arguments));
            if ($validation !== []) {
                continue;
            }

            $preview = $this->confirmationPreview->preview($tool, $arguments, $context);
            $previewResult = $preview['result'];
            if ($previewResult instanceof ActionResult && (!$previewResult->success || $previewResult->requiresUserInput())) {
                continue;
            }

            $arguments = $preview['arguments'];
            $message = trim((string) ($plan['message'] ?? ''));
            $state['pending_tool'] = [
                'name' => $toolName,
                'params' => $arguments,
                'message' => $message !== '' ? $message : 'Please confirm before I continue.',
            ];
            $this->taskState->markPendingConfirmation($state, $toolName, $arguments);
            $this->stateStore->put($context, $state);

            return $this->responses->confirmation($context, $state, $toolName, $arguments, $state['pending_tool']['message'], $preview['summary']);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function argumentsFromPayloadForTool(array $payload, AgentTool $tool): array
    {
        $arguments = [];

        foreach ($tool->getParameters() as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            if ((string) $name === 'confirmed') {
                continue;
            }

            $value = $this->payloadValueForParameter($payload, (string) $name);
            if ($value !== null && $value !== '') {
                $arguments[(string) $name] = $value;
            } elseif ((bool) ($definition['required'] ?? false)) {
                return [];
            }
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadValueForParameter(array $payload, string $parameter): mixed
    {
        if (array_key_exists($parameter, $payload)) {
            return $payload[$parameter];
        }

        foreach ($payload as $key => $value) {
            if (!is_string($key) || $value === null || $value === '') {
                continue;
            }

            if ($key === $parameter || str_ends_with($key, '_'.$parameter)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $requiredInputs
     */
    private function hasNonConfirmationRequiredInputs(array $requiredInputs): bool
    {
        if ($requiredInputs === []) {
            return false;
        }

        foreach ($requiredInputs as $input) {
            $name = is_array($input)
                ? (string) ($input['name'] ?? $input['id'] ?? $input['field'] ?? '')
                : (string) $input;
            $type = is_array($input) ? (string) ($input['type'] ?? '') : '';
            $value = mb_strtolower(trim($name.' '.$type));

            if ($value === '' || !preg_match('/\b(confirm|confirmation|approve|approval|yes_no|boolean)\b/u', $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $terms
     */
    private function containsAnyTerm(string $message, array $terms): bool
    {
        foreach ($terms as $term) {
            $rawTerm = mb_strtolower((string) $term);
            $trimmedTerm = trim($rawTerm);
            if ($trimmedTerm === '') {
                continue;
            }

            $needle = $rawTerm !== $trimmedTerm ? $rawTerm : $trimmedTerm;
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

}
