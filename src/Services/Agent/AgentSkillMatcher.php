<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentSkillDefinition;

class AgentSkillMatcher
{
    public function __construct(private readonly AgentSkillRegistry $skills)
    {
    }

    /**
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    public function match(string $message, bool $includeDisabled = false): ?array
    {
        $message = trim($message);
        $normalizedMessage = $this->normalize($message);
        if ($normalizedMessage === '') {
            return null;
        }

        $best = null;

        foreach ($this->skills->skills(includeDisabled: $includeDisabled) as $skill) {
            $candidate = $this->scoreSkill($skill, $normalizedMessage);
            if ($candidate === null) {
                continue;
            }

            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    protected function scoreSkill(AgentSkillDefinition $skill, string $normalizedMessage): ?array
    {
        $bestScore = 0;
        $bestTrigger = '';

        foreach ($this->candidateTriggers($skill) as $trigger) {
            $normalizedTrigger = $this->normalize($trigger);
            if ($normalizedTrigger === '') {
                continue;
            }

            $score = 0;
            if ($normalizedMessage === $normalizedTrigger) {
                $score = 100;
            } elseif (str_starts_with($normalizedMessage, $normalizedTrigger . ' ')) {
                $score = 90;
            } elseif (preg_match('/\b' . preg_quote($normalizedTrigger, '/') . '\b/u', $normalizedMessage) === 1) {
                $score = 75;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTrigger = $trigger;
            }
        }

        if ($bestScore === 0) {
            return null;
        }

        return [
            'skill' => $skill,
            'score' => $bestScore,
            'trigger' => $bestTrigger,
            'reason' => "Matched skill trigger [{$bestTrigger}].",
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function candidateTriggers(AgentSkillDefinition $skill): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $skill->triggers,
            [$skill->name],
            $skill->capabilities
        ))));
    }

    protected function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
