<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentContextSnapshotBuilder
{
    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function build(UnifiedActionContext $context, array $state): array
    {
        $frame = is_array($state['task_frame'] ?? null) ? $state['task_frame'] : [];
        $outcomes = array_values(array_filter(
            (array) ($state['recent_outcomes'] ?? []),
            static fn (mixed $outcome): bool => is_array($outcome)
        ));

        // Prompt-size guard (render-time only; the persisted state is untouched):
        // a long session's recent_outcomes accumulate byte-identical entries (the
        // model retrying the same successful call) and multi-KB 'display' blobs
        // (the normalizer keeps the whole entity payload). Collapse duplicates to
        // one entry + {"repeats":N} and cap each display string, so the snapshot
        // block stops re-billing the same bytes on every planner step.
        // Kill switch: ai-agent.ai_native.snapshot_compact_outcomes (default true).
        if ($this->compactionEnabled()) {
            $outcomes = $this->compactOutcomes($outcomes);
        }

        return array_filter([
            'session_id' => $context->sessionId,
            'active_task' => $frame !== [] ? [
                'objective' => $frame['active_objective'] ?? null,
                'status' => $frame['status'] ?? null,
            ] : null,
            'pending_confirmation' => $this->pendingConfirmation($frame),
            'current_payload' => is_array($frame['current_payload'] ?? null) ? $frame['current_payload'] : null,
            'resolved_entities' => $this->resolvedEntities($outcomes),
            'recent_outcomes' => $outcomes,
            'already_completed' => array_values((array) ($frame['completed_writes'] ?? [])),
            'open_questions' => array_values((array) ($frame['open_questions'] ?? [])),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function compactionEnabled(): bool
    {
        return !\function_exists('config')
            || (bool) config('ai-agent.ai_native.snapshot_compact_outcomes', true);
    }

    /**
     * Collapse byte-identical outcome entries to a single entry carrying a
     * {"repeats": N} counter (first occurrence keeps its position), after
     * capping each entry's 'display' strings to a render budget. Dedup runs on
     * the CAPPED entries so two outcomes that differ only inside a truncated
     * display blob still collapse.
     *
     * @param array<int, array<string, mixed>> $outcomes
     * @return array<int, array<string, mixed>>
     */
    private function compactOutcomes(array $outcomes): array
    {
        $compact = [];
        $index = [];
        foreach ($outcomes as $outcome) {
            $outcome = $this->withCappedDisplay($outcome);
            $fingerprint = json_encode($outcome, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($fingerprint === false) {
                $compact[] = $outcome;
                continue;
            }
            if (isset($index[$fingerprint])) {
                $at = $index[$fingerprint];
                $compact[$at]['repeats'] = (int) ($compact[$at]['repeats'] ?? 1) + 1;
                continue;
            }
            $index[$fingerprint] = count($compact);
            $compact[] = $outcome;
        }

        return array_values($compact);
    }

    /**
     * Cap the entry's 'display' payload for prompt rendering: every string is
     * truncated to ~200 chars (the normalizer stores whole entity payloads —
     * live entries ran 1-3KB each). Non-display fields are left untouched.
     *
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private function withCappedDisplay(array $outcome): array
    {
        if (isset($outcome['display'])) {
            $outcome['display'] = $this->truncateStrings($outcome['display']);
        }

        return $outcome;
    }

    private function truncateStrings(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }
        if (!is_array($value) || $depth >= 4) {
            return is_array($value) ? '[pruned: too deep]' : $value;
        }

        return array_map(fn (mixed $v): mixed => $this->truncateStrings($v, $depth + 1), $value);
    }

    /**
     * @param array<string, mixed> $frame
     * @return array<string, mixed>|null
     */
    private function pendingConfirmation(array $frame): ?array
    {
        $pending = $frame['pending_tool'] ?? null;
        if (!is_array($pending)) {
            return null;
        }

        return [
            'tool' => $pending['name'] ?? null,
            'summary' => $pending['summary'] ?? [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $outcomes
     * @return array<int, array<string, mixed>>
     */
    private function resolvedEntities(array $outcomes): array
    {
        $entities = [];
        foreach ($outcomes as $outcome) {
            if (!in_array($outcome['outcome'] ?? null, ['found', 'created', 'updated'], true)) {
                continue;
            }

            if (($outcome['entity_type'] ?? null) === null || ($outcome['entity_id'] ?? null) === null) {
                continue;
            }

            $entities[] = [
                'type' => $outcome['entity_type'],
                'label' => $outcome['label'] ?? $outcome['entity_type'],
                'internal_id' => $outcome['entity_id'],
                'visible_to_user' => false,
            ];
        }

        return array_values($entities);
    }
}
