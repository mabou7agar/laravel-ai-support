<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

/**
 * Generic, app-agnostic structured-data tool. Answers count / list / filter
 * questions with exact database results over the host's Eloquent models.
 *
 * Models are resolved from config('ai-engine.data_query.models') when provided,
 * otherwise auto-discovered from the same RAG-able models the engine already
 * knows about. The engine's router auto-routes structured queries to the
 * "data_query" tool, so registering this gives precise count/list answers out of
 * the box instead of approximate RAG retrieval. Read-only and scope-filtered.
 */
class DataQueryTool extends AgentTool
{
    /** @var array<string, string> preferred display columns, in priority order */
    protected array $preferredColumns = [
        'name', 'title', 'invoice_number', 'email', 'company', 'status', 'total', 'amount', 'price',
    ];

    public function __construct(
        protected ?RAGCollectionDiscovery $discovery = null,
    ) {}

    public function getName(): string
    {
        return 'data_query';
    }

    public function getDescription(): string
    {
        return 'Answer structured questions about stored records: count, list, or filter your data (e.g. "how many overdue invoices", "list recent customers").';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'description' => 'The natural-language data question.',
                'required' => true,
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum rows to return for list queries.',
                'required' => false,
            ],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = strtolower(trim((string) ($parameters['query'] ?? '')));
        $maxLimit = (int) config('ai-engine.data_query.max_limit', 50);
        $limit = max(1, min($maxLimit, (int) ($parameters['limit'] ?? 10)));

        if ($query === '') {
            return ActionResult::needsUserInput('What would you like to look up?');
        }

        $entities = $this->entities();
        $match = $this->resolveEntity($query, $entities);

        if ($match === null) {
            $known = implode(', ', array_keys($entities));

            return ActionResult::needsUserInput(
                $known === ''
                    ? 'No queryable data models are configured.'
                    : "I can look up: {$known}. Which one, and do you want a count or a list?"
            );
        }

        [$label, $config] = $match;
        $modelClass = $config['class'];

        /** @var Builder $builder */
        $builder = $modelClass::query();
        $table = (new $modelClass)->getTable();

        $scoped = $this->applyScope($builder, $table, $context);
        if (!$scoped && $this->requiresScope($config)) {
            return ActionResult::failure(
                "Access to '{$label}' is blocked: no user/workspace/tenant scope could be applied "
                . '(the model has no scope column or there is no caller context). Mark the model '
                . "'public' => true in ai-engine.data_query.models to allow unscoped access."
            );
        }

        $appliedStatus = $this->applyStatusFilter($builder, $table, $config, $query);
        $displayLabel = $appliedStatus !== null ? "{$appliedStatus} {$label}" : $label;

        if (preg_match('/\b(how many|count|number of|total number|how much)\b/i', $query)) {
            $count = (clone $builder)->count();

            return ActionResult::success(
                sprintf('You have %d %s.', $count, $displayLabel),
                ['operation' => 'count', 'entity' => $label, 'status' => $appliedStatus, 'count' => $count],
                ['tool' => $this->getName()]
            );
        }

        $columns = $this->listColumns($table, $config);
        $ordered = Schema::hasColumn($table, 'created_at')
            ? $builder->latest()
            : $builder->orderByDesc((new $modelClass)->getKeyName());
        $rows = $ordered->limit($limit)->get($columns)
            ->map(static fn (Model $m) => $m->only($columns))->all();

        $message = $rows === []
            ? sprintf('No %s found.', $displayLabel)
            : sprintf('Found %d %s%s.', count($rows), $displayLabel, count($rows) === $limit ? ' (most recent)' : '');

