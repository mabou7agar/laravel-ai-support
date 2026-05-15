<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RAGAggregateService
{
    public function __construct(
        protected RAGDecisionPolicy $policy,
        protected ?RAGModelScopeGuard $scopeGuard = null
    )
    {
        $this->scopeGuard = $this->scopeGuard ?? new RAGModelScopeGuard();
    }

    public function aggregate(array $params, $userId, array $options, array $dependencies): array
    {
        $modelName = $params['model'] ?? null;
        if (!$modelName) {
            return ['success' => false, 'error' => 'No model specified'];
        }

        $modelClass = $dependencies['findModelClass']($modelName, $options);
        if (!$modelClass) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not found locally",
                'should_route_to_node' => true,
            ];
        }

        if (!class_exists($modelClass)) {
            return [
                'success' => false,
                'error' => "Model {$modelName} not available locally",
                'should_route_to_node' => true,
            ];
        }

        $aggregate = $params['aggregate'] ?? [];
        $operation = $this->policy->normalizeAggregateOperation($aggregate['operation'] ?? 'sum');
        $field = $aggregate['field'] ?? null;
        $groupBy = $aggregate['group_by'] ?? null;
        $filterConfig = $dependencies['getFilterConfigForModel']($modelClass);

        if (!$field && !empty($filterConfig['amount_field'])) {
            $field = $filterConfig['amount_field'];
        }

        if (!$field) {
            $field = $this->defaultAggregateField($modelClass, $operation);
        }

        if (!$field && $operation !== 'count') {
            return ['success' => false, 'error' => 'No field specified for aggregation'];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass();
            $table = $instance->getTable();

            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            $scope = $this->scopeGuard->apply($query, $modelClass, $userId, $filterConfig, $options);
            if (($scope['allowed'] ?? false) !== true) {
                return [
                    'success' => false,
                    'error' => $scope['error'] ?? 'Structured RAG access is blocked for this model.',
                    'tool' => 'db_aggregate',
                    'scope_blocked' => true,
                ];
            }
            $query = $scope['query'];

            $query = $dependencies['applyFilters']($query, $params['filters'] ?? [], $modelClass, $options);

            if ($groupBy) {
                return $this->aggregateByMonth(
                    $query,
                    $table,
                    $modelName,
                    $field ?: 'id',
                    $operation,
                    $aggregate,
                    $filterConfig
                ) ?: $this->aggregateByField(
                    $query,
                    $table,
                    $modelName,
                    $field ?: 'id',
                    $operation,
                    (string) $groupBy,
                    $aggregate
                );
            }

            $isDbField = $field && Schema::hasColumn($table, $field);
            $methodName = 'get' . ucfirst($field);
            $hasMethod = method_exists($instance, $methodName);

            $result = null;
            $count = 0;
            $calculationMethod = null;

            if ($operation === 'summary') {
                if (!$isDbField) {
                    return [
                        'success' => false,
                        'error' => "Field '{$field}' must be a database column for summary aggregation",
                    ];
                }

                $count = (clone $query)->count();
                $result = [
                    'count' => $count,
                    'sum' => (float) (clone $query)->sum($field),
                    'avg' => $count > 0 ? (float) (clone $query)->avg($field) : 0.0,
                    'min' => $count > 0 ? (float) (clone $query)->min($field) : 0.0,
                    'max' => $count > 0 ? (float) (clone $query)->max($field) : 0.0,
                ];
                $calculationMethod = 'database';
            } elseif ($operation === 'count') {
                $result = (clone $query)->count();
                $count = (int) $result;
                $calculationMethod = 'database';
            } elseif ($isDbField) {
                $result = $query->$operation($field);
                $count = (clone $query)->count();
                $calculationMethod = 'database';
            } elseif ($hasMethod) {
                $records = $query->get();
                $count = $records->count();

                if ($count === 0) {
                    $result = 0;
                } else {
                    $values = $records->map(fn ($record) => $record->$methodName())
                        ->filter(fn ($value) => is_numeric($value));

                    $result = match ($operation) {
                        'sum' => $values->sum(),
                        'avg' => $values->avg(),
                        'min' => $values->min(),
                        'max' => $values->max(),
                        'count' => $values->count(),
                        default => $values->sum(),
                    };
                }

                $calculationMethod = 'model_method';
            } else {
                return [
                    'success' => false,
                    'error' => "Field '{$field}' not found in database and no method '{$methodName}' exists on {$modelName}",
                ];
            }

            $label = [
                'sum' => 'Total',
                'avg' => 'Average',
                'min' => 'Minimum',
                'max' => 'Maximum',
                'count' => 'Count',
            ][$operation] ?? 'Result';

            if ($operation === 'summary') {
                return [
                    'success' => true,
                    'response' => $this->formatSummaryResponse($modelName, $field, $result),
                    'tool' => 'db_aggregate',
                    'fast_path' => true,
                    'result' => $result,
                    'count' => $count,
                    'operation' => $operation,
                    'field' => $field,
                    'calculation_method' => $calculationMethod,
                ];
            }

            $formattedResult = is_numeric($result) ? number_format((float) $result, 2) : $result;
            $prefix = $operation === 'count' ? '' : '$';
            $subject = $operation === 'count' ? $modelName : "{$field}";

            return [
                'success' => true,
                'response' => "**{$label} {$subject}**: {$prefix}{$formattedResult} (from {$count} {$modelName}s)",
                'tool' => 'db_aggregate',
                'fast_path' => $calculationMethod === 'database',
                'result' => $result,
                'count' => $count,
                'operation' => $operation,
                'field' => $field,
                'calculation_method' => $calculationMethod,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('db_aggregate failed', ['error' => $e->getMessage()]);

            if ($this->isMissingTableException($e)) {
                return [
                    'success' => false,
                    'error' => "Model {$modelName} table is not available locally",
                    'should_route_to_node' => true,
                ];
            }

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function aggregateByMonth(
        $query,
        string $table,
        string $modelName,
        string $field,
        string $operation,
        array $aggregate,
        array $filterConfig
    ): ?array {
        $dateField = $aggregate['date_field'] ?? $filterConfig['date_field'] ?? 'created_at';
        if ($groupBy = $aggregate['group_by'] ?? null) {
            if ($groupBy !== 'month') {
                return null;
            }
        }

        if ($operation !== 'count' && !Schema::hasColumn($table, $field)) {
            return [
                'success' => false,
                'error' => "Field '{$field}' must be a database column for grouped aggregation",
            ];
        }

        if (!Schema::hasColumn($table, $dateField)) {
            return [
                'success' => false,
                'error' => "Date field '{$dateField}' not found for grouped aggregation",
            ];
        }

        $groupLimit = min((int) ($aggregate['limit'] ?? $this->policy->itemsPerPage()), $this->policy->itemsPerPage());
        $bucketExpression = $this->monthBucketExpression($dateField, (string) $query->getConnection()->getDriverName());
        if ($operation === 'summary') {
            $rows = (clone $query)
                ->selectRaw("{$bucketExpression} as bucket")
                ->selectRaw('COUNT(*) as count_value')
                ->selectRaw("SUM({$field}) as sum_value")
                ->selectRaw("AVG({$field}) as avg_value")
                ->selectRaw("MIN({$field}) as min_value")
                ->selectRaw("MAX({$field}) as max_value")
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->limit($groupLimit + 1)
                ->get();

            return $this->formatGroupedSummaryResult($rows, $groupLimit, $modelName, $field, 'month');
        }

        $aggregateExpression = $operation === 'count'
            ? 'COUNT(*)'
            : strtoupper($operation) . "({$field})";

        $rows = (clone $query)
            ->selectRaw("{$bucketExpression} as bucket")
            ->selectRaw("{$aggregateExpression} as aggregate_value")
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->limit($groupLimit + 1)
            ->get();

        $hasMoreGroups = $rows->count() > $groupLimit;
        $groups = $rows
            ->take($groupLimit)
            ->map(fn ($row) => [
                'bucket' => $row->bucket,
                'value' => (float) $row->aggregate_value,
            ])
            ->values()
            ->all();

        $prefix = $operation === 'count' ? '' : '$';
        $label = ucfirst($operation) . " {$field} by month";
        $lines = collect($groups)
            ->map(fn (array $group) => '- ' . $group['bucket'] . ': ' . $prefix . number_format($group['value'], 2))
            ->implode("\n");

        $response = "**{$label}**:\n{$lines}";
        if ($hasMoreGroups) {
            $response .= "\n\n*Showing first {$groupLimit} groups due to policy limits.*";
        }

        return [
            'success' => true,
            'response' => $response,
            'tool' => 'db_aggregate',
            'fast_path' => true,
            'result' => $groups,
            'count' => count($groups),
            'operation' => $operation,
            'field' => $field,
            'group_by' => 'month',
            'groups' => $groups,
            'has_more_groups' => $hasMoreGroups,
        ];
    }

    protected function monthBucketExpression(string $dateField, string $driver): string
    {
        return match ($driver) {
            'pgsql' => "to_char({$dateField}, 'YYYY-MM')",
            'sqlsrv' => "FORMAT({$dateField}, 'yyyy-MM')",
            default => "strftime('%Y-%m', {$dateField})",
        };
    }

    protected function aggregateByField(
        $query,
        string $table,
        string $modelName,
        string $field,
        string $operation,
        string $groupBy,
        array $aggregate
    ): array {
        if (!Schema::hasColumn($table, $groupBy)) {
            return [
                'success' => false,
                'error' => "Group field '{$groupBy}' not found for aggregation",
            ];
        }

        if ($operation !== 'count' && !Schema::hasColumn($table, $field)) {
            return [
                'success' => false,
                'error' => "Field '{$field}' must be a database column for grouped aggregation",
            ];
        }

        $groupLimit = min((int) ($aggregate['limit'] ?? $this->policy->itemsPerPage()), $this->policy->itemsPerPage());
        if ($operation === 'summary') {
            $rows = (clone $query)
                ->select($groupBy)
                ->selectRaw('COUNT(*) as count_value')
                ->selectRaw("SUM({$field}) as sum_value")
                ->selectRaw("AVG({$field}) as avg_value")
                ->selectRaw("MIN({$field}) as min_value")
                ->selectRaw("MAX({$field}) as max_value")
                ->groupBy($groupBy)
                ->orderBy($groupBy)
                ->limit($groupLimit + 1)
                ->get();

            return $this->formatGroupedSummaryResult($rows, $groupLimit, $modelName, $field, $groupBy);
        }

        $aggregateExpression = $operation === 'count'
            ? 'COUNT(*)'
            : strtoupper($operation) . "({$field})";

        $rows = (clone $query)
            ->select($groupBy)
            ->selectRaw("{$aggregateExpression} as aggregate_value")
            ->groupBy($groupBy)
            ->orderBy($groupBy)
            ->limit($groupLimit + 1)
            ->get();

        $hasMoreGroups = $rows->count() > $groupLimit;
        $groups = $rows
            ->take($groupLimit)
            ->map(fn ($row) => [
                'bucket' => (string) ($row->{$groupBy} ?? 'none'),
                'value' => (float) $row->aggregate_value,
            ])
            ->values()
            ->all();

        $prefix = $operation === 'count' ? '' : '$';
        $label = ucfirst($operation) . ($operation === 'count' ? " {$modelName}" : " {$field}") . " by {$groupBy}";
        $lines = collect($groups)
            ->map(fn (array $group) => '- ' . $group['bucket'] . ': ' . $prefix . number_format($group['value'], 2))
            ->implode("\n");

        $response = "**{$label}**:\n{$lines}";
        if ($hasMoreGroups) {
            $response .= "\n\n*Showing first {$groupLimit} groups due to policy limits.*";
        }

        return [
            'success' => true,
            'response' => $response,
            'tool' => 'db_aggregate',
            'fast_path' => true,
            'result' => $groups,
            'count' => count($groups),
            'operation' => $operation,
            'field' => $field,
            'group_by' => $groupBy,
            'groups' => $groups,
            'has_more_groups' => $hasMoreGroups,
        ];
    }

    protected function defaultAggregateField(string $modelClass, string $operation): ?string
    {
        if ($operation === 'count') {
            return 'id';
        }

        $instance = new $modelClass();
        $table = $instance->getTable();
        $columns = Schema::getColumnListing($table);
        $scores = [];

        foreach ($columns as $column) {
            $lower = strtolower((string) $column);
            if (preg_match('/(^|_)id$/', $lower)) {
                continue;
            }

            $score = 0;
            if (in_array($lower, ['total', 'amount', 'total_amount', 'amount_total'], true)) {
                $score = 100;
            } elseif (str_contains($lower, 'total') || str_contains($lower, 'amount')) {
                $score = 85;
            } elseif (str_contains($lower, 'balance') || str_contains($lower, 'price') || str_contains($lower, 'cost')) {
                $score = 70;
            } elseif (str_contains($lower, 'subtotal') || str_contains($lower, 'tax')) {
                $score = 50;
            } elseif (str_contains($lower, 'quantity') || str_contains($lower, 'qty') || str_contains($lower, 'rate')) {
                $score = 40;
            }

            if ($score > 0) {
                $scores[$column] = $score;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    protected function formatSummaryResponse(string $modelName, string $field, array $result): string
    {
        return "**{$modelName} {$field} summary**:\n"
            . '- Count: ' . number_format((float) $result['count'], 0) . "\n"
            . '- Total: $' . number_format((float) $result['sum'], 2) . "\n"
            . '- Average: $' . number_format((float) $result['avg'], 2) . "\n"
            . '- Minimum: $' . number_format((float) $result['min'], 2) . "\n"
            . '- Maximum: $' . number_format((float) $result['max'], 2);
    }

    protected function formatGroupedSummaryResult($rows, int $groupLimit, string $modelName, string $field, string $groupBy): array
    {
        $hasMoreGroups = $rows->count() > $groupLimit;
        $groups = $rows
            ->take($groupLimit)
            ->map(function ($row) use ($groupBy): array {
                $bucket = $groupBy === 'month' ? $row->bucket : ($row->{$groupBy} ?? 'none');

                return [
                    'bucket' => (string) $bucket,
                    'count' => (int) $row->count_value,
                    'sum' => (float) $row->sum_value,
                    'avg' => (float) $row->avg_value,
                    'min' => (float) $row->min_value,
                    'max' => (float) $row->max_value,
                ];
            })
            ->values()
            ->all();

        $lines = collect($groups)
            ->map(fn (array $group) => '- ' . $group['bucket']
                . ': count ' . $group['count']
                . ', total $' . number_format($group['sum'], 2)
                . ', avg $' . number_format($group['avg'], 2)
                . ', min $' . number_format($group['min'], 2)
                . ', max $' . number_format($group['max'], 2))
            ->implode("\n");

        $response = "**{$modelName} {$field} summary by {$groupBy}**:\n{$lines}";
        if ($hasMoreGroups) {
            $response .= "\n\n*Showing first {$groupLimit} groups due to policy limits.*";
        }

        return [
            'success' => true,
            'response' => $response,
            'tool' => 'db_aggregate',
            'fast_path' => true,
            'result' => $groups,
            'count' => count($groups),
            'operation' => 'summary',
            'field' => $field,
            'group_by' => $groupBy,
            'groups' => $groups,
            'has_more_groups' => $hasMoreGroups,
        ];
    }

    protected function isMissingTableException(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'base table or view not found')
            || str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist');
    }
}
