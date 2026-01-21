<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

interface ModerationRuleInterface
{
    /**
     * Check if the content violates the rule.
     *
     * @param string $content
     * @return array Result array ['approved' => bool, 'flags' => array, 'score' => float]
     */
    public function check(string $content): array;

    /**
     * Get the rule identifier.
     */
    public function getId(): string;
}
