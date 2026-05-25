<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Vector\Drivers;

use Illuminate\Support\Facades\Log;

class QdrantFilterBuilder
{
    /**
     * @param array<string, mixed> $filters
     * @param array<string, string> $indexTypes
     */
    public function build(
        array $filters,
        ?string $collection = null,
        array $indexTypes = [],
        ?callable $ensureFilterIndexes = null
    ): array {
        $must = [];

        foreach ($filters as $key => $value) {
            if ($key === 'model_class') {
                continue;
            }

            $processedFilter = $this->processDateFilter($key, $value, $collection, $ensureFilterIndexes);
            if ($processedFilter !== null) {
                $must[] = $processedFilter;
                continue;
            }

            if (is_array($value) && $this->isRangeFilter($value)) {
                $rangeFilter = $this->buildRangeFilter($key, $value, $indexTypes[$key] ?? null);
                if ($rangeFilter) {
                    $must[] = $rangeFilter;
                }
                continue;
            }

            $indexType = $indexTypes[$key] ?? null;

            if (is_array($value)) {
                $must[] = [
                    'key' => $key,
                    'match' => [
                        'any' => array_map(
                            fn ($item) => $this->convertFilterValue($key, $item, $indexType),
                            $value
                        ),
                    ],
                ];
                continue;
            }

            $must[] = [
                'key' => $key,
                'match' => ['value' => $this->convertFilterValue($key, $value, $indexType)],
            ];
        }

        return ['must' => $must];
    }

    private function isRangeFilter(array $value): bool
    {
        foreach (['gte', 'lte', 'gt', 'lt'] as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function buildRangeFilter(string $key, array $range, ?string $indexType = null): ?array
    {
        $rangeCondition = [];

        foreach (['gte', 'lte', 'gt', 'lt'] as $operator) {
            if (isset($range[$operator])) {
                $rangeCondition[$operator] = $this->convertRangeValue($range[$operator], $indexType);
            }
        }

        if ($rangeCondition === []) {
            return null;
        }

        return [
            'key' => $key,
            'range' => $rangeCondition,
        ];
    }

    private function convertRangeValue($value, ?string $indexType = null)
    {
        if ($indexType === 'integer' || $indexType === 'int') {
            return is_numeric($value) ? (int) $value : $value;
        }

        if ($indexType === 'float') {
            return is_numeric($value) ? (float) $value : $value;
        }

        if (is_numeric($value)) {
            return str_contains((string) $value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    private function processDateFilter(
        string $key,
        $value,
        ?string $collection = null,
        ?callable $ensureFilterIndexes = null
    ): ?array {
        $dateFields = ['created_at', 'updated_at', 'issue_date', 'due_date', 'paid_date', 'sent_date', 'date', 'published_at', 'deleted_at'];

        if (!in_array($key, $dateFields, true)) {
            return null;
        }

        if (is_array($value) && $this->isRangeFilter($value)) {
            $tsKey = $key . '_ts';
            $tsRange = [];

            foreach (['gte', 'lte', 'gt', 'lt'] as $operator) {
                if (isset($value[$operator])) {
                    $timestamp = $this->parseToTimestamp($value[$operator]);
                    if ($timestamp !== null) {
                        if ($operator === 'lte' && $this->isDateOnly($value[$operator])) {
                            $timestamp = strtotime($value[$operator] . ' 23:59:59');
                        }

                        $tsRange[$operator] = $timestamp;
                    }
                }
            }

            if ($tsRange !== []) {
                $this->ensureTimestampIndex($collection, $tsKey, $ensureFilterIndexes);

                return [
                    'key' => $tsKey,
                    'range' => $tsRange,
                ];
            }
        }

        if (is_string($value) && $this->isDateOnly($value)) {
            $tsKey = $key . '_ts';
            $startTs = strtotime($value . ' 00:00:00');
            $endTs = strtotime($value . ' 23:59:59');

            if ($startTs && $endTs && $collection) {
                $this->ensureTimestampIndex($collection, $tsKey, $ensureFilterIndexes);

                return [
                    'key' => $tsKey,
                    'range' => [
                        'gte' => $startTs,
                        'lte' => $endTs,
                    ],
                ];
            }
        }

        return null;
    }

    private function ensureTimestampIndex(?string $collection, string $field, ?callable $ensureFilterIndexes): void
    {
        if ($collection !== null && $ensureFilterIndexes !== null) {
            $ensureFilterIndexes($collection, [$field]);
        }
    }

    private function parseToTimestamp($value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);

            return $timestamp !== false ? $timestamp : null;
        }

        return null;
    }

    private function isDateOnly(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) === 1
            || (strtotime($value) !== false && preg_match('/\d{2}:\d{2}/', $value) !== 1);
    }

    private function convertFilterValue(string $key, $value, ?string $indexType = null)
    {
        if ($indexType !== null) {
            switch ($this->normalizeIndexType($indexType)) {
                case 'integer':
                    if (is_numeric($value)) {
                        return (int) $value;
                    }

                    Log::debug('Filter value is not numeric for integer index', [
                        'field' => $key,
                        'value' => $value,
                        'index_type' => $indexType,
                    ]);

                    return $value;

                case 'float':
                    return is_numeric($value) ? (float) $value : $value;

                case 'bool':
                    if (is_bool($value)) {
                        return $value;
                    }

                    if (is_string($value)) {
                        $lower = strtolower($value);
                        if (in_array($lower, ['true', '1', 'yes', 'on'], true)) {
                            return true;
                        }
                        if (in_array($lower, ['false', '0', 'no', 'off'], true)) {
                            return false;
                        }
                    }

                    return (bool) $value;

                case 'keyword':
                    return (string) $value;
            }
        }

        if ((str_ends_with($key, '_id') || $key === 'id') && is_numeric($value)) {
            return (int) $value;
        }

        if (str_starts_with($key, 'is_') || str_starts_with($key, 'has_')) {
            return (bool) $value;
        }

        return $value;
    }

    private function normalizeIndexType(string $type): string
    {
        return match (strtolower($type)) {
            'integer', 'int', 'uint64' => 'integer',
            'float', 'double' => 'float',
            'bool', 'boolean' => 'bool',
            'datetime', 'date' => 'datetime',
            default => 'keyword',
        };
    }
}
