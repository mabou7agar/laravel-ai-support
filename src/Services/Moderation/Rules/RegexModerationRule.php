<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Moderation\Rules;

use LaravelAIEngine\Contracts\ModerationRuleInterface;

class RegexModerationRule implements ModerationRuleInterface
{
    public function __construct(
        protected string $id,
        protected array $patterns,
        protected float $violationScore = 0.5,
        protected string $flagName = 'regex_violation'
    ) {
    }

    public function check(string $content): array
    {
        $contentLower = strtolower($content);
        $foundMatches = [];

        foreach ($this->patterns as $pattern) {
            // Check if pattern is a valid regex, if not treat as simple string contains
            if (@preg_match($pattern, '') !== false) {
                if (preg_match($pattern, $content)) {
                    $foundMatches[] = $pattern;
                }
            } else {
                if (str_contains($contentLower, strtolower($pattern))) {
                    $foundMatches[] = $pattern;
                }
            }
        }

        return [
            'approved' => empty($foundMatches),
            'flags' => !empty($foundMatches) ? [$this->flagName] : [],
            'score' => !empty($foundMatches) ? $this->violationScore : 0.0,
            'matches' => $foundMatches
        ];
    }

    public function getId(): string
    {
        return $this->id;
    }
}
