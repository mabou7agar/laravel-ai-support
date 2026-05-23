<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class AiNativeFinalActionHandler
{
    public function __construct(
        private readonly AiNativeSkillPolicy $skillPolicy,
        private readonly AiNativeSuggestedToolContinuation $suggestedToolContinuation,
        private readonly AiNativeStateStore $stateStore,
        private readonly AiNativeResponseFactory $responses
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function handle(string $message, UnifiedActionContext $context, array &$state, array $options, array $plan): AiNativeActionOutcome
    {
        if ($this->suggestedToolContinuation->mustContinue($state)) {
            $state['runtime_feedback'][] = [
                'reason' => 'final_before_suggested_tool_continuation',
                'message' => 'The previous tool returned suggested tools and a confirmed write is waiting to be retried. Call the suggested tool or retry the confirmed write before returning a final answer.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsToolEvidenceBeforeFinal($message, $state, $options, $plan)) {
            $state['runtime_feedback'][] = [
                'reason' => 'final_without_tool_evidence',
                'message' => 'The previous plan tried to finish an action request without tool evidence. Call a relevant tool or ask the user for missing data.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsNextStepAfterMissingLookup($message, $state, $options, $plan)) {
            $state['runtime_feedback'][] = [
                'reason' => 'missing_lookup_without_next_step',
                'message' => 'The latest lookup did not find a required record. Ask for missing input, request confirmation to create it, or call the next appropriate tool.',
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        if ($this->skillPolicy->needsRequiredFinalToolBeforeFinal($message, $state, $options)) {
            $state['runtime_feedback'][] = [
                'reason' => 'final_without_required_final_tool',
                'message' => 'The matched skill declares a final tool. If the payload is ready, call the confirming final tool now; Laravel will pause for confirmation before execution. Do not return a final answer until that final tool has executed successfully, or ask the user for missing input.',
                'required_tools' => $this->skillPolicy->requiredFinalTools($message, $options, $state),
            ];
            $this->stateStore->put($context, $state);

            return AiNativeActionOutcome::continueLoop();
        }

        return AiNativeActionOutcome::response($this->responses->final($context, $state, $this->planMessage($plan), (array) ($plan['data'] ?? [])));
    }

    private function planMessage(array $plan): string
    {
        $message = trim((string) ($plan['message'] ?? ''));

        return $message !== '' ? $message : 'Done.';
    }
}
