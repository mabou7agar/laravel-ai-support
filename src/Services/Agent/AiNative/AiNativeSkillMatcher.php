<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;

class AiNativeSkillMatcher
{
    public function __construct(private readonly AgentSkillRegistry $skills)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function matchedSkillId(string $message, array $options): ?string
    {
        $selectedSkill = trim((string) ($options['skill_id'] ?? ''));
        if ($selectedSkill !== '') {
            return $selectedSkill;
        }

        $normalized = mb_strtolower($message);
        foreach ($this->skills->skills() as $skill) {
            if ($this->skillMatchesMessage($skill, $normalized)) {
                return $skill->id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function messageMatchesSkill(string $message, array $options): bool
    {
        // Explicit skill selection short-circuits; a bare runtime_scope=skill
        // must not — it made trigger matching vacuous (every message
        // "matched"), which cascaded into forced final tools on unrelated asks.
        if (($options['skill_id'] ?? '') !== '') {
            return true;
        }

        $normalized = mb_strtolower($message);
        if (trim($normalized) === '') {
            return false;
        }

        foreach ($this->skills->skills() as $skill) {
            if ($this->skillMatchesMessage($skill, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $options
     */
    public function selectedSkillIdForActiveTask(array $state, array $options): string
    {
        $selectedSkill = trim((string) ($options['skill_id'] ?? ''));
        if ($selectedSkill !== '') {
            return $selectedSkill;
        }

        if ((string) data_get($state, 'task_frame.status', '') === 'completed') {
            return '';
        }

        return trim((string) data_get($state, 'task_frame.active_objective', ''));
    }

    public function skillMatchesMessage(mixed $skill, string $normalized): bool
    {
        $normalizedWithoutStopwords = $this->withoutCommonStopwords($normalized);

        foreach ($skill->triggers as $trigger) {
            $trigger = mb_strtolower(trim((string) $trigger));
            if ($trigger === '') {
                continue;
            }

            if (str_contains($normalized, $trigger) || str_contains($normalizedWithoutStopwords, $this->withoutCommonStopwords($trigger))) {
                return true;
            }
        }

        return false;
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
