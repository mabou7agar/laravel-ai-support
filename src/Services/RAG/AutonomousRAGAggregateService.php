<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AutonomousRAGAggregateService
{
    public function __construct(protected AutonomousRAGPolicy $policy)
    {
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
            return ['success' => false, 'error' => 'No field specified for aggregation'];
        }

        try {
            $query = $modelClass::query();
            $instance = new $modelClass();
            $table = $instance->getTable();

            if (!empty($filterConfig['eager_load'])) {
                $query->with($filterConfig['eager_load']);
            }

            if (method_exists($modelClass, 'scopeForUser')) {
                $query->forUser($userId);
            } elseif (!empty($filterConfig['user_field'])) {
                $userField = $filterConfig['user_field'];
                if (Schema::hasColumn($table, $userField)) {
                    $query->where($userField, $userId);
                }
            }

            $query = $dependencies['applyFilters']($query, $params['filters'] ?? [], $modelClass, $options);

            if ($groupBy === 'month') {
                return $this->aggregateByMonth(
                    $query,
                    $table,
                    $modelName,
                    $field,
                    $operation,
                    $aggregate,
                    $filterConfig
                );
            }

            $isDbField = Schema::hasColumn($table, $field);
            $methodName = 'get' . ucfirst($field);
            $hasMethod = method_exists($instance, $methodName);

            $result = null;
            $count = 0;
            $calculationMethod = null;

            if ($isDbField) {
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

            $formattedResult = is_numeric($result) ? number_format($result, 2) : $result;
            $prefix = $operation === 'count' ? '' : '$';

            return [
                'success' => true,
                'response' => "**{$label} {$field}**: {$prefix}{$formattedResult} (from {$count} {$modelName}s)",
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
    ): array {
        $dateField = $aggregate['date_field'] ?? $filterConfig['date_field'] ?? 'created_at';
        if (!Schema::hasColumn($table, $field)) {
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

    protected function isMissingTableException(\Exception $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'base table or view not found')
            || str_contains($message, 'no such table')
            || str_contains($message, 'doesn\'t exist');
    }
}
