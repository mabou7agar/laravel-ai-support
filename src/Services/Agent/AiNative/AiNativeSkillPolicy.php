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
    private ToolRegistry $tools;

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
        $this->tools = $tools;
        $this->matcher = $matcher ?? new AiNativeSkillMatcher($skills);
        $this->lookupPolicy = $lookupPolicy ?? new AiNativeLookupPolicy($skills, $tools, $this->matcher);
        $this->confirmationIntent = $confirmationIntent ?? new AiNativeConfirmationIntent($signals);
        $this->finalToolPolicy = $finalToolPolicy ?? new AiNativeFinalToolPolicy($skills, $this->matcher, $this->confirmationIntent, $tools);
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
     * Whether the message is itself a request that a skill handles (it matches a trigger),
     * as opposed to a reply about the task already in progress.
     */
    public function startsSkillTask(string $message): bool
    {
        return $this->matcher->matchedSkillId($message, []) !== null;
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

        if ((array) data_get($state, 'task_frame.current_payload', []) === []) {
            return false;
        }

        $requiredTools = $this->requiredFinalTools($message, $options, $state);
        if ($requiredTools === []) {
            return false;
        }

        // Asking for inputs is fine when at least one of them is something the final tool
        // needs. Asking only for fields the tool treats as optional (dates, notes, terms) is
        // a detour: the tool fills defaults and shows its own confirmation. Push back once
        // per turn, so a planner that still insists is not looped to the step limit.
        $requiredInputs = (array) ($plan['required_inputs'] ?? []);
        if ($requiredInputs !== []) {
            if ($this->hasRuntimeFeedback($state, 'final_tool_required_before_confirmation_question')
                || !$this->onlyOptionalFinalToolInputs($requiredInputs, $requiredTools, (array) data_get($state, 'task_frame.current_payload', []))) {
                return false;
            }
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
        if ($this->needsHostRequiredToolEvidence($state, $options)) {
            return true;
        }

        if (!empty($state['tool_results']) || is_array($state['pending_tool'] ?? null)) {
            return false;
        }

        if ((array) ($plan['data'] ?? []) !== []) {
            return false;
        }

        return $this->matcher->messageMatchesSkill($message, $options);
    }

    /**
     * Hosts may declare that a turn is not complete until one of a small set of
     * outcome tools succeeds. This is intentionally generic: the runtime does
     * not infer a workflow or choose a tool, it only rejects unsupported prose
     * completion and lets the model re-plan with structured feedback.
     *
     * Evidence is scoped to the current turn. A successful matching tool from a
     * previous turn must not let a later action request finish without acting.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function needsHostRequiredToolEvidence(array $state, array $options): bool
    {
        $requiredTools = $this->hostRequiredToolEvidence($options);
        if ($requiredTools === []) {
            return false;
        }

        $turnOutcomeCount = max(0, (int) ($state['turn_outcome_count'] ?? 0));
        if ($turnOutcomeCount === 0) {
            return true;
        }

        $turnOutcomes = array_slice((array) ($state['recent_outcomes'] ?? []), -$turnOutcomeCount);
        foreach ($turnOutcomes as $outcome) {
            if (!is_array($outcome) || ($outcome['success'] ?? false) !== true) {
                continue;
            }

            if (in_array((string) ($outcome['tool'] ?? ''), $requiredTools, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function hostRequiredToolEvidence(array $options): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $tool): string => trim((string) $tool),
                (array) ($options['required_tool_evidence'] ?? []),
            ),
            static fn (string $tool): bool => $tool !== '',
        )));
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    private function matchedSkillDeclaresFinalTool(string $message, array $options): bool
    {
        $skillId = $this->matcher->matchedSkillId($message, $options);
        foreach ($this->skills->skills() as $skill) {
            if ($skill->id !== $skillId) {
                continue;
            }
            $metadata = (array) ($skill->metadata ?? []);

            return trim((string) ($metadata['final_tool'] ?? '')) !== '' || (array) ($metadata['final_tools'] ?? []) !== [];
        }

        return false;
    }

    public function needsNextStepAfterMissingLookup(string $message, array $state, array $options, array $plan): bool
    {
        if (!$this->matcher->messageMatchesSkill($message, $options)) {
            return false;
        }

        if ((array) ($plan['data'] ?? []) !== []) {
            return false;
        }

        // "Not found" is a complete answer to a plain lookup. Only push for a next step
        // (ask, offer to create, another tool) when the lookup serves a write: a draft or
        // pending write exists, or the matched skill declares a final tool. Otherwise the
        // planner is forced into proposing creates the user never asked for.
        if ((array) data_get($state, 'task_frame.current_payload', []) === []
            && !is_array($state['pending_tool'] ?? null)
            && !is_array(data_get($state, 'task_frame.pending_tool'))
            && !$this->matchedSkillDeclaresFinalTool($message, $options)) {
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
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function relationCreateNeedsLookupMiss(string $toolName, array $arguments, array $state, array $options): bool
    {
        return $this->lookupPolicy->relationCreateNeedsLookupMiss($toolName, $arguments, $state, $options);
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

    /**
     * True when the payload already holds every required parameter of the final tools and
     * every requested input names a parameter those tools mark optional. Unknown names count
     * as genuinely needed.
     *
     * @param array<int|string, mixed> $requiredInputs
     * @param array<int, string>       $finalTools
     * @param array<string, mixed>     $payload
     */
    private function onlyOptionalFinalToolInputs(array $requiredInputs, array $finalTools, array $payload): bool
    {
        $optional = null;
        foreach ($finalTools as $toolName) {
            $tool = $this->tools->get($toolName);
            if ($tool === null) {
                return false;
            }

            $toolOptional = [];
            foreach ($tool->getParameters() as $name => $definition) {
                if (!is_array($definition) || ($definition['required'] ?? false) !== true) {
                    $toolOptional[] = mb_strtolower((string) $name);
                    continue;
                }

                $value = $payload[(string) $name] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    return false;
                }
            }
            $optional = $optional === null ? $toolOptional : array_values(array_intersect($optional, $toolOptional));
        }

        if ($optional === null || $optional === []) {
            return false;
        }

        foreach ($requiredInputs as $input) {
            $name = is_array($input)
                ? (string) ($input['name'] ?? $input['id'] ?? $input['field'] ?? '')
                : (string) $input;
            $name = mb_strtolower(trim($name));
            if ($name === '' || !in_array($name, $optional, true)) {
                return false;
            }
        }

        return true;
    }
}
