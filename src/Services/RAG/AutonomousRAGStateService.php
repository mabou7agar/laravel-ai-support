<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Cache;

class AutonomousRAGStateService
{
    public function __construct(protected ?AutonomousRAGPolicy $policy = null)
    {
        $this->policy = $policy ?? new AutonomousRAGPolicy();
    }

    public function hydrateOptionsWithLastEntityList(?string $sessionId, array $options): array
    {
        if (!$sessionId || isset($options['last_entity_list'])) {
            return $options;
        }

        $queryState = $this->getQueryState($sessionId);
        if (!$queryState) {
            return $options;
        }

        $options['last_entity_list'] = [
            'entity_type' => $queryState['model'] ?? 'item',
            'entity_data' => $queryState['entity_data'] ?? [],
            'entity_ids' => $queryState['entity_ids'] ?? [],
            'entity_refs' => $queryState['entity_refs'] ?? [],
            'objects' => $queryState['objects'] ?? [],
            'start_position' => $queryState['start_position'] ?? 1,
            'end_position' => $queryState['end_position'] ?? count($queryState['entity_ids'] ?? []),
            'current_page' => $queryState['current_page'] ?? 1,
        ];

        return $options;
    }

    public function storeQueryState(string $sessionId, array $queryState): void
    {
        Cache::put(
            "rag_query_state:{$sessionId}",
            $queryState,
            now()->addMinutes($this->policy->queryStateTtlMinutes())
        );
    }

    public function getQueryState(?string $sessionId): ?array
    {
        if (!$sessionId) {
            return null;
        }

        $state = Cache::get("rag_query_state:{$sessionId}");

        return is_array($state) ? $state : null;
    }

    public function resolveSelectedEntity(array $options, ?object $context = null): ?array
    {
        $selected = $options['selected_entity'] ?? $options['selected_entity_context'] ?? null;
        if (is_array($selected) && !empty($selected['entity_id'])) {
            return $selected;
        }

        if ($context && isset($context->metadata['selected_entity_context']) && is_array($context->metadata['selected_entity_context'])) {
            return $context->metadata['selected_entity_context'];
        }

        return null;
    }

    public function resolveIdFilterValue($rawId, array $options): ?int
    {
        if (is_int($rawId)) {
            return $rawId > 0 ? $rawId : null;
        }

        if (is_numeric($rawId)) {
            $id = (int) $rawId;

            return $id > 0 ? $id : null;
        }

        if (!is_string($rawId) || trim($rawId) === '') {
            return null;
        }

        $text = strtolower(trim($rawId));

        if (preg_match('/(?:^|[^\d])(\d{1,10})(?:$|[^\d])/', $text, $matches)) {
            $maybeId = (int) $matches[1];

            if (str_contains($text, 'entity ids') || preg_match('/\b(st|nd|rd|th)\b/', $text)) {
                $resolvedFromPosition = $this->resolveIdFromPosition((int) $matches[1], $options);
                if ($resolvedFromPosition !== null) {
                    return $resolvedFromPosition;
                }
            }

            if ($maybeId > 0) {
                return $maybeId;
            }
        }

        $wordToOrdinal = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            'sixth' => 6,
            'seventh' => 7,
            'eighth' => 8,
            'ninth' => 9,
            'tenth' => 10,
        ];

        foreach ($wordToOrdinal as $word => $ordinal) {
            if (str_contains($text, $word)) {
                $resolved = $this->resolveIdFromPosition($ordinal, $options);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    public function resolveIdFromPosition(int $position, array $options): ?int
    {
        if ($position <= 0) {
            return null;
        }

        $entityIds = [];
        $startPosition = 1;

        if (!empty($options['last_entity_list']) && is_array($options['last_entity_list'])) {
            $entityIds = $options['last_entity_list']['entity_ids'] ?? [];
            $startPosition = (int) ($options['last_entity_list']['start_position'] ?? 1);
        }

        if (empty($entityIds) && !empty($options['session_id'])) {
            $queryState = $this->getQueryState($options['session_id']);
            if ($queryState) {
                $entityIds = $queryState['entity_ids'] ?? [];
                $startPosition = (int) ($queryState['start_position'] ?? 1);
            }
        }

        if (empty($entityIds)) {
            return null;
        }

        if ($position >= $startPosition) {
            $absoluteIndex = $position - $startPosition;
            if (isset($entityIds[$absoluteIndex])) {
                return (int) $entityIds[$absoluteIndex];
            }
        }

        $relativeIndex = $position - 1;
        if (isset($entityIds[$relativeIndex])) {
            return (int) $entityIds[$relativeIndex];
        }

        return null;
    }
}
