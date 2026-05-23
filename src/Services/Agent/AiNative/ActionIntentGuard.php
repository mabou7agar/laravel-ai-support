<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

class ActionIntentGuard
{
    private AiNativeConfirmationIntent $confirmationIntent;

    public function __construct(
        private readonly IntentSignalService $signals,
        ?AiNativeConfirmationIntent $confirmationIntent = null
    ) {
        $this->confirmationIntent = $confirmationIntent ?? new AiNativeConfirmationIntent($signals);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function shouldGatePlan(
        string $message,
        array $state,
        array $options,
        array $plan,
        ?AgentTool $tool = null,
        bool $planHasActionPayload = false
    ): bool {
        if ($this->latestTurnCanStartOrContinueAction($message, $state, $options)) {
            return false;
        }

        $action = strtolower(trim((string) ($plan['action'] ?? 'final')));
        if ($action === 'tool_call') {
            return $tool instanceof AgentTool && $this->toolStartsWriteFlow($tool);
        }

        if ($action !== 'ask_user') {
            return false;
        }

        if ((array) ($plan['required_inputs'] ?? []) !== []) {
            return true;
        }

        return $planHasActionPayload;
    }

    /**
     * @return array{reason:string,message:string}
     */
    public function nonActionFeedback(): array
    {
        return [
            'reason' => 'latest_message_not_action_request',
            'message' => 'The latest user message provides context or background but does not clearly ask for an application action. Do not ask for action-only required fields and do not call write tools. Reply conversationally and let response suggestions expose possible next actions.',
        ];
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function latestTurnCanStartOrContinueAction(string $message, array $state, array $options): bool
    {
        if (($options['skill_id'] ?? '') !== '' || ($options['runtime_scope'] ?? '') === 'skill') {
            return true;
        }

        if (is_array($state['pending_tool'] ?? null) || is_array($state['suggested_tool_continuation'] ?? null)) {
            return true;
        }

        $taskStatus = (string) data_get($state, 'task_frame.status', '');
        if ($taskStatus !== 'completed' && trim((string) data_get($state, 'task_frame.active_objective', '')) !== '') {
            return true;
        }

        if ($taskStatus !== 'completed' && (array) ($state['tool_results'] ?? []) !== []) {
            return true;
        }

        if ($this->isPendingToolApproval($message) || $this->signals->isNegative($message)) {
            return true;
        }

        if ($taskStatus !== 'completed' && (array) data_get($state, 'task_frame.current_payload', []) !== []) {
            return true;
        }

        return $this->messageHasActionIntent($message);
    }

    private function toolStartsWriteFlow(AgentTool $tool): bool
    {
        return $tool->requiresConfirmation()
            || strtolower((string) $tool->getToolKind()) === 'write';
    }

    private function messageHasActionIntent(string $message): bool
    {
        $normalized = $this->withoutCommonStopwords($message);
        if ($normalized === '') {
            return false;
        }

        foreach ($this->actionIntentTerms() as $term) {
            $term = $this->withoutCommonStopwords($term);
            if ($term !== '' && preg_match('/\b'.preg_quote($term, '/').'\b/u', $normalized) === 1) {
                return true;
            }
        }

        return $this->looksLikeContinuationRequest($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function actionIntentTerms(): array
    {
        return array_values(array_filter(
            array_map(static fn (mixed $term): string => trim((string) $term), (array) config('ai-agent.ai_native.action_intent_terms', [])),
            static fn (string $term): bool => $term !== ''
        ));
    }

    private function looksLikeContinuationRequest(string $normalized): bool
    {
        $terms = array_values(array_filter(
            array_map(static fn (mixed $term): string => trim((string) $term), (array) config('ai-agent.skills.continuation_terms', [])),
            static fn (string $term): bool => $term !== ''
        ));

        foreach ($terms as $term) {
            $term = $this->withoutCommonStopwords($term);
            if ($term !== '' && preg_match('/\b'.preg_quote($term, '/').'\b/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isPendingToolApproval(string $message): bool
    {
        return $this->confirmationIntent->isApproval($message);
    }

    private function withoutCommonStopwords(string $value): string
    {
        $words = preg_split('/\s+/u', preg_replace('/[^\pL\pN_]+/u', ' ', mb_strtolower($value)) ?: '') ?: [];
        $stopwords = array_flip((array) config('ai-agent.ai_native.trigger_stopwords', [
            'a',
            'an',
            'the',
            'to',
            'for',
            'with',
            'me',
            'please',
        ]));

        return implode(' ', array_values(array_filter(
            $words,
            static fn (string $word): bool => $word !== '' && !isset($stopwords[$word])
        )));
    }
}
