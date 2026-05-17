<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

use LaravelAIEngine\DTOs\ConversationMemoryResult;

class ConversationMemoryPromptBuilder
{
    public function __construct(
        protected ConversationMemoryPolicy $policy,
    ) {
    }

    /**
     * @param array<int, ConversationMemoryResult> $results
     */
    public function build(array $results): string
    {
        $budget = $this->policy->maxPromptChars();
        $minScore = $this->policy->minScore();

        $filtered = array_values(array_filter(
            $results,
            static fn (ConversationMemoryResult $result): bool => $result->score >= $minScore
                && trim($result->item->summary) !== ''
        ));

        usort($filtered, static function (ConversationMemoryResult $a, ConversationMemoryResult $b): int {
            return $b->score <=> $a->score;
        });

        $header = 'Relevant remembered context:';
        $lines = [];
        $seen = [];

        foreach ($filtered as $result) {
            $summary = $this->singleLine($result->item->summary);
            $dedupeKey = mb_strtolower($result->item->namespace . ':' . $result->item->key . ':' . $summary);

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $line = sprintf('- [%s] %s', $result->item->namespace, $summary);
            $candidate = $header . "\n" . implode("\n", [...$lines, $line]);

            if (mb_strlen($candidate) > $budget) {
                if ($lines === []) {
                    $remaining = max(0, $budget - mb_strlen($header) - 4);
                    if ($remaining > 20) {
                        $lines[] = mb_substr($line, 0, $remaining - 1) . '...';
                    }
                }

                break;
            }

            $seen[$dedupeKey] = true;
            $lines[] = $line;
        }

        if ($lines === []) {
            return '';
        }

        return $header . "\n" . implode("\n", $lines);
    }

    protected function singleLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
