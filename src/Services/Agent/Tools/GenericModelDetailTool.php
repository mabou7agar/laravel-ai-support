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

        return "Show a {$label} with its full details"
            . ($rel !== [] ? ' including its ' . implode(', ', $rel) . '.' : '.')
            . " Use this to display a {$label} or list its related items — do not use the knowledge base for its contents.";
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
            return ActionResult::failure('This record type is not available.', ['found' => false]);
        }

        $query = $modelClass::query();
        foreach ($this->scope($context, $parameters) as $column => $value) {
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
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
            return ActionResult::failure('Record was not found.', ['found' => false, 'message' => 'No matching record was found.']);
        }

        return ActionResult::success('Record loaded.', array_merge(
            ['found' => true, 'record' => $this->recordColumns($record)],
            ['relations' => $this->relationRows($record)]
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function recordColumns(Model $record): array
    {
        $columns = $this->returns !== [] ? $this->returns : array_keys($record->getAttributes());
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

            $out[$relation] = $rows->map(static function (Model $row) use ($columns): array {
                $attributes = $row->getAttributes();
                if ($columns === []) {
                    return array_merge(['id' => $row->getKey()], $attributes);
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
