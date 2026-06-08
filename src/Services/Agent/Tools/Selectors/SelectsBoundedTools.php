<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\Tools\AgentTool;

/**
 * Shared helpers for the top-K selectors (keyword/semantic): the always-on core list and a
 * BOUNDED fail-open.
 *
 * A selector falls open (returns the full set) on a no-signal turn — an empty/greeting
 * message, no lexical match, or an embedding error — so the planner is never starved. With a
 * small registry that's harmless, but with a large one (e.g. 1000 AiResource tools) dumping
 * every schema into the prompt defeats the whole point of selecting and can blow the context
 * window. fallbackTools() caps that: the full set when the registry is small (unchanged
 * behavior), otherwise the core plus the first `fallback_limit` tools so the prompt stays
 * bounded even on a no-signal turn. Set `fallback_limit` to null to restore the legacy
 * (unbounded) fail-open.
 */
trait SelectsBoundedTools
{
    /**
     * @return array<int, string>
     */
    protected function core(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $name): string => trim((string) $name),
            (array) config('ai-agent.ai_native.tool_selection.always', ['search_knowledge', 'data_query'])
        )));
    }

    /**
     * @param array<string, AgentTool> $tools
     * @return array<string, AgentTool>
     */
    protected function fallbackTools(array $tools): array
    {
        $cap = config('ai-agent.ai_native.tool_selection.fallback_limit', 50);
        if ($cap === null || count($tools) <= (int) $cap) {
            return $tools;
        }
        $cap = max(1, (int) $cap);

        $coreNames = array_flip($this->core());
        $selected = [];

        // Core tools are always exposed, even on a no-signal turn.
        foreach ($tools as $name => $tool) {
            if (isset($coreNames[$name])) {
                $selected[$name] = $tool;
            }
        }

        // Fill the rest up to the cap in registration order — arbitrary but bounded; a
        // no-signal turn rarely needs a specific domain tool, and progressive disclosure /
        // find_tools (or a follow-up turn with signal) recovers the rest.
        foreach ($tools as $name => $tool) {
            if (count($selected) >= $cap) {
                break;
            }
            $selected[$name] = $tool;
        }

        return $selected;
    }
}
