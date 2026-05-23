<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeSkillPolicy
{
    private AiNativeLookupPolicy $lookupPolicy;
    private AiNativeSkillMatcher $matcher;
    private AiNativeConfirmationIntent $confirmationIntent;
    private AiNativeFinalToolPolicy $finalToolPolicy;
    private AiNativeSkillPayloadResolver $payloadResolver;

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        ToolRegistry $tools,
        private readonly IntentSignalService $signals,
        ?AiNativeLookupPolicy $lookupPolicy = null,
        ?AiNativeSkillMatcher $matcher = null,
        ?AiNativeConfirmationIntent $confirmationIntent = null,
        ?AiNativeFinalToolPolicy $finalToolPolicy = null,
        ?AiNativeSkillPayloadResolver $payloadResolver = null
    ) {
        $this->matcher = $matcher ?? new AiNativeSkillMatcher($skills);
        $this->lookupPolicy = $lookupPolicy ?? new AiNativeLookupPolicy($skills, $tools, $this->matcher);
        $this->confirmationIntent = $confirmationIntent ?? new AiNativeConfirmationIntent($signals);
        $this->finalToolPolicy = $finalToolPolicy ?? new AiNativeFinalToolPolicy($skills, $this->matcher, $this->confirmationIntent);
        $this->payloadResolver = $payloadResolver ?? new AiNativeSkillPayloadResolver($skills, $this->matcher);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function seedActiveTask(string $message, array &$state, array $options): void
    {
        if (($state['task_frame']['status'] ?? null) === 'completed') {
            unset($state['task_frame']['active_objective']);
        }

        if (!empty($state['task_frame']['active_objective'])) {
            return;
        }

        $objective = $this->matcher->matchedSkillId($message, $options);
        if ($objective === null) {
            return;
        }

        $state['task_frame'] = array_merge((array) ($state['task_frame'] ?? []), [
            'active_objective' => $objective,
            'status' => 'working',
        ]);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function hasRecentContext(array $state): bool
    {
        if (is_array(data_get($state, 'task_frame.current_payload')) && data_get($state, 'task_frame.current_payload') !== []) {
            return true;
        }

        return (array) ($state['recent_outcomes'] ?? []) !== []
            || (array) data_get($state, 'task_frame.recent_outcomes', []) !== []
            || (array) data_get($state, 'task_frame.completed_writes', []) !== [];
    }

    /**
     * @param array<string, mixed> $state
     */
    public function hasRuntimeFeedback(array $state, string $reason): bool
    {
        foreach ((array) ($state['runtime_feedback'] ?? []) as $feedback) {
            if (is_array($feedback) && ($feedback['reason'] ?? null) === $reason) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function needsRequiredFinalToolBeforeFinal(string $message, array $state, array $options): bool
    {
        if (!$this->finalToolPolicy->requirementApplies($message, $state, $options)) {
            return false;
        }

        $requiredTools = $this->requiredFinalTools($message, $options, $state);
        if ($requiredTools === []) {
            return false;
        }

        foreach ($requiredTools as $toolName) {
            if ($this->finalToolPolicy->hasSuccessfulToolResult($state, $toolName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function needsFinalToolBeforeAsk(string $message, array $state, array $options, array $plan): bool
    {
        if (is_array($state['pending_tool'] ?? null)) {
            return false;
        }

        if ((array) ($plan['required_inputs'] ?? []) !== []) {
            return false;
        }

        if ((array) data_get($state, 'task_frame.current_payload', []) === []) {
            return false;
        }

        $requiredTools = $this->requiredFinalTools($message, $options, $state);
        if ($requiredTools === []) {
            return false;
        }

        foreach ($requiredTools as $toolName) {
            if ($this->finalToolPolicy->hasSuccessfulToolResult($state, $toolName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    public function requiredFinalTools(string $message, array $options, array $state = []): array
    {
        return $this->finalToolPolicy->requiredTools($message, $options, $state);
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function payloadFromPlan(array $plan, array $state, array $options): array
    {
        return $this->payloadResolver->fromPlan($plan, $state, $options);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function needsToolEvidenceBeforeFinal(string $message, array $state, array $options, array $plan): bool
    {
        if (!empty($state['tool_results']) || is_array($state['pending_tool'] ?? null)) {
            return false;
        }

        if ((array) ($plan['data'] ?? []) !== []) {
            return false;
        }

        return $this->matcher->messageMatchesSkill($message, $options);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function needsNextStepAfterMissingLookup(string $message, array $state, array $options, array $plan): bool
    {
        if (!$this->matcher->messageMatchesSkill($message, $options)) {
            return false;
        }

        if ((array) ($plan['data'] ?? []) !== []) {
            return false;
        }

        $latest = $this->lookupPolicy->latestToolResult($state);
        if ($latest === []) {
            return false;
        }

        $toolName = strtolower((string) ($latest['tool'] ?? ''));
        if (!str_contains($toolName, 'find') && !str_contains($toolName, 'search') && !str_contains($toolName, 'lookup')) {
            return false;
        }

        $result = (array) ($latest['result'] ?? []);
        $data = (array) ($result['data'] ?? []);

        return array_key_exists('found', $data) && $data['found'] === false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function needsLookupBeforeAsk(string $message, array $state, array $options, array $plan): bool
    {
        if ($this->hasRuntimeFeedback($state, 'suggested_tool_continuation_abandoned')) {
            return false;
        }

        return $this->lookupPolicy->needsLookupBeforeAsk($message, $state, $options, $plan);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function needsRecentContextBeforeAsk(array $state, bool $hadRecentContextBeforeTurn): bool
    {
        if (!$hadRecentContextBeforeTurn) {
            return false;
        }

        if (is_array($state['pending_tool'] ?? null)) {
            return false;
        }

        if ($this->hasRuntimeFeedback($state, 'recent_context_available')) {
            return false;
        }

        if ($this->hasRuntimeFeedback($state, 'suggested_tool_continuation_abandoned')) {
            return false;
        }

        if ($this->lookupPolicy->latestLookupWasNotFound($state)) {
            return false;
        }

        return $this->hasRecentContext($state);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function needsLookupBeforeWrite(string $toolName, array $arguments, array $state, array $options): bool
    {
        return $this->lookupPolicy->needsLookupBeforeWrite($toolName, $arguments, $state, $options, $this->requiredFinalTools('', $options, $state));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function matchingLookupToolsForWrite(string $toolName, array $state, array $options): array
    {
        return $this->lookupPolicy->matchingLookupToolsForWrite($toolName, $state, $options);
    }

}