        return ActionResult::success(
            $message,
            ['operation' => 'list', 'entity' => $label, 'status' => $appliedStatus, 'rows' => $rows],
            ['tool' => $this->getName()]
        );
    }

    /**
     * Build the entity map: keyword => config. Config-defined models win;
     * otherwise discover RAG-able models and derive keywords from class names.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function entities(): array
    {
        $configured = (array) config('ai-engine.data_query.models', []);
        $entities = [];

        foreach ($configured as $keyword => $cfg) {
            $class = is_array($cfg) ? ($cfg['class'] ?? null) : $cfg;
            if (!$this->isQueryableModel($class)) {
                continue;
            }
            $entities[strtolower((string) $keyword)] = $this->normalizeConfig((string) $class, is_array($cfg) ? $cfg : []);
        }

        if ($entities === [] && config('ai-engine.data_query.use_discovery', true) && $this->discovery !== null) {
            foreach ($this->safeDiscover() as $class) {
                if (!$this->isQueryableModel($class)) {
                    continue;
                }
                $keyword = Str::snake(class_basename($class));
                $entities[$keyword] = $this->normalizeConfig($class, []);
            }
        }

        return $entities;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}|null [label, config]
     */
    protected function resolveEntity(string $query, array $entities): ?array
    {
        foreach ($entities as $keyword => $config) {
            foreach ($config['aliases'] as $alias) {
                if (preg_match('/\b' . preg_quote($alias, '/') . '\b/i', $query)) {
                    return [$config['label'], $config];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function normalizeConfig(string $class, array $extra): array
    {
        $base = Str::snake(class_basename($class));
        $aliases = $extra['aliases'] ?? [$base, Str::plural($base)];

        return [
            'class' => $class,
            'label' => $extra['label'] ?? Str::plural($base),
            'aliases' => array_values(array_unique(array_map('strtolower', (array) $aliases))),
            'list' => $extra['list'] ?? null,
            'status_column' => $extra['status_column'] ?? 'status',
            'statuses' => array_map('strtolower', (array) ($extra['statuses'] ?? [])),
            // Intentionally-unscoped (catalog-style) model: bypass require_scope.
            'public' => (bool) ($extra['public'] ?? false),
            // Aggregation allowlists (see AggregateQueryTool): numeric columns that may be
            // summed/averaged/etc, dimension columns that may be grouped by, and friendly
            // metric aliases ('revenue' => 'total'). Empty = aggregation disabled for this model.
            'aggregatable' => array_values((array) ($extra['aggregatable'] ?? [])),
            'groupable' => array_values((array) ($extra['groupable'] ?? [])),
            'metric_aliases' => (array) ($extra['metric_aliases'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function requiresScope(array $config): bool
    {
        if ($config['public'] ?? false) {
            return false;
        }

        return (bool) config('ai-engine.data_query.require_scope', true);
    }

    /**
     * Apply per-request access scope. Returns true when at least one scope
     * predicate (user/workspace/tenant) was actually applied to the query.
     */
    protected function applyScope(Builder $builder, string $table, UnifiedActionContext $context): bool
    {
        $values = [
            'user_id' => auth()->id() ?? $context->userId,
            'workspace_id' => $context->metadata['workspace_id'] ?? null,
            'tenant_id' => $context->metadata['tenant_id'] ?? null,
        ];

        $applied = false;
        foreach ((array) config('ai-engine.data_query.scope_columns', ['user_id', 'workspace_id', 'tenant_id']) as $column) {
            $value = $values[$column] ?? null;
            if ($value !== null && $value !== '' && Schema::hasColumn($table, $column)) {
                $builder->where($column, $value);
                $applied = true;
            }
        }

        return $applied;
    }

    /**
     * @param array<string, mixed> $config
     */
    protected function applyStatusFilter(Builder $builder, string $table, array $config, string $query): ?string
    {
        $column = $config['status_column'] ?? null;
        if (!$column || !Schema::hasColumn($table, $column)) {
            return null;
        }

        // Use configured statuses, or derive distinct values from the table so
        // status filtering ("overdue invoices") works without per-model config.
        $candidates = $config['statuses'];
        if ($candidates === []) {
            try {
                $candidates = $builder->getModel()->newQuery()
                    ->select($column)->distinct()->limit(30)->pluck($column)
                    ->filter(static fn ($v) => is_string($v) && $v !== '')
                    ->values()->all();
            } catch (\Throwable) {
                $candidates = [];
            }
        }

        foreach ($candidates as $status) {
            $needle = strtolower((string) $status);
            if ($needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/i', $query)) {
                $builder->where($column, $status);

                return $needle;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, string>
     */
    protected function listColumns(string $table, array $config): array
    {
        if (is_array($config['list']) && $config['list'] !== []) {
            $columns = array_values(array_filter($config['list'], static fn ($c) => Schema::hasColumn($table, $c)));

            return $columns === [] ? ['id'] : array_values(array_unique(array_merge(['id'], $columns)));
        }

        $columns = ['id'];
        foreach ($this->preferredColumns as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                $columns[] = $candidate;
            }
            if (count($columns) >= 5) {
                break;
            }
        }

        return array_values(array_unique($columns));
    }

    protected function isQueryableModel(mixed $class): bool
    {
        return is_string($class) && class_exists($class) && is_subclass_of($class, Model::class);
    }

    /**
     * @return array<int, string>
     */
    protected function safeDiscover(): array
    {
        try {
            $collections = $this->discovery->discover();
        } catch (\Throwable) {
            return [];
        }

        $classes = [];
        foreach ($collections as $entry) {
            $class = is_array($entry) ? ($entry['class'] ?? $entry['model'] ?? null) : $entry;
            if (is_string($class)) {
                $classes[] = $class;
            }
        }

        return array_values(array_unique($classes));
    }

    protected function getRequiredParameters(): array
    {
        return ['query'];
    }
}
