<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;

class AiNativeFinalToolPolicy
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillMatcher $matcher,
        private readonly AiNativeConfirmationIntent $confirmationIntent
    ) {}

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function requirementApplies(string $message, array $state, array $options): bool
    {
        if (($options['skill_id'] ?? '') !== '' || ($options['runtime_scope'] ?? '') === 'skill') {
            return true;
        }

        if ($this->hasRuntimeFeedback($state, 'final_tool_required_before_confirmation_question')) {
            return true;
        }

        if ($this->confirmationIntent->isApproval($message)) {
            return true;
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
}
