<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeFinalToolPolicy
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillMatcher $matcher,
        private readonly AiNativeConfirmationIntent $confirmationIntent,
        private readonly ?ToolRegistry $tools = null
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function requirementApplies(string $message, array $state, array $options): bool
    {
        // An explicitly selected skill always enforces its final tool. A bare
        // runtime_scope=skill must NOT: that scope only says the runtime uses
        // skills — treating it as "every message is a skill task" forced final
        // tools onto unrelated messages (live-proven: a host preamble
        // containing a trigger word seeded an objective, and every turn then
        // looped on final_without_required_final_tool).
        if (($options['skill_id'] ?? '') !== '') {
            return true;
        }

        if ($this->hasRuntimeFeedback($state, 'final_tool_required_before_confirmation_question')) {
            return true;
        }

        if ($this->confirmationIntent->isApproval($message)) {
            return true;
        }

        if ($this->matcher->selectedSkillIdForActiveTask($state, $options) !== '') {
            return $this->matcher->messageMatchesSkill($message, $options)
                || $this->activePayloadMissesRequiredFinalToolParams($state, $options);
        }

        if (!$this->matcher->messageMatchesSkill($message, $options)) {
            return false;
        }

        return (array) data_get($state, 'task_frame.current_payload', []) !== []
            || (array) ($state['tool_results'] ?? []) !== [];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    public function requiredTools(string $message, array $options, array $state = []): array
    {
        $normalized = mb_strtolower($message);
        $selectedSkill = $this->matcher->selectedSkillIdForActiveTask($state, $options);
        $tools = [];

        foreach ($this->skills->skills() as $skill) {
            if ($selectedSkill !== '' && $skill->id !== $selectedSkill) {
                continue;
            }

            // An active objective alone must not force a final tool: the
            // objective may have been seeded by loose trigger noise (e.g. a
            // host preamble containing a trigger word). Unless the skill was
            // EXPLICITLY selected, force only when the CURRENT message matches
            // the skill or the task shows genuine engagement with it — a
            // payload collected on prior turns, or a successful result from
            // one of the skill's own tools.
            if ($selectedSkill !== ''
                && trim((string) ($options['skill_id'] ?? '')) === ''
                && !$this->matcher->skillMatchesMessage($skill, $normalized)
                && !$this->skillEngaged($skill, $state)) {
                continue;
            }

            if ($selectedSkill === '' && !$this->matcher->skillMatchesMessage($skill, $normalized)) {
                continue;
            }

            $metadata = is_array($skill->metadata ?? null) ? $skill->metadata : [];
            foreach ((array) ($metadata['final_tools'] ?? []) as $toolName) {
                if (is_string($toolName) && trim($toolName) !== '') {
                    $tools[] = trim($toolName);
                }
            }

            $finalTool = $metadata['final_tool'] ?? null;
            if (is_string($finalTool) && trim($finalTool) !== '') {
                $tools[] = trim($finalTool);
            }
        }

        return array_values(array_unique($tools));
    }

    /**
     * Genuine engagement with a skill's task: a working payload collected on
     * prior turns, or a successful result from one of the skill's OWN tools.
     * Tool activity from unrelated tools does not count — that is exactly the
     * noise-seeded-objective case this guards against.
     *
     * @param array<string, mixed> $state
     */
    private function skillEngaged(mixed $skill, array $state): bool
    {
        if ((array) data_get($state, 'task_frame.current_payload', []) !== []) {
            return true;
        }

        $skillTools = array_map(static fn (mixed $tool): string => (string) $tool, (array) ($skill->tools ?? []));
        if ($skillTools === []) {
            return false;
        }

        foreach ((array) ($state['tool_results'] ?? []) as $toolResult) {
            if (!is_array($toolResult)) {
                continue;
            }
            $result = is_array($toolResult['result'] ?? null) ? $toolResult['result'] : [];
            if (($result['success'] ?? false) === true
                && in_array((string) ($toolResult['tool'] ?? ''), $skillTools, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function hasSuccessfulToolResult(array $state, string $toolName): bool
    {
        foreach ((array) ($state['tool_results'] ?? []) as $toolResult) {
            if (!is_array($toolResult) || (string) ($toolResult['tool'] ?? '') !== $toolName) {
                continue;
            }

            $result = is_array($toolResult['result'] ?? null) ? $toolResult['result'] : [];
            if (($result['success'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasRuntimeFeedback(array $state, string $reason): bool
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
    private function activePayloadMissesRequiredFinalToolParams(array $state, array $options): bool
    {
        $payload = (array) data_get($state, 'task_frame.current_payload', []);
        if ($payload === []) {
            return false;
        }

        foreach ($this->requiredTools('', $options, $state) as $toolName) {
            $tool = $this->tools?->get($toolName);
            if ($tool === null) {
                continue;
            }

            foreach ($tool->getParameters() as $name => $definition) {
                if (!is_array($definition) || ($definition['required'] ?? false) !== true) {
                    continue;
                }

                if (!$this->payloadHasValue($payload, (string) $name)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadHasValue(array $payload, string $key): bool
    {
        if (!array_key_exists($key, $payload)) {
            return false;
        }

        $value = $payload[$key];

        return !($value === null || $value === '' || $value === []);
    }
}
