<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Compute real aggregates over stored records — the questions data_query (count/list/filter)
 * cannot answer correctly.
 *
 * "Total revenue", "average invoice value", "the most expensive invoice", "which customer
 * spent the most" need SUM/AVG/MIN/MAX, ranking, and GROUP BY across the WHOLE table — not a
 * 10-row list the model then eyeballs (which silently produces 10x-wrong numbers). This tool
 * runs the aggregate in SQL, scope-filtered and column-allowlisted, so the planner gets exact
 * figures instead of estimating from a sample.
 *
 * Security: metric/group_by columns must be declared in the model's `aggregatable` /
 * `groupable` allowlists (ai-engine.data_query.models) AND exist on the table; otherwise the
 * tool fails closed. Access scope (user/workspace/tenant) is applied exactly as in data_query.
 */
class AggregateQueryTool extends DataQueryTool
{
    private const SCALAR_OPS = ['sum', 'avg', 'min', 'max', 'count'];

    private const RECORD_OPS = ['top', 'bottom'];

    public function getName(): string
    {
        return 'aggregate_data';
    }

    public function getDescription(): string
    {
        return 'Compute exact aggregates over your records: sum/total, average, minimum, '
            . 'maximum, a count, the top/bottom records ranked by a numeric column, or a '
            . 'per-group breakdown. Use this for ANY "total revenue", "average value", '
            . '"highest/lowest", "most/least expensive", "which X has the most", or "per X" '
            . 'question. Never estimate these from a list — call this tool so the numbers are '
            . 'correct across all rows. For a figure about ONE specific customer / product / '
            . 'status (e.g. "how much has Apollo Labs spent", "revenue for product X"), pass '
            . 'that value in `filters` (e.g. {"customer_name": "Apollo Labs"}) so you get that '
            . 'subset, NOT the whole-table total. Relative time ("this month", "today") is '
            . 'applied automatically from the question. Also use this with `group_by` to list '
            . 'the distinct values of a dimension or count them — "list invoice products" / '
            . '"how many different products" → group_by the product column (operation=count '
            . 'returns the distinct count).';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'The natural-language analytics question. Optional if you pass structured operation/metric/group_by params.',
                'required' => false,
            ],
            'entity' => [
                'type' => 'string',
                'description' => 'Which data model to analyze (e.g. invoice, line_item, product). Omit to infer from the question.',
                'required' => false,
            ],
            'operation' => [
                'type' => 'string',
                'description' => "One of: sum, avg, min, max, count (a single number); or top, bottom (the actual records ranked by `metric`). Omit to infer from the question.",
                'required' => false,
            ],
            'metric' => [
                'type' => 'string',
                'description' => 'Numeric column to aggregate/rank by (e.g. total). Must be allowlisted. Omit to infer.',
                'required' => false,
            ],
            'group_by' => [
                'type' => 'string',
                'description' => 'Dimension column to group by (e.g. customer_name, status) for a per-group breakdown. Must be allowlisted. Omit for a whole-table figure.',
                'required' => false,
            ],
            'filters' => [
                'type' => 'object',
                'description' => 'Exact field=value filters to narrow the rows before aggregating, e.g. {"customer_name": "Apollo Labs"}. Use for "X\'s total", "revenue for product Y". Only allowlisted columns apply. Relative time ("this month", "today") is parsed from the question automatically.',
                'required' => false,
            ],
            'direction' => [
                'type' => 'string',
                'description' => 'desc (most/highest, default) or asc (least/lowest).',
                'required' => false,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'How many ranked rows/groups to return (default 5).',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = strtolower(trim((string) ($parameters['query'] ?? '')));
        $entityParam = strtolower(trim((string) ($parameters['entity'] ?? '')));
        if ($query === '' && $entityParam === '') {
            return ActionResult::needsUserInput('What would you like to calculate?');
        }

        $operation = $this->resolveOperation($parameters, $query);

        $entities = $this->entities();
        $match = $this->resolveAggregateEntity($entityParam, $query, $entities, $operation);
        if ($match === null) {
            $known = implode(', ', array_keys($entities));

            return ActionResult::needsUserInput(
                $known === '' ? 'No queryable data models are configured.' : "I can analyze: {$known}. Which one?"
            );
        }

        [$label, $config] = $match;
        $modelClass = $config['class'];

        /** @var Builder $builder */
        $builder = $modelClass::query();
        $table = (new $modelClass)->getTable();

        $scoped = $this->applyScope($builder, $table, $context, $config);
        if (!$scoped && $this->requiresScope($config)) {
            return ActionResult::failure(
                "Access to '{$label}' is blocked: no user/workspace/tenant scope could be applied. "
                . "Mark the model 'public' => true in ai-engine.data_query.models to allow unscoped access."
            );
        }

        $appliedStatus = $this->applyStatusFilter($builder, $table, $config, $query);
        // Narrow by relative time ("this month") and exact field values (filters) so the
        // aggregate is for the requested subset, not the whole table.
        $this->applyDateFilter($builder, $table, $config, $query);
        $this->applyValueFilters($builder, $table, $config, $parameters);

        $aggregatable = array_values(array_filter((array) $config['aggregatable'], fn ($c): bool => is_string($c) && Schema::hasColumn($table, $c)));
        $metric = $this->resolveMetric($parameters, $query, $config, $aggregatable, $table);
        $groupBy = $this->resolveGroupBy($parameters, $query, $config, $table);
        $direction = $this->resolveDirection($parameters, $query);
        $limit = $this->resolveLimit($parameters, $query, $operation, $groupBy);

        if ($operation !== 'count' && $metric === null) {
            if ($aggregatable === []) {
                return ActionResult::failure(
                    "Aggregation is not enabled for {$label}. Add an 'aggregatable' column list to ai-engine.data_query.models."
                );
            }

            return ActionResult::needsUserInput(
                'Which value should I use? Options: ' . implode(', ', $aggregatable) . '.'
            );
        }

        if ($groupBy !== null) {
            // "how many invoice products" / "how many different customers" — count of DISTINCT
            // dimension values, not COUNT(*) per group.
            if ($operation === 'count') {
                return $this->distinctCount($builder, $label, $groupBy, $appliedStatus);
            }

            return $this->grouped($builder, $label, $operation, $metric, $groupBy, $direction, $limit, $appliedStatus);
        }

        if (in_array($operation, self::RECORD_OPS, true)) {
            return $this->rankedRecords($builder, $table, $config, $label, $operation, (string) $metric, $direction, $limit, $appliedStatus);
        }

        return $this->scalar($builder, $label, $operation, $metric, $appliedStatus);
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function resolveAggregateEntity(string $entityParam, string $query, array $entities, string $operation): ?array
    {
        if ($entityParam !== '') {
            foreach ($entities as $keyword => $config) {
                if ($keyword === $entityParam || in_array($entityParam, (array) $config['aliases'], true)) {
                    return [$config['label'], $config];
                }
            }
        }

        // Candidates: any entity the question names by alias, or by a groupable dimension it
        // exposes ("products by revenue" reaches line items via their product_name dimension).
        $byAlias = [];
        $byDimension = [];
        foreach ($entities as $config) {
            if ($this->aliasReferenced($config, $query)) {
                $byAlias[] = $config;
            } elseif ($this->groupableReferenced($config, $query)) {
                $byDimension[] = $config;
            }
        }
        $candidates = array_merge($byAlias, $byDimension);

        if ($operation === 'count') {
            // Count keeps to the named entity (count rows of it), not a metric-bearing relation.
            $pick = $byAlias[0] ?? $candidates[0] ?? null;

            return $pick !== null ? [$pick['label'], $pick] : $this->resolveEntity($query, $entities);
        }

        // Score: owning the referenced metric dominates (price vs revenue disambiguation),
        // then grouping by a referenced dimension, then merely being aggregatable.
        $best = null;
        $bestScore = 0.0;
        foreach ($candidates as $config) {
            $score = ($this->ownsReferencedMetric($config, $query) ? 3.0 : 0.0)
                + ($this->groupableReferenced($config, $query) ? 1.0 : 0.0)
                + ($this->isAggregatable($config) ? 0.5 : 0.0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $config;
            }
        }
        if ($best !== null && $bestScore > 0.0) {
            return [$best['label'], $best];
        }

        // Nothing aggregatable named — redirect to an entity that groups by / holds the metric
        // the question references (e.g. "which customer spent the most" → invoices).
        $redirect = $this->aggregatableEntityGroupedBy($query, $entities)
            ?? $this->aggregatableEntityWithMetric($query, $entities);
        if ($redirect !== null) {
            return $redirect;
        }

        if ($candidates !== []) {
            return [$candidates[0]['label'], $candidates[0]];
        }

        return $this->resolveEntity($query, $entities);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function aliasReferenced(array $config, string $query): bool
    {
        foreach ((array) ($config['aliases'] ?? []) as $alias) {
            if (preg_match('/\b' . preg_quote((string) $alias, '/') . '\b/i', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the question name one of this entity's aggregatable columns (or a metric alias)?
     *
     * @param array<string, mixed> $config
     */
    private function ownsReferencedMetric(array $config, string $query): bool
    {
        $aggregatable = (array) ($config['aggregatable'] ?? []);
        foreach ($aggregatable as $column) {
            if ($this->columnAppearsIn((string) $column, $query)) {
                return true;
            }
        }
        foreach ((array) ($config['metric_aliases'] ?? []) as $alias => $column) {
            if (in_array((string) $column, $aggregatable, true)
                && preg_match('/\b' . preg_quote(strtolower((string) $alias), '/') . '\b/', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function groupableReferenced(array $config, string $query): bool
    {
        foreach ((array) ($config['groupable'] ?? []) as $column) {
            foreach ($this->groupAliases((string) $column) as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $query) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * First aggregatable entity whose metric column (or alias) the question names — e.g.
     * "total tax collected" has no entity word but `tax` is an invoice metric.
     *
     * @param array<string, array<string, mixed>> $entities
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function aggregatableEntityWithMetric(string $query, array $entities): ?array
    {
        foreach ($entities as $config) {
            if (!$this->isAggregatable($config)) {
                continue;
            }
            foreach ((array) $config['aggregatable'] as $column) {
                if ($this->columnAppearsIn((string) $column, $query)) {
                    return [$config['label'], $config];
                }
            }
            foreach ((array) ($config['metric_aliases'] ?? []) as $alias => $column) {
                if (in_array((string) $column, (array) $config['aggregatable'], true)
                    && preg_match('/\b' . preg_quote(strtolower((string) $alias), '/') . '\b/', $query) === 1) {
                    return [$config['label'], $config];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isAggregatable(array $config): bool
    {
        return array_values(array_filter((array) ($config['aggregatable'] ?? []))) !== [];
    }

    /**
     * First aggregatable entity that has a groupable column the question refers to.
     *
     * @param array<string, array<string, mixed>> $entities
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function aggregatableEntityGroupedBy(string $query, array $entities): ?array
    {
        foreach ($entities as $config) {
            if (!$this->isAggregatable($config)) {
                continue;
            }
            foreach ((array) ($config['groupable'] ?? []) as $column) {
                foreach ($this->groupAliases((string) $column) as $alias) {
                    if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $query) === 1) {
                        return [$config['label'], $config];
                    }
                }
            }
        }

        return null;
    }

    private function scalar(Builder $builder, string $label, string $operation, ?string $metric, ?string $status): ActionResult
    {
        $value = match ($operation) {
            'count' => (clone $builder)->count(),
            'sum' => (float) (clone $builder)->sum($metric),
            'avg' => round((float) (clone $builder)->avg($metric), 2),
            'min' => (float) (clone $builder)->min($metric),
            'max' => (float) (clone $builder)->max($metric),
            default => null,
        };

        $scope = $status !== null ? "{$status} {$label}" : $label;
        $metricLabel = str_replace('_', ' ', (string) $metric);
        $message = match ($operation) {
            'count' => sprintf('You have %s %s.', $this->num($value), $scope),
            'sum' => sprintf('Total %s across all %s: %s.', $metricLabel, $scope, $this->num($value)),
            'avg' => sprintf('Average %s per %s: %s.', $metricLabel, Str::singular($scope), $this->num($value)),
            'min' => sprintf('Lowest %s among %s: %s.', $metricLabel, $scope, $this->num($value)),
            'max' => sprintf('Highest %s among %s: %s.', $metricLabel, $scope, $this->num($value)),
            default => 'Result.',
        };

        return ActionResult::success($message, [
            'operation' => $operation,
            'entity' => $label,
            'metric' => $metric,
            'status' => $status,
            'value' => $value,
        ], ['tool' => $this->getName()]);
    }

    private function distinctCount(Builder $builder, string $label, string $groupBy, ?string $status): ActionResult
    {
        $value = (int) (clone $builder)->distinct()->count($groupBy);
        $dimension = str_replace('_', ' ', $groupBy);
        $scope = $status !== null ? "{$status} {$label}" : $label;

        return ActionResult::success(
            sprintf('There are %s distinct %s across %s.', $this->num($value), $dimension, $scope),
            [
                'operation' => 'count_distinct',
                'entity' => $label,
                'group_by' => $groupBy,
                'status' => $status,
                'value' => $value,
            ],
            ['tool' => $this->getName()]
        );
    }

    private function grouped(Builder $builder, string $label, string $operation, ?string $metric, string $groupBy, string $direction, int $limit, ?string $status): ActionResult
    {
        $agg = in_array($operation, self::RECORD_OPS, true) ? 'sum' : $operation;

        $select = (clone $builder)->select($groupBy)->groupBy($groupBy)->limit($limit);
        if ($operation === 'count') {
            $select->selectRaw('COUNT(*) as agg_value');
        } else {
            $select->selectRaw(strtoupper($agg) . "({$metric}) as agg_value")->selectRaw('COUNT(*) as agg_count');
        }
        $rows = $select->orderBy('agg_value', $direction)->get();

        $groups = $rows->map(static fn (Model $r): array => [
            'key' => $r->getAttribute($groupBy),
            'value' => (float) $r->getAttribute('agg_value'),
            'count' => (int) ($r->getAttribute('agg_count') ?? 0),
        ])->all();

        $metricLabel = $operation === 'count' ? 'count' : ($agg . ' of ' . str_replace('_', ' ', (string) $metric));
        $scope = $status !== null ? "{$status} {$label}" : $label;
        $rank = $direction === 'asc' ? 'lowest' : 'highest';
        $message = sprintf('%s by %s (%s %s first):', ucfirst($scope), str_replace('_', ' ', $groupBy), $rank, $metricLabel);

        return ActionResult::success($message, [
            'operation' => $operation,
            'entity' => $label,
            'metric' => $metric,
            'group_by' => $groupBy,
            'direction' => $direction,
            'status' => $status,
            'groups' => $groups,
        ], ['tool' => $this->getName()]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function rankedRecords(Builder $builder, string $table, array $config, string $label, string $operation, string $metric, string $direction, int $limit, ?string $status): ActionResult
    {
        $columns = $this->listColumns($table, $config);
        if (!in_array($metric, $columns, true)) {
            $columns[] = $metric;
        }

        $rows = $builder->orderBy($metric, $direction)->limit($limit)->get($columns)
            ->map(static fn (Model $m): array => $m->only($columns))->all();

        $rank = $operation === 'bottom' ? 'lowest' : 'highest';
        $scope = $status !== null ? "{$status} {$label}" : $label;
        $message = $rows === []
            ? sprintf('No %s found.', $scope)
            : sprintf('%s %s by %s (%s first):', ucfirst($rank), $scope, str_replace('_', ' ', $metric), $rank);

        return ActionResult::success($message, [
            'operation' => $operation,
            'entity' => $label,
            'metric' => $metric,
            'direction' => $direction,
            'status' => $status,
            'rows' => $rows,
        ], ['tool' => $this->getName()]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveOperation(array $parameters, string $query): string
    {
        $explicit = strtolower(trim((string) ($parameters['operation'] ?? '')));
        if (in_array($explicit, array_merge(self::SCALAR_OPS, self::RECORD_OPS), true)) {
            return $explicit;
        }

        // "how many units sold" is a SUM of quantity, not a row count.
        if (preg_match('/\b(units?\s+sold|quantity\s+sold|qty\s+sold)\b/', $query)) {
            return 'sum';
        }
        if (preg_match('/\b(how many|number of|count of|count the)\b/', $query)) {
            return 'count';
        }
        if (preg_match('/\b(average|avg|mean|typical)\b/', $query)) {
            return 'avg';
        }
        if (preg_match('/\b(sum|combined|altogether|in total|grand total|total revenue|total sales|total of|total amount|total value)\b/', $query)) {
            return 'sum';
        }
        if (preg_match('/\b(cheapest|lowest|smallest|least expensive|min|minimum|bottom|worst)\b/', $query)) {
            return 'bottom';
        }
        if (preg_match('/\b(most expensive|highest|largest|biggest|priciest|top|max|maximum|best|most)\b/', $query)) {
            return 'top';
        }
        // "total revenue"/"revenue"/"how much" lean to sum; otherwise default to sum (a safe scalar).
        if (preg_match('/\b(revenue|how much|sales)\b/', $query)) {
            return 'sum';
        }

        return 'sum';
    }

    /**
     * @param array<string, mixed>   $parameters
     * @param array<string, mixed>   $config
     * @param array<int, string>     $aggregatable
     */
    private function resolveMetric(array $parameters, string $query, array $config, array $aggregatable, string $table): ?string
    {
        $explicit = strtolower(trim((string) ($parameters['metric'] ?? '')));
        if ($explicit !== '' && in_array($explicit, $aggregatable, true)) {
            return $explicit;
        }

        // Friendly aliases first ('revenue' => 'total').
        foreach ((array) $config['metric_aliases'] as $alias => $column) {
            $column = (string) $column;
            if (in_array($column, $aggregatable, true)
                && preg_match('/\b' . preg_quote(strtolower((string) $alias), '/') . '\b/', $query) === 1) {
                return $column;
            }
        }

        // Then explicit column names in the question. Collect all matches so we can prefer a
        // specific metric over one that doubles as an operation word ("total tax" → tax, not
        // total).
        $matches = [];
        foreach ($aggregatable as $column) {
            if ($this->columnAppearsIn($column, $query)) {
                $matches[] = $column;
            }
        }
        if ($matches !== []) {
            $ambiguous = ['total', 'sum', 'count', 'amount'];
            $specific = array_values(array_filter($matches, static fn (string $c): bool => !in_array($c, $ambiguous, true)));

            return $specific[0] ?? $matches[0];
        }

        // Default to the first allowlisted numeric column (commonly the money total).
        return $aggregatable[0] ?? null;
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $config
     */
    private function resolveGroupBy(array $parameters, string $query, array $config, string $table): ?string
    {
        $groupable = array_values(array_filter((array) $config['groupable'], fn ($c): bool => is_string($c) && Schema::hasColumn($table, $c)));
        if ($groupable === []) {
            return null;
        }

        $explicit = strtolower(trim((string) ($parameters['group_by'] ?? '')));
        if ($explicit !== '' && in_array($explicit, $groupable, true)) {
            return $explicit;
        }

        foreach ($groupable as $column) {
            foreach ($this->groupAliases($column) as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/', $query) === 1) {
                    return $column;
                }
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function groupAliases(string $column): array
    {
        $spaced = str_replace('_', ' ', $column);
        $base = (string) Str::of($column)->beforeLast('_id')->beforeLast('_name');

        return array_values(array_unique(array_filter([
            $column,
            $spaced,
            $base,
            str_replace('_', ' ', $base),
            Str::singular($base),
            Str::plural($base),
            Str::plural(str_replace('_', ' ', $base)),
        ], static fn (string $v): bool => $v !== '')));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveDirection(array $parameters, string $query): string
    {
        $explicit = strtolower(trim((string) ($parameters['direction'] ?? '')));
        if (in_array($explicit, ['asc', 'desc'], true)) {
            return $explicit;
        }

        if (preg_match('/\b(cheapest|lowest|smallest|least|min|minimum|bottom|worst|ascending)\b/', $query)) {
            return 'asc';
        }

        return 'desc';
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveLimit(array $parameters, string $query, string $operation, ?string $groupBy): int
    {
        $maxLimit = (int) config('ai-engine.data_query.max_limit', 50);
        if (isset($parameters['limit']) && is_numeric($parameters['limit'])) {
            return max(1, min($maxLimit, (int) $parameters['limit']));
        }

        // Scalar whole-table aggregate ignores limit; ranked/grouped default to a small top-N.
        if ($groupBy === null && in_array($operation, self::SCALAR_OPS, true)) {
            return 1;
        }

        // "list all / every / each" wants the full breakdown, not just the top few.
        if (preg_match('/\b(all|every|each|list|full|complete|entire)\b/', $query) === 1) {
            return $maxLimit;
        }

        return 5;
    }

    /**
     * Does a column name (singular, plural, or space-separated) appear as a whole word?
     */
    private function columnAppearsIn(string $column, string $query): bool
    {
        $spaced = str_replace('_', ' ', $column);
        $candidates = array_unique([$column, $spaced, Str::plural($column), Str::plural($spaced)]);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && preg_match('/\b' . preg_quote($candidate, '/') . '\b/', $query) === 1) {
                return true;
            }
        }

        return false;
    }

    private function num(float|int|null $value): string
    {
        if ($value === null) {
            return '0';
        }

        return $value == (int) $value
            ? number_format((int) $value)
            : number_format((float) $value, 2);
    }
}
