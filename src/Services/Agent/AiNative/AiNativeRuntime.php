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

}
