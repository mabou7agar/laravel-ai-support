<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeLookupPolicy
{
    private AiNativeToolClassifier $classifier;
    private AiNativeLookupLabelResolver $labels;
    private AiNativeLookupAskDetector $askDetector;

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly ToolRegistry $tools,
        private readonly ?AiNativeSkillMatcher $matcher = null,
        ?AiNativeToolClassifier $classifier = null,
        ?AiNativeLookupLabelResolver $labels = null,
        ?AiNativeLookupAskDetector $askDetector = null
    ) {
        $this->classifier = $classifier ?? new AiNativeToolClassifier($tools);
        $this->labels = $labels ?? new AiNativeLookupLabelResolver();
        $this->askDetector = $askDetector ?? new AiNativeLookupAskDetector();
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plan
     */
    public function needsLookupBeforeAsk(string $message, array $state, array $options, array $plan): bool
    {
        if (is_array($state['pending_tool'] ?? null)) {
            return false;
        }

        $lookupTools = $this->matchedSkillLookupTools($message, $options, $state);
        if ($lookupTools === []) {
            return false;
        }

        if (empty($state['tool_results'])) {
            return true;
        }

        if ($this->latestLookupWasNotFound($state)) {
            return false;
        }

        if (!$this->askMentionsResolvableLookupValue($plan)) {
            return false;
        }

        if ($this->hasRuntimeFeedback($state, 'ask_with_unused_lookup_tools')) {
            return false;
        }

        foreach ($lookupTools as $toolName) {
            if (!$this->hasAnyToolResult($state, $toolName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function needsLookupBeforeWrite(string $toolName, array $arguments, array $state, array $options, array $finalTools): bool
    {
        if (!$this->classifier->isWriteTool($toolName)) {
            return false;
        }

        if (in_array($toolName, $finalTools, true)) {
            return false;
        }

        $lookupTools = $this->matchingLookupToolsForWrite($toolName, $state, $options);
        if ($lookupTools === []) {
            return false;
        }

        $label = $this->labels->label($arguments);
        if ($label === '') {
            return false;
        }

        foreach ($lookupTools as $lookupTool) {
            if ($this->hasLookupResultForLabel($state, $lookupTool, $label)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<int, string>
     */
    public function matchingLookupToolsForWrite(string $toolName, array $state, array $options): array
    {
        $entity = $this->classifier->entityFor($toolName);
        if ($entity === '') {
            return [];
        }

        $selectedSkill = (string) ($options['skill_id'] ?? data_get($state, 'task_frame.active_objective', ''));
        if ($selectedSkill === '') {
            return [];
        }

        $nameCandidates = ["find_{$entity}", "lookup_{$entity}", "search_{$entity}"];
        $skillTools = [];
        foreach ($this->skills->skills() as $skill) {
            if ($skill->id !== $selectedSkill) {
                continue;
            }

            $skillTools = array_map('strval', $skill->tools);
            break;
        }

        return array_values(array_filter($skillTools, function (string $candidate) use ($entity, $nameCandidates): bool {
            $candidate = strtolower($candidate);
            if (!$this->tools->get($candidate) instanceof AgentTool) {
                return false;
            }

            if (in_array($candidate, $nameCandidates, true)) {
                return true;
            }

            return $this->classifier->isLookupTool($candidate) && $this->classifier->entityFor($candidate) === $entity;
        }));
    }

    /**
     * @param array<string, mixed> $state
     */
    public function latestLookupWasNotFound(array $state): bool
    {
        $latest = $this->latestToolResult($state);
        $toolName = strtolower((string) ($latest['tool'] ?? ''));
        if (!$this->classifier->isLookupTool($toolName)) {
            return false;
        }

        $result = is_array($latest['result'] ?? null) ? $latest['result'] : [];
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        return array_key_exists('found', $data) && $data['found'] === false;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function latestToolResult(array $state): array
    {
        $results = is_array($state['tool_results'] ?? null) ? $state['tool_results'] : [];
        $latest = $results === [] ? null : $results[array_key_last($results)];

        return is_array($latest) ? $latest : [];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     * @return array<int, string>
     */
    private function matchedSkillLookupTools(string $message, array $options, array $state = []): array
    {
        $normalized = mb_strtolower($message);
        $selectedSkill = (string) ($options['skill_id'] ?? data_get($state, 'task_frame.active_objective', ''));
        $lookupTools = [];

        foreach ($this->skills->skills() as $skill) {
            if ($selectedSkill !== '' && $skill->id !== $selectedSkill) {
                continue;
            }

            if ($selectedSkill === '' && !$this->matcher()->skillMatchesMessage($skill, $normalized)) {
                continue;
            }

            foreach ($skill->tools as $tool) {
                $tool = strtolower((string) $tool);
                if ($this->classifier->isLookupTool($tool)) {
                    $lookupTools[] = (string) $tool;
                }
            }
        }

        return array_values(array_unique($lookupTools));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasLookupResultForLabel(array $state, string $lookupTool, string $label): bool
    {
        foreach ((array) ($state['tool_results'] ?? []) as $entry) {
            if (!is_array($entry) || (string) ($entry['tool'] ?? '') !== $lookupTool) {
                continue;
            }

            $params = is_array($entry['params'] ?? null) ? $entry['params'] : [];
            $result = is_array($entry['result'] ?? null) ? $entry['result'] : [];
            $data = is_array($result['data'] ?? null) ? $result['data'] : [];

            foreach ([$this->labels->label($params), $this->labels->label($data)] as $candidate) {
                if ($candidate !== '' && $candidate === $label) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function hasAnyToolResult(array $state, string $toolName): bool
    {
        foreach ((array) ($state['tool_results'] ?? []) as $toolResult) {
            if (is_array($toolResult) && (string) ($toolResult['tool'] ?? '') === $toolName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function askMentionsResolvableLookupValue(array $plan): bool
    {
        return $this->askDetector->asksForResolvableLookupValue($plan);
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

    private function matcher(): AiNativeSkillMatcher
    {
        return $this->matcher ?? new AiNativeSkillMatcher($this->skills);
    }
}
