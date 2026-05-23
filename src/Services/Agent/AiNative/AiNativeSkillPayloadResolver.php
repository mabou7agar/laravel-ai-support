<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;

class AiNativeSkillPayloadResolver
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillMatcher $matcher
    ) {}

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function fromPlan(array $plan, array $state, array $options): array
    {
        $data = is_array($plan['data'] ?? null) ? $plan['data'] : [];
        foreach (['current_payload', 'draft_payload', 'payload'] as $key) {
            if (is_array($data[$key] ?? null)) {
                return $data[$key];
            }
        }

        if (is_array($data['draft']['payload'] ?? null)) {
            return $data['draft']['payload'];
        }

        if ($this->looksLikeSkillPayload($data, $state, $options)) {
            return $data;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    private function looksLikeSkillPayload(array $payload, array $state, array $options): bool
    {
        if ($payload === []) {
            return false;
        }

        $keys = $this->selectedSkillPayloadKeys($state, $options);
        if ($keys === []) {
            return false;
        }

        foreach (array_keys($payload) as $key) {
            if (isset($keys[(string) $key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     * @return array<string, bool>
     */
    private function selectedSkillPayloadKeys(array $state, array $options): array
    {
        $selectedSkill = $this->matcher->selectedSkillIdForActiveTask($state, $options);
        if ($selectedSkill === '') {
            return [];
        }

        foreach ($this->skills->skills() as $skill) {
            if ($skill->id !== $selectedSkill) {
                continue;
            }

            $targetJson = is_array($skill->metadata['target_json'] ?? null) ? $skill->metadata['target_json'] : [];

            return array_fill_keys(array_map('strval', array_keys($targetJson)), true);
        }

        return [];
    }
}
