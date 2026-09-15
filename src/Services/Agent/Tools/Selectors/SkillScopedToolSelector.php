<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

/**
 * When a skill is active for the turn, expose only that skill's declared tools (its
 * `use`/`final` tools and its relation lookup/create tools) plus a small always-on core
 * (e.g. search_knowledge, data_query). When no skill is active, general turns see the whole
 * registry only while it is small: above `unscoped_limit` tools (default 40) they get the core
 * plus the tools most relevant to the message (keyword ranked), and find_tools so the planner
 * can discover anything else. `unscoped_limit` null restores the legacy unbounded fallback.
 *
 * This is the lowest-risk reduction: it never hides a tool the active skill needs, and the
 * skill is re-matched every turn, so a topic change restores the full set.
 */
class SkillScopedToolSelector implements ToolSelectorContract
{
    use SelectsBoundedTools;

    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillMatcher $matcher,
        private readonly ?ToolRegistry $registry = null
    ) {
    }

    public function select(array $tools, string $message, array $state, array $options): array
    {
        $skillId = $this->activeSkillId($message, $state, $options);
        if ($skillId === null) {
            return $this->boundedUnscoped($tools, $message, $options);
        }

        $skillTools = $this->skillToolNames($skillId);
        if ($skillTools === []) {
            return $this->boundedUnscoped($tools, $message, $options);
        }

        $allowed = array_flip(array_merge($skillTools, $this->core()));

        $selected = array_filter(
            $tools,
            static fn ($tool): bool => isset($allowed[$tool->getName()])
        );

        // The scope comes from an open task, but this message is not about that task's skill
        // ("find customer CUST-0001" while an invoice draft is open). Keep the task's tools so
        // it can be resumed, and add the few tools the message is about (plus find_tools), so
        // the question can be answered instead of being pulled back into the draft.
        if ($selected !== [] && $this->scopeIsFromTaskOnly($skillId, $message, $options)) {
            $extra = (int) ($options['tool_selection']['off_skill_relevant_limit']
                ?? config('ai-agent.ai_native.tool_selection.off_skill_relevant_limit', 8));
            $relevant = $extra > 0 ? $this->relevantTools($tools, $message, $extra, array_keys($selected)) : [];
            if ($relevant !== []) {
                $selected += $relevant + $this->findToolsEntry($options);
            }
        }

        // Guard against an over-aggressive scope: if the filter somehow removed everything,
        // fall back to the full set rather than handing the planner no tools.
        return $selected !== [] ? $selected : $this->boundedUnscoped($tools, $message, $options);
    }

    /**
     * No skill scope applies. Small registries keep the full set; a large one (e.g. ~1,500
     * generated resource tools) is cut to the core plus the best keyword matches, filling any
     * remaining room in registration order, and find_tools is added so the rest stays
     * reachable. The selection keeps registry order, so the prompt stays deterministic.
     *
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function boundedUnscoped(array $tools, string $message, array $options): array
    {
        $perRequest = $options['tool_selection']['unscoped_limit'] ?? null;
        $limit = $perRequest ?? config('ai-agent.ai_native.tool_selection.unscoped_limit', 40);
        if ($limit === null || $limit === '' || count($tools) <= (int) $limit) {
            return $tools;
        }
        $limit = max(1, (int) $limit);

        $core = array_flip($this->core());
        $picked = [];
        foreach ($tools as $name => $tool) {
            if (isset($core[$name])) {
                $picked[$name] = true;
            }
        }

        $scores = [];
        $terms = $this->keywordTerms($message);
        if ($terms !== []) {
            foreach ($tools as $name => $tool) {
                if (isset($picked[$name])) {
                    continue;
                }
                $description = method_exists($tool, 'getDescription') ? (string) $tool->getDescription() : '';
                $score = $this->keywordScore((string) $name, $description, $terms);
                if ($score > 0) {
                    $scores[$name] = $score;
                }
            }
            // Stable: equal scores keep registration order.
            $keys = array_flip(array_keys($tools));
            uksort($scores, static function ($a, $b) use ($scores, $keys): int {
                return [$scores[$b], $keys[$a]] <=> [$scores[$a], $keys[$b]];
            });
            foreach (array_keys($scores) as $name) {
                if (count($picked) >= $limit) {
                    break;
                }
                $picked[$name] = true;
            }
        }

        // No relevance signal at all (a greeting): a bounded slice keeps the planner from
        // being starved; find_tools recovers anything else.
        if ($scores === []) {
            foreach ($tools as $name => $tool) {
                if (count($picked) >= $limit) {
                    break;
                }
                $picked[$name] = true;
            }
        }

        $selected = array_filter($tools, static fn ($tool, $name): bool => isset($picked[$name]), ARRAY_FILTER_USE_BOTH);

        $findToolsEnabled = $options['tool_selection']['find_tools_enabled']
            ?? config('ai-agent.ai_native.tool_selection.find_tools_enabled', true);
        $registry = $this->registry ?? (function_exists('app') && app()->bound(ToolRegistry::class) ? app(ToolRegistry::class) : null);
        // A host-closed roster (tool_selection.exposed_tools) is not widened with discovery.
        $closedRoster = array_key_exists('exposed_tools', (array) ($options['tool_selection'] ?? []));
        if ((bool) $findToolsEnabled && !$closedRoster && $registry instanceof ToolRegistry && $registry->has('find_tools')) {
            $selected['find_tools'] = $registry->get('find_tools');
        }

        return $selected;
    }

    private function activeSkillId(string $message, array $state, array $options): ?string
    {
        $matched = $this->matcher->matchedSkillId($message, $options);
        $matched = is_string($matched) && $matched !== '' ? $matched : null;

        $active = trim((string) $this->matcher->selectedSkillIdForActiveTask($state, $options));
        if ($active !== '') {
            // A message that clearly belongs to another skill scopes to that skill.
            return $matched ?? $active;
        }

        return $matched;
    }

    /**
     * True when the skill scope was inherited from the open task and the message itself
     * matches no skill (an explicitly selected skill always owns the turn).
     *
     * @param array<string, mixed> $options
     */
    private function scopeIsFromTaskOnly(string $skillId, string $message, array $options): bool
    {
        if (trim((string) ($options['skill_id'] ?? '')) !== '') {
            return false;
        }

        return $this->matcher->matchedSkillId($message, $options) === null;
    }

    /**
     * Up to $limit tools whose name/description share keywords with the message, best first.
     *
     * @param array<string, mixed> $tools
     * @param array<int, string>   $exclude
     * @return array<string, mixed>
     */
    private function relevantTools(array $tools, string $message, int $limit, array $exclude = []): array
    {
        $terms = $this->keywordTerms($message);
        if ($terms === []) {
            return [];
        }

        $excluded = array_flip($exclude);
        $scores = [];
        foreach ($tools as $name => $tool) {
            if (isset($excluded[$name])) {
                continue;
            }
            $description = method_exists($tool, 'getDescription') ? (string) $tool->getDescription() : '';
            $score = $this->keywordScore((string) $name, $description, $terms);
            if ($score > 0) {
                $scores[$name] = $score;
            }
        }

        $keys = array_flip(array_keys($tools));
        uksort($scores, static fn ($a, $b): int => [$scores[$b], $keys[$a]] <=> [$scores[$a], $keys[$b]]);

        $picked = [];
        foreach (array_slice(array_keys($scores), 0, $limit) as $name) {
            $picked[$name] = $tools[$name];
        }

        return $picked;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function findToolsEntry(array $options): array
    {
        $enabled = $options['tool_selection']['find_tools_enabled']
            ?? config('ai-agent.ai_native.tool_selection.find_tools_enabled', true);
        $registry = $this->registry ?? (function_exists('app') && app()->bound(ToolRegistry::class) ? app(ToolRegistry::class) : null);
        $closedRoster = array_key_exists('exposed_tools', (array) ($options['tool_selection'] ?? []));

        return (bool) $enabled && !$closedRoster && $registry instanceof ToolRegistry && $registry->has('find_tools')
            ? ['find_tools' => $registry->get('find_tools')]
            : [];
    }

    /**
     * @return array<int, string>
     */
    private function skillToolNames(string $skillId): array
    {
        foreach ($this->skills->skills() as $skill) {
            if ((string) ($skill->id ?? '') !== $skillId) {
                continue;
            }

            $names = array_values((array) ($skill->tools ?? []));

            $metadata = (array) ($skill->metadata ?? []);
            $final = trim((string) ($metadata['final_tool'] ?? ''));
            if ($final !== '') {
                $names[] = $final;
            }
            foreach ((array) ($metadata['relations'] ?? []) as $relation) {
                if (!is_array($relation)) {
                    continue;
                }
                foreach (['lookup_tool', 'create_tool'] as $key) {
                    $tool = trim((string) ($relation[$key] ?? ''));
                    if ($tool !== '') {
                        $names[] = $tool;
                    }
                }
            }

            return array_values(array_unique(array_filter($names)));
        }

        return [];
    }
}
