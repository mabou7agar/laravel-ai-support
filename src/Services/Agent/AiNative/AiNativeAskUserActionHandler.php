<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class AiNativeAskUserActionHandler
{
    public function __construct(
        private readonly AiNativeSkillPolicy $skillPolicy,
        private readonly AiNativeSuggestedToolContinuation $suggestedToolContinuation,
        private readonly ActionIntentGuard $actionIntentGuard,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeResponseFactory $responses
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
        array $plan,
        bool $hadRecentContextBeforeTurn
    ): AiNativeActionOutcome {
        if ($this->shouldGateNewActionPlan($message, $state, $options, $plan)) {
            if ($this->skillPolicy->hasRuntimeFeedback($state, 'latest_message_not_action_request')) {
                return AiNativeActionOutcome::response($this->responses->nonActionContext($context, $state));
            }

            $state['runtime_feedback'][] = $this->actionIntentGuard->nonActionFeedback();
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->suggestedToolContinuation->mustContinue($state)) {
            $state['runtime_feedback'][] = [
                'reason' => 'suggested_tool_required',
                'message' => 'The previous tool returned suggested tools that can resolve missing data. Do not ask the user until suggested lookup/search tools have been attempted and the confirmed write has been retried.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsFinalToolBeforeAsk($message, $state, $options, $plan)) {
            $state['runtime_feedback'][] = [
                'reason' => 'final_tool_required_before_confirmation_question',
                'message' => 'The plan asked the user to confirm a ready payload without structured missing inputs. Call the skill final tool with the current payload instead; Laravel will handle the confirmation prompt before execution.',
                'required_tools' => $this->skillPolicy->requiredFinalTools($message, $options, $state),
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsRecentContextBeforeAsk($state, $hadRecentContextBeforeTurn)) {
            $state['runtime_feedback'][] = [
                'reason' => 'recent_context_available',
                'message' => 'The user asked about a recent, current, or previously completed object and the context snapshot already contains relevant recent outcomes, current payload, or completed writes. Answer from that context or call a relevant read/list tool instead of asking the user for an identifier.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsLookupBeforeAsk($message, $state, $options, $plan)) {
            $state['runtime_feedback'][] = [
                'reason' => empty($state['tool_results']) ? 'ask_without_lookup' : 'ask_with_unused_lookup_tools',
                'message' => 'The previous plan asked for user data before trying available lookup tools. Call a relevant lookup/search/find tool first when a skill can resolve existing records.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        $this->stateStore->put($context, $state);

        return AiNativeActionOutcome::response($this->responses->needsUserInput(
            $context,
            $state,
            $this->planMessage($plan),
            (array) ($plan['required_inputs'] ?? []),
            (array) ($plan['data'] ?? [])
        ));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    private function shouldGateNewActionPlan(string $message, array $state, array $options, array $plan): bool
    {
        return $this->actionIntentGuard->shouldGatePlan(
            $message,
            $state,
            $options,
            $plan,
            null,
            $this->skillPolicy->payloadFromPlan($plan, $state, $options) !== []
        );
    }

    private function planMessage(array $plan): string
    {
        $message = trim((string) ($plan['message'] ?? ''));

        return $message !== '' ? $message : 'Done.';
    }
}
