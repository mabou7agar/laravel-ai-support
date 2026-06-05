<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

/**
 * Lexical top-K selector: score each tool by keyword overlap between the turn message and
 * the tool's name + description, and expose the best `limit` (plus the always-on core).
 * No embeddings — cheap and deterministic. When nothing matches (e.g. a greeting), fall
 * back to all tools so the planner is never starved on a no-signal turn.
 */
class KeywordToolSelector implements ToolSelectorContract
{
    /** @var array<int, string> */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'with', 'this', 'that', 'you', 'your', 'are', 'was', 'will',
        'can', 'please', 'want', 'need', 'about', 'from', 'into', 'have', 'has', 'create',
        'make', 'show', 'get', 'list', 'find', 'add', 'new',
    ];

    public function select(array $tools, string $message, array $state, array $options): array
    {
        $limit = max(1, (int) config('ai-agent.ai_native.tool_selection.limit', 12));
        $coreNames = array_flip($this->core());

        $core = [];
        $candidates = [];
        foreach ($tools as $name => $tool) {
            if (isset($coreNames[$name])) {
                $core[$name] = $tool;
            } else {
                $candidates[$name] = $tool;
            }
        }

        $terms = $this->terms($message);
        if ($terms === []) {
            return $tools;
        }

        $scored = [];
        foreach ($candidates as $name => $tool) {
            $score = $this->score($name, (string) $tool->getDescription(), $terms);
            if ($score > 0) {
                $scored[$name] = $score;
            }
        }

        if ($scored === []) {
            // No lexical signal at all — don't hide everything; expose the full set.
            return $tools;
        }

        arsort($scored);
        $room = max(0, $limit - count($core));
        $picked = array_slice($scored, 0, $room, true);

        $selected = $core;
        foreach (array_keys($picked) as $name) {
            $selected[$name] = $candidates[$name];
        }

        return $selected;
    }

    /**
     * @param array<int, string> $terms
     */
    private function score(string $name, string $description, array $terms): int
    {
        $nameHay = ' ' . str_replace('_', ' ', mb_strtolower($name)) . ' ';
        $descHay = mb_strtolower($description);

        $score = 0;
        foreach ($terms as $term) {
            // A hit in the tool name is the strongest signal.
            if (str_contains($nameHay, ' ' . $term . ' ')) {
                $score += 3;
            } elseif (str_contains($nameHay, $term)) {
                $score += 2;
            } elseif (str_contains($descHay, $term)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $message): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower($message)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $t): bool => strlen($t) >= 3 && !in_array($t, self::STOP_WORDS, true)
        )));
    }

    /**
     * @return array<int, string>
     */
    private function core(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            (array) config('ai-agent.ai_native.tool_selection.always', ['search_knowledge', 'data_query'])
        )));
    }
}
