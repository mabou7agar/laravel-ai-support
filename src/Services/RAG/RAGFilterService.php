<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Applies AI-generated filters to Eloquent queries and resolves
 * positional/ordinal ID references from the visible entity list.
 *
 * Extracted from AutonomousRAGAgent to keep query-building logic
 * isolated and testable.
 */
class RAGFilterService
{
    /**
     * Apply AI-generated filters to a query builder.
     *
     * Supported filter keys:
     *  - id            → fetch single record (skips all other filters)
     *  - date_field, date_value, date_operator, date_end
     *  - status
     *  - amount_field, amount_min, amount_max
     */
    public function apply(Builder $query, array $filters, string $modelClass, array $options = []): Builder
    {
        if (empty($filters)) {
            return $query;
        }

        $table = (new $modelClass)->getTable();

        // ── ID filter (short-circuit) ──────────────────────────
        if (!empty($filters['id'])) {
            $resolvedId = $this->resolveIdFilterValue($filters['id'], $options);
            if ($resolvedId !== null) {
                $query->where('id', $resolvedId);

                Log::channel('ai-engine')->debug('RAGFilterService: applied ID filter', [
                    'id' => $resolvedId,
                    'raw_id' => $filters['id'],
                ]);
            } else {
                Log::channel('ai-engine')->warning('RAGFilterService: could not resolve ID filter, skipping', [
                    'raw_id' => $filters['id'],
                ]);
            }

            return $query; // ID filter is exclusive
        }

        // ── Date filter ────────────────────────────────────────
        if (!empty($filters['date_field']) && !empty($filters['date_value'])) {
            $dateField = $filters['date_field'];
            $dateValue = $filters['date_value'];
            $operator = $filters['date_operator'] ?? '=';

            if (Schema::hasColumn($table, $dateField)) {
                if ($operator === 'between' && !empty($filters['date_end'])) {
                    $query->whereBetween($dateField, [$dateValue, $filters['date_end']]);
                } else {
                    $query->whereDate($dateField, $operator, $dateValue);
                }
            }
        }

        // ── Status filter ──────────────────────────────────────
        if (!empty($filters['status'])) {
            $statusField = $filters['status_field'] ?? 'status';
            if (Schema::hasColumn($table, $statusField)) {
                $query->where($statusField, $filters['status']);
            }
        }

        // ── Amount range filters ───────────────────────────────
        $amountField = $filters['amount_field'] ?? null;
        if ($amountField && Schema::hasColumn($table, $amountField)) {
            if (!empty($filters['amount_min'])) {
                $query->where($amountField, '>=', $filters['amount_min']);
            }
            if (!empty($filters['amount_max'])) {
                $query->where($amountField, '<=', $filters['amount_max']);
            }
        }

        return $query;
    }

    /**
     * Apply the standard user-scoping filter for a model.
     *
     * Uses scopeForUser() if available, otherwise falls back to
     * the user_field from the filter config.
     */
    public function applyUserScope(Builder $query, string $modelClass, $userId, array $filterConfig = []): Builder
    {
        if (method_exists($modelClass, 'scopeForUser')) {
            $query->forUser($userId);
            return $query;
        }

        if (!empty($filterConfig['user_field'])) {
            $table = (new $modelClass)->getTable();
            $userField = $filterConfig['user_field'];
            if (Schema::hasColumn($table, $userField)) {
                $query->where($userField, $userId);
            }
        }

        return $query;
    }

    // ──────────────────────────────────────────────
    //  ID Resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve AI-generated ID filter values.
     *
     * Supports:
     *  - Raw numeric IDs (42, "42")
     *  - Ordinal words ("first", "second")
     *  - Ordinal suffixes ("2nd")
     *  - Positional placeholders ("[use 2nd ID from ENTITY IDS in context]")
     */
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

        // Extract explicit numeric ID (e.g. "id 25", "#25")
        if (preg_match('/(?:^|[^\d])(\d{1,10})(?:$|[^\d])/', $text, $m)) {
            $maybeId = (int) $m[1];

            // If it looks like a positional reference, resolve from entity list
            if (str_contains($text, 'entity ids') || preg_match('/\b(st|nd|rd|th)\b/', $text)) {
                $resolved = $this->resolveIdFromPosition($maybeId, $options);
                if ($resolved !== null) {
                    return $resolved;
                }
            }

            if ($maybeId > 0) {
                return $maybeId;
            }
        }

        // Ordinal words → position
        $ordinals = [
            'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4,
            'fifth' => 5, 'sixth' => 6, 'seventh' => 7, 'eighth' => 8,
            'ninth' => 9, 'tenth' => 10,
        ];

        foreach ($ordinals as $word => $position) {
            if (str_contains($text, $word)) {
                $resolved = $this->resolveIdFromPosition($position, $options);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    /**
     * Map a 1-based display position to an actual entity ID
     * from the currently visible entity list.
     */
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
            $queryState = Cache::get("rag_query_state:{$options['session_id']}");
            if (is_array($queryState)) {
                $entityIds = $queryState['entity_ids'] ?? [];
                $startPosition = (int) ($queryState['start_position'] ?? 1);
            }
        }

        if (empty($entityIds)) {
            return null;
        }

        // Absolute position (e.g. position 12 when start_position is 11)
        if ($position >= $startPosition) {
            $index = $position - $startPosition;
            if (isset($entityIds[$index])) {
                return (int) $entityIds[$index];
            }
        }

        // Relative position within current page (1-based)
        $relativeIndex = $position - 1;
        if (isset($entityIds[$relativeIndex])) {
            return (int) $entityIds[$relativeIndex];
        }

        return null;
    }
}
