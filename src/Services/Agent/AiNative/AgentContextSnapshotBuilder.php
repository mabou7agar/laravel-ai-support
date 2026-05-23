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
