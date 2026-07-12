<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentExecutionPolicyService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class AiNativeRuntime
{
    use TranslatesRuntimeText;

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
    private AiNativeAskUserConfirmationHandler $askUserConfirmationHandler;
    private AiNativeAskUserActionHandler $askUserHandler;
    private AiNativeToolCallActionHandler $toolCallHandler;
    private AiNativeFinalActionHandler $finalHandler;
    private AiNativePendingConfirmationHandler $pendingConfirmationHandler;
    private AiNativeConfirmationIntent $confirmationIntent;
    private AiNativeParallelToolCallHandler $parallelHandler;
    private ?ConversationContextCompactor $compactor;

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
        ?AiNativePendingConfirmationHandler $pendingConfirmationHandler = null,
        ?AiNativeAskUserConfirmationHandler $askUserConfirmationHandler = null,
        ?ConversationContextCompactor $compactor = null,
        ?AiNativeParallelToolCallHandler $parallelHandler = null
    ) {
        $this->compactor = $compactor;
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
        $this->askUserConfirmationHandler = $askUserConfirmationHandler ?? new AiNativeAskUserConfirmationHandler(
            $tools,
            $skills,
            $this->skillPolicy,
            $this->taskState,
            $this->stateStore,
            $this->confirmationPreview,
            $this->responses,
            $this->toolExecutor
        );
        $this->askUserHandler = $askUserHandler ?? new AiNativeAskUserActionHandler(
            $this->skillPolicy,
            $this->suggestedToolContinuation,
            $this->actionIntentGuard,
            $this->stateStore,
            $this->responses,
            $this->askUserConfirmationHandler
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
        $this->parallelHandler = $parallelHandler ?? new AiNativeParallelToolCallHandler(
            $this->toolCallHandler,
            $this->stateStore,
            $this->responses
        );
    }

    public function process(string $message, UnifiedActionContext $context, array $options = []): AgentResponse
    {
        $state = $this->stateStore->state($context);
        $state['runtime_feedback'] = [];
        unset($state['last_tool_validation_failure']);
        $outcomesAtTurnStart = count((array) ($state['recent_outcomes'] ?? []));
        // Turn-scoped marker so downstream handlers (e.g. the tool-call
        // handler's validation-failure salvage) can tell which outcomes
        // belong to THIS turn. In-memory only; the state-store allowlist
        // drops it cross-turn.
        $state['outcomes_at_turn_start'] = $outcomesAtTurnStart;
        if ($this->exposeReasoning($options)) {
            // Per-turn rationale accumulator. The state store allowlist drops this
            // cross-turn, which is intentional: reasoning_trace lives only for the
            // current turn and is copied onto the returned AgentResponse metadata.
            $state['reasoning_trace'] = [];
        }
        if ($this->planTimelineEnabled($options)) {
            // Per-turn forward-looking plan snapshot {steps, current}. Like
            // reasoning_trace, the state store allowlist drops it cross-turn; it
            // lives only for the current turn and is copied onto the returned
            // AgentResponse metadata['plan'] by the response factory.
            $state['plan_timeline'] = ['steps' => [], 'current' => 0];
        }
        if ($this->confirmationIntent->isApproval($message)
            && ($state['task_frame']['status'] ?? null) === 'completed'
            && (array) data_get($state, 'task_frame.completed_writes', []) !== []) {
            return $this->responses->alreadyCompleted($context, $state);
        }

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
            $this->compactStateForPlanner($state, $context, $options);

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
                        return $this->responses->needsUserInput($context, $state, $previewResult->message ?? $previewResult->error ?? $this->runtimeText('ai-engine::runtime.responses.more_information_required', 'More information is required.'), (array) ($previewResult->metadata['required_inputs'] ?? []), [
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
            $this->captureReasoning($state, $plan, $options);
            $this->capturePlanTimeline($state, $plan, $options);
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

            if ($this->parallelEnabled($options) && $this->hasParallelToolCalls($plan)) {
                $outcome = $this->parallelHandler->handle($message, $context, $state, $options, $plan);
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

        // Loop exhausted without a parseable final turn from the model. If a
        // tool ALREADY SUCCEEDED this turn, the work is done — answering
        // "I need more information" (or a raw validator string from a bogus
        // trailing call) would tell the user their request failed when it
        // didn't. Return a final response carrying that outcome; the host
        // application can surface the tool's artifacts (previews, ids) from
        // its own stores.
        // Gate: the task frame says the objective COMPLETED — a successful
        // lookup mid-flow must still lead to the planned ask — OR a trailing
        // tool call failed validation after an earlier tool succeeded (the
        // completed work must win over the validator error). Sub-agent runs
        // keep their own richer tool_result_fallback salvage.
        $turnOutcomes = ($state['task_frame']['status'] ?? null) === 'completed' || $validationErrors !== []
            ? array_slice((array) ($state['recent_outcomes'] ?? []), $outcomesAtTurnStart)
            : [];
        if ($validationErrors !== []) {
            $failedTool = (string) ($lastValidation['tool'] ?? '');
            $turnOutcomes = array_values(array_filter($turnOutcomes, static fn (mixed $outcome): bool => is_array($outcome) && (string) ($outcome['tool'] ?? '') !== $failedTool));
        }
        foreach (array_reverse($turnOutcomes) as $turnOutcome) {
            if (is_array($turnOutcome) && ($turnOutcome['success'] ?? false) === true && ($turnOutcome['needs_user_input'] ?? false) !== true) {
                $label = trim((string) ($turnOutcome['label'] ?? $turnOutcome['tool'] ?? ''));

                return $this->responses->final(
                    $context,
                    $state,
                    rtrim($this->runtimeText('ai-engine::runtime.responses.completed_without_summary', 'Done — the requested action completed successfully.'), '.')
                        . ($label !== '' ? ' (' . $label . ').' : '.'),
                    ['last_tool_outcome' => $turnOutcome],
                );
            }
        }

        if ($validationErrors !== []) {
            $failedTool = (string) ($lastValidation['tool'] ?? '');
            $errorsText = implode('; ', $validationErrors);

            return $this->responses->needsUserInput(
                $context,
                $state,
                $this->runtimeText(
                    'ai-engine::runtime.responses.tool_step_failed',
                    $failedTool !== '' ? "I couldn't run the \"{$failedTool}\" step: {$errorsText}" : "I couldn't complete that step: {$errorsText}",
                    ['tool' => $failedTool, 'errors' => $errorsText],
                ),
                $this->responses->requiredInputsFromValidation($validationErrors),
                ['tool_name' => $lastValidation['tool'] ?? null],
            );
        }

        return $this->responses->needsUserInput($context, $state, $this->runtimeText('ai-engine::runtime.responses.need_more_information', 'I need more information to continue.'));
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function nextPlan(string $message, UnifiedActionContext $context, array $state, array $options): array
    {
        $engine = $options['engine'] ?? config('ai-engine.default', 'openai');
        $model = $options['model'] ?? config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini'));
        $maxTokens = (int) ($options['max_tokens'] ?? config('ai-agent.ai_native.max_tokens', 1200));
        $temperature = (float) ($options['temperature'] ?? config('ai-agent.ai_native.temperature', 0.1));
        $redactedState = $this->stateStore->redactedState($state);

        try {
            if ($this->supportsSystemPromptCaching($engine)) {
                // Anthropic doesn't auto-cache like OpenAI: split the prompt so the stable
                // instruction prefix goes in the (cacheable) system block and the per-turn body
                // stays in the user message. The Anthropic driver marks the system block
                // cache_control: ephemeral. `system . "\n\n" . body` equals build() byte-for-byte,
                // so the model sees the same content — only the role boundary changes.
                $parts = $this->promptBuilder->buildParts($message, $context, $redactedState, $options);
                $request = (new AIRequest(
                    prompt: $parts['body'],
                    engine: $engine,
                    model: $model,
                    maxTokens: $maxTokens,
                    temperature: $temperature,
                    metadata: ['context' => 'ai_native_runtime']
                ))->withSystemPrompt($parts['system']);
            } else {
                // OpenAI (default) and others already cache the longest common prompt prefix
                // automatically; keep the single-message prompt unchanged.
                $request = new AIRequest(
                    prompt: $this->promptBuilder->build($message, $context, $redactedState, $options),
                    engine: $engine,
                    model: $model,
                    maxTokens: $maxTokens,
                    temperature: $temperature,
                    metadata: ['context' => 'ai_native_runtime']
                );
            }

            $response = $this->ai->generate($request);
        } catch (Throwable $exception) {
            return [
                'action' => 'ask_user',
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : $this->runtimeText('ai-engine::runtime.responses.runtime_failed', 'AI runtime failed.'),
            ];
        }

        if (!$response->isSuccessful()) {
            return ['action' => 'ask_user', 'message' => $response->getError() ?? $this->runtimeText('ai-engine::runtime.responses.runtime_failed', 'AI runtime failed.')];
        }

        return $this->parser->parse($response->getContent());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function parallelEnabled(array $options): bool
    {
        // Per-run override always wins (mirrors maxSteps()); default preserves today's behavior.
        if (array_key_exists('parallel_tools', $options) && $options['parallel_tools'] !== null) {
            return (bool) $options['parallel_tools'];
        }

        return (bool) config('ai-agent.ai_native.parallel_tools', false);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function hasParallelToolCalls(array $plan): bool
    {
        foreach (['tool_calls', 'calls', 'parallel_tool_calls'] as $key) {
            $raw = $plan[$key] ?? null;
            if (!is_array($raw)) {
                continue;
            }

            foreach ($raw as $entry) {
                if (is_array($entry) && trim((string) ($entry['tool'] ?? $entry['tool_name'] ?? '')) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Engines that need an explicit system/cacheable-prefix split. OpenAI-family engines cache
     * the longest common prompt prefix automatically, so only Anthropic (Claude) benefits from
     * the split + cache_control breakpoint.
     */
    private function supportsSystemPromptCaching(string $engine): bool
    {
        return in_array(strtolower(trim($engine)), ['anthropic', 'claude'], true);
    }

    private function maxSteps(array $options): int
    {
        // Per-run override always wins (preserves existing behavior/tests).
        if (array_key_exists('max_steps', $options) && $options['max_steps'] !== null) {
            return max(1, (int) $options['max_steps']);
        }

        $hard = max(1, (int) config('ai-agent.ai_native.max_steps', 6));

        if ((bool) config('ai-agent.ai_native.budget.enabled', false)) {
            $budget = max(1, (int) config('ai-agent.ai_native.budget.max_steps', 16));

            return max($hard, $budget);
        }

        return $hard;
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
     * When expose_reasoning is on, accumulate the planner's optional one-sentence
     * rationale into a per-turn trace threaded through the loop. The factory copies
     * this onto the returned AgentResponse metadata. No-op when the flag is off.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     */
    private function captureReasoning(array &$state, array $plan, array $options): void
    {
        if (!$this->exposeReasoning($options)) {
            return;
        }

        $reasoning = $plan['reasoning'] ?? null;
        if (!is_string($reasoning)) {
            return;
        }

        $reasoning = trim($reasoning);
        if ($reasoning === '') {
            return;
        }

        $state['reasoning_trace'] = array_values((array) ($state['reasoning_trace'] ?? []));
        $state['reasoning_trace'][] = $reasoning;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function exposeReasoning(array $options): bool
    {
        if (array_key_exists('expose_reasoning', $options) && $options['expose_reasoning'] !== null) {
            return (bool) $options['expose_reasoning'];
        }

        return (bool) config('ai-agent.ai_native.expose_reasoning', false);
    }

    /**
     * When plan_timeline is on, capture the planner's optional forward-looking
     * "steps":["..."] list into a per-turn snapshot {steps, current} threaded
     * through the loop. The current index advances each planning round-trip that
     * yields a non-empty steps list (1-based, clamped to the step count). The
     * factory copies this onto the returned AgentResponse metadata['plan'].
     * No-op when the flag is off, preserving today's behavior.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     */
    private function capturePlanTimeline(array &$state, array $plan, array $options): void
    {
        if (!$this->planTimelineEnabled($options)) {
            return;
        }

        $raw = $plan['steps'] ?? null;
        if (!is_array($raw)) {
            return;
        }

        $steps = array_values(array_filter(
            array_map(static fn (mixed $step): string => is_string($step) ? trim($step) : '', $raw),
            static fn (string $step): bool => $step !== ''
        ));

        if ($steps === []) {
            return;
        }

        $snapshot = is_array($state['plan_timeline'] ?? null) ? $state['plan_timeline'] : ['steps' => [], 'current' => 0];
        $current = (int) ($snapshot['current'] ?? 0) + 1;

        $state['plan_timeline'] = [
            'steps' => $steps,
            // 1-based current index, clamped to the latest step count so it always
            // reads "step X of Y" with X <= Y for the presenter.
            'current' => max(1, min($current, count($steps))),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function planTimelineEnabled(array $options): bool
    {
        if (array_key_exists('plan_timeline', $options) && $options['plan_timeline'] !== null) {
            return (bool) $options['plan_timeline'];
        }

        return (bool) config('ai-agent.ai_native.plan_timeline', false);
    }

    /**
     * In-loop context compaction: before nextPlan() builds the planner prompt,
     * trim the oldest recorded tool results so a smaller runtime state is sent
     * to the planner, enabling longer trajectories. Gated entirely behind a
     * config flag whose default (false) preserves today's behavior.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    private function compactStateForPlanner(array &$state, UnifiedActionContext $context, array $options): void
    {
        if (!(bool) config('ai-agent.ai_native.compaction.enabled', false)) {
            return;
        }

        $threshold = max(1, (int) config('ai-agent.ai_native.compaction.threshold', 12));
        $keep = max(1, (int) config('ai-agent.ai_native.compaction.keep_recent_results', 6));

        $results = is_array($state['tool_results'] ?? null) ? array_values($state['tool_results']) : [];
        if (count($results) > $threshold) {
            $drop = count($results) - $keep;
            if ($drop > 0) {
                $state['tool_results'] = array_slice($results, -$keep);
                $state['compacted_tool_results'] = (int) ($state['compacted_tool_results'] ?? 0) + $drop;
            }
        }

        if ((bool) config('ai-agent.ai_native.compaction.compact_conversation', false)) {
            try {
                $this->resolveCompactor()->compact($context, $options);
            } catch (Throwable) {
                // Conversation compaction is a best-effort prompt-size optimization;
                // never let it break the agentic loop.
            }
        }
    }

    private function resolveCompactor(): ConversationContextCompactor
    {
        return $this->compactor ??= app(ConversationContextCompactor::class);
    }

}
