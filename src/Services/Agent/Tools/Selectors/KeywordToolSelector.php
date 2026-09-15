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
    use SelectsBoundedTools;

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

        $terms = $this->keywordTerms($message);
        if ($terms === []) {
            return $this->fallbackTools($tools);
        }

        $scored = [];
        foreach ($candidates as $name => $tool) {
            $score = $this->keywordScore($name, (string) $tool->getDescription(), $terms);
            if ($score > 0) {
                $scored[$name] = $score;
            }
        }

        if ($scored === []) {
            // No lexical signal at all — don't hide everything; expose the (bounded) full set.
            return $this->fallbackTools($tools);
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

}
