<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeSkillMatcher;

/**
 * When a skill is active for the turn, expose only that skill's declared tools (its
 * `use`/`final` tools and its relation lookup/create tools) plus a small always-on core
 * (e.g. search_knowledge, data_query). When no skill is active, fall back to all tools so
 * general turns are unaffected.
 *
 * This is the lowest-risk reduction: it never hides a tool the active skill needs, and the
 * skill is re-matched every turn, so a topic change restores the full set.
 */
class SkillScopedToolSelector implements ToolSelectorContract
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly AiNativeSkillMatcher $matcher
    ) {
    }

    public function select(array $tools, string $message, array $state, array $options): array
    {
        $skillId = $this->activeSkillId($message, $state, $options);
        if ($skillId === null) {
            return $tools;
        }

        $skillTools = $this->skillToolNames($skillId);
        if ($skillTools === []) {
            return $tools;
        }

        $allowed = array_flip(array_merge($skillTools, $this->core()));

        $selected = array_filter(
            $tools,
            static fn ($tool): bool => isset($allowed[$tool->getName()])
        );

        // Guard against an over-aggressive scope: if the filter somehow removed everything,
        // fall back to the full set rather than handing the planner no tools.
        return $selected !== [] ? $selected : $tools;
    }

    private function activeSkillId(string $message, array $state, array $options): ?string
    {
        $active = trim((string) $this->matcher->selectedSkillIdForActiveTask($state, $options));
        if ($active !== '') {
            return $active;
        }

        $matched = $this->matcher->matchedSkillId($message, $options);

        return is_string($matched) && $matched !== '' ? $matched : null;
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
