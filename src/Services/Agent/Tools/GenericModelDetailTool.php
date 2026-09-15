<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Show ONE record with its full details, INCLUDING related rows (a model's children).
 *
 * find_<name> (lookup) and data_query (list/count) return scalar columns only, so an agent
 * can see an invoice's id/total but not its line items. This tool eager-loads the
 * configured relations and returns their rows, so "show this X" and "list the items on X"
 * return real related data instead of falling back to the knowledge base. Built by
 * {@see AiResource} when ->with()/->detail() is configured.
 */
class GenericModelDetailTool extends AgentTool
{
    public const SENSITIVE_COLUMN_PATTERNS = [
        '/(^|_)(password|passwd|secret|api_key|apikey|private_key|otp)(_|$)/i',
        '/(^|_)token$/i',
        '/(^|_)two_factor(_|$)/i',
        '/(^|_)(account_number|iban|swift|bank_identifier_code|routing_number|card_number|cvv)(_|$)/i',
        '/(^|_)(tax_payer_id|national_id|passport|ssn|social_security)(_|$)/i',
    ];

    /** @var (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null */
    protected $scopeResolver;

    /**
     * @param class-string                              $model
     * @param array<int, string>                        $search    columns to look up by (besides id)
     * @param array<int, string>                        $returns   record columns to return ([] = all)
     * @param array<string, array<int, string>>         $relations relation => columns ([] = all)
     * @param (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null $scope
     */
    public function __construct(
        protected string $toolName,
        protected string $model,
        protected array $search = [],
        protected array $returns = [],
        protected array $relations = [],
        protected string $descriptionText = '',
        ?Closure $scope = null
    ) {
        $this->scopeResolver = $scope;
    }

    public function getName(): string
    {
        return $this->toolName;
    }

    public function getDescription(): string
    {
        if ($this->descriptionText !== '') {
            return $this->descriptionText;
        }

        $label = str_replace('_', ' ', \Illuminate\Support\Str::after($this->toolName, 'show_') ?: $this->toolName);
        $rel = array_keys($this->relations);
        $by = array_map(static fn (string $c): string => str_replace('_', ' ', $c), $this->search);

        return "Show a {$label} with its full details"
            . ($rel !== [] ? ' including its ' . implode(', ', $rel) . '.' : '.')
            . ' Look it up by id'
            . ($by !== [] ? ' or by ' . implode(', ', $by) : '')
            . ". Use this to display, find, or search for a {$label}"
            . ($by !== [] ? ' (e.g. for a given ' . $by[0] . ')' : '')
            . " or to list its related items — do not use the knowledge base for its contents.";
    }

    public function getParameters(): array
    {
        $parameters = ['id' => ['type' => 'integer', 'required' => false, 'description' => 'Record id.']];
        foreach ($this->search as $column) {
            $parameters[$column] = ['type' => 'string', 'required' => false, 'description' => str_replace('_', ' ', $column)];
        }

        return $parameters;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $modelClass = $this->model;
        if (!class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.record_type_unavailable', 'This record type is not available.'), ['found' => false]);
        }

        $query = $modelClass::query();
        foreach ($this->scope($context, $parameters) as $column => $value) {
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        // Planners often wrap identifiers: {"filters": {"customer_code": "C-1"}}.
        foreach (['filters', 'where', 'criteria', 'search', 'conditions'] as $wrapper) {
            if (is_array($parameters[$wrapper] ?? null)) {
                $parameters += $parameters[$wrapper];
            }
        }

        $id = $parameters['id'] ?? null;
        $matchedBy = false;
        if (is_numeric($id)) {
            $query->whereKey((int) $id);
            $matchedBy = true;
        } else {
            foreach ($this->search as $column) {
                $value = trim((string) ($parameters[$column] ?? ''));
                if ($value !== '' && $this->columnExists($modelClass, $column)) {
                    $query->where($column, 'like', "%{$value}%");
                    $matchedBy = true;
                }
            }
        }

        if (!$matchedBy) {
            // No identifier — show the most recent ("show the invoice").
            $query->latest((new $modelClass())->getKeyName());
        }

        $relationNames = array_values(array_filter(array_keys($this->relations), fn ($r): bool => method_exists(new $modelClass(), (string) $r)));
        $record = $query->with($relationNames)->first();

        if (!$record instanceof Model) {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.record_not_found', 'Record was not found.'), ['found' => false, 'message' => 'No matching record was found.']);
        }

        // Say when no identifier was given, so the planner (and user) do not mistake "the most
        // recent record" for a match on something they asked about.
        return ActionResult::success($matchedBy ? 'Record loaded.' : 'No identifier was given, so this is the most recent record.', array_merge(
            ['found' => true, 'matched_by' => $matchedBy ? 'identifier' : 'most_recent', 'record' => $this->recordColumns($record)],
            ['relations' => $this->relationRows($record)]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordColumns(Model $record): array
    {
        $columns = $this->returns !== [] ? $this->returns : $this->visibleColumns($record);
        $payload = [];
        foreach ($columns as $column) {
            if ($column === 'id') {
                $payload['id'] = $record->getKey();
            } elseif (array_key_exists($column, $record->getAttributes())) {
                $payload[$column] = $record->getAttribute($column);
            }
        }

        return $payload;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function relationRows(Model $record): array
    {
        $out = [];
        foreach ($this->relations as $relation => $columns) {
            if (!$record->relationLoaded($relation)) {
                continue;
            }
            $rows = $record->getRelation($relation);
            $rows = $rows instanceof Model ? collect([$rows]) : collect($rows);

            $out[$relation] = $rows->map(function (Model $row) use ($columns): array {
                $attributes = $row->getAttributes();
                if ($columns === []) {
                    return array_merge(['id' => $row->getKey()], array_intersect_key($attributes, array_flip($this->visibleColumns($row))));
                }
                $picked = [];
                foreach ($columns as $column) {
                    if ($column === 'id') {
                        $picked['id'] = $row->getKey();
                    } elseif (array_key_exists($column, $attributes)) {
                        $picked[$column] = $attributes[$column];
                    }
                }

                return $picked;
            })->values()->all();
        }

        return $out;
    }

    /**
     * Columns safe to expose when no explicit column list was configured: the model's
     * $hidden/$visible rules apply, and credential-like columns are always withheld.
     *
     * @return array<int, string>
     */
    private function visibleColumns(Model $record): array
    {
        $columns = array_keys($record->getAttributes());
        $visible = $record->getVisible();
        if ($visible !== []) {
            $columns = array_values(array_intersect($columns, $visible));
        }

        $hidden = $record->getHidden();
        $patterns = (array) config('ai-engine.agent_tools.sensitive_column_patterns', self::SENSITIVE_COLUMN_PATTERNS);

        return array_values(array_filter($columns, static function (string $column) use ($hidden, $patterns): bool {
            if (in_array($column, $hidden, true)) {
                return false;
            }

            foreach ($patterns as $pattern) {
                if (@preg_match((string) $pattern, $column) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function scope(UnifiedActionContext $context, array $parameters): array
    {
        return $this->scopeResolver !== null ? (array) ($this->scopeResolver)($context, $parameters) : [];
    }

    private function columnExists(string $modelClass, string $column): bool
    {
        $table = (new $modelClass())->getTable();

        return Schema::hasTable($table) && Schema::hasColumn($table, $column);
    }
}
