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
                || !$this->onlyOptionalFinalToolInputs($requiredInputs, $requiredTools, (array) data_get($state, 'task_frame.current_payload', []), $state)) {
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
     * nothing the plan asks for is one of those tools' required parameters: each requested
     * input either names a parameter the tools mark optional, or names something that is
     * not a parameter of any final tool at all (e.g. "currency" on an invoice tool that has
     * no currency field). The tool cannot use such an answer, and its own validation still
     * asks when something is genuinely missing. An input matching a required parameter
     * keeps the question flowing, and so does a name the payload or schema holds as a nested
     * field (items.*.product_name) or any question once the final tool itself has already
     * reported a problem (its answer, not the planner's guess, is driving the question).
     *
     * @param array<int|string, mixed> $requiredInputs
     * @param array<int, string>       $finalTools
     * @param array<string, mixed>     $payload
     * @param array<string, mixed>     $state
     */
    private function onlyOptionalFinalToolInputs(array $requiredInputs, array $finalTools, array $payload, array $state = []): bool
    {
        $required = [];
        $known = [];
        $nested = $this->nestedKeys($payload);
        foreach ((array) ($state['tool_results'] ?? []) as $entry) {
            if (is_array($entry)
                && ($entry['task_closed'] ?? false) !== true
                && in_array((string) ($entry['tool'] ?? ''), $finalTools, true)
                && (($entry['result']['success'] ?? false) !== true || ($entry['result']['data']['needs_user_input'] ?? false) === true)) {
                return false;
            }
        }
        foreach ($finalTools as $toolName) {
            $tool = $this->tools->get($toolName);
            if ($tool === null) {
                return false;
            }

            $parameters = $tool->getParameters();
            if ($parameters === []) {
                // A tool without a declared schema gives no basis to call a question useless.
                return false;
            }

            foreach ($parameters as $name => $definition) {
                $normalized = $this->normalizeInputName((string) $name);
                $known[$normalized] = true;
                if (is_array($definition)) {
                    foreach (array_merge(array_keys((array) ($definition['properties'] ?? [])), array_keys((array) ($definition['items']['properties'] ?? []))) as $property) {
                        $nested[$this->normalizeInputName((string) $property)] = true;
                    }
                }
                if (!is_array($definition) || ($definition['required'] ?? false) !== true) {
                    continue;
                }

                $required[$normalized] = true;
                $value = $payload[(string) $name] ?? null;
                if ($value === null || $value === '' || $value === []) {
                    return false;
                }
            }
        }

        foreach ($requiredInputs as $input) {
            $name = is_array($input)
                ? (string) ($input['name'] ?? $input['id'] ?? $input['field'] ?? '')
                : (string) $input;
            $name = $this->normalizeInputName($name);
            if ($name === '') {
                return false;
            }

            if (isset($nested[$name])) {
                return false;
            }

            // "customer" asks for the customer_id parameter.
            foreach ([$name, $name . '_id', preg_replace('/_id$/', '', $name)] as $candidate) {
                if (isset($required[$candidate])) {
                    return false;
                }
            }
        }

        return $known !== [];
    }

    /**
     * Normalized keys found below the top level of a payload (keys of list items and
     * nested objects).
     *
     * @param array<mixed> $value
     * @return array<string, true>
     */
    private function nestedKeys(array $value, bool $includeTopLevel = false, int $depth = 0): array
    {
        $keys = [];
        if ($depth > 4) {
            return $keys;
        }

        foreach ($value as $key => $child) {
            if ($includeTopLevel && is_string($key)) {
                $keys[$this->normalizeInputName($key)] = true;
            }
            if (is_array($child)) {
                $keys += $this->nestedKeys($child, true, $depth + 1);
            }
        }

        return $keys;
    }

    private function normalizeInputName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return trim((string) preg_replace('/[\s\-.]+/u', '_', $name), '_');
    }
}
