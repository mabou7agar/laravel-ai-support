<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

abstract class ModelBackedLookupTool extends AgentTool
{
    public string $name = '';

    public string $description = '';

    /**
     * @var class-string<Model>|string
     */
    protected string $model = '';

    /**
     * @var array<int, string>
     */
    protected array $search = [];

    /**
     * @var array<int, string>
     */
    protected array $returns = ['id', 'name', 'title', 'email'];

    /**
     * @var array<int, string>
     */
    protected array $missingFields = [];

    /**
     * @return class-string<Model>
     */
    protected function modelClass(): string
    {
        return $this->model;
    }

    /**
     * @return array<int, string>
     */
    protected function searchColumns(): array
    {
        return $this->search;
    }

    public function getName(): string
    {
        return $this->name !== ''
            ? $this->name
            : \Illuminate\Support\Str::snake(\Illuminate\Support\Str::beforeLast(class_basename($this), 'Tool'));
    }

    public function getDescription(): string
    {
        return $this->description !== ''
            ? $this->description
            : 'Find ' . str_replace('_', ' ', $this->getName()) . ' records.';
    }

    /**
     * @return array<int, string>
     */
    protected function returnColumns(): array
    {
        if ($this->explicitReturns) {
            return $this->returns;
        }

        // Also return what the record was found by: a customer matched on company_name or
        // customer_code has no name/title/email, so the defaults alone came back empty.
        return array_values(array_unique(array_merge($this->returns, $this->searchColumns())));
    }

    /** True when the return columns were configured explicitly rather than defaulted. */
    protected bool $explicitReturns = false;

    /**
     * @return array<string, mixed>
     */
    protected function scope(UnifiedActionContext $context, array $parameters): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    protected function missingRequiredFields(): array
    {
        return $this->missingFields;
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Search query.',
            ],
        ];
    }

    public function validate(array $parameters): array
    {
        // execute() searches with query, name, email, title or any search column; planners
        // often send {"name": "..."} instead of {"query": "..."}, which is equally valid.
        $keys = array_values(array_unique(array_merge(['query', 'name', 'email', 'title'], $this->searchColumns())));

        return $this->queryText($parameters, $keys) !== '' ? [] : parent::validate($parameters);
    }

    /**
     * @return array<string, string>
     */
    public function getResultSchema(): array
    {
        $schema = ['found' => 'boolean'];
        foreach ($this->returnColumns() as $column) {
            $schema[$column] = $column === 'id' ? 'mixed' : 'string';
        }

        return $schema;
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $modelClass = $this->modelClass();
        if (!$this->tableExists($modelClass)) {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.records_unavailable', 'The requested records are not available.'), [
                'found' => false,
                'message' => 'The requested records are not available.',
            ]);
        }

        $queryText = $this->queryText($parameters, ['query', 'name', 'email', 'title']);
        if ($queryText === '') {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.search_query_required', 'Search query is required.'), [
                'found' => false,
                'message' => 'Search query is required.',
                'required_fields' => ['query'],
            ]);
        }

        $query = $modelClass::query();
        foreach ($this->scope($context, $parameters) as $column => $value) {
            if ($this->columnExists($modelClass, (string) $column) && $value !== null && $value !== '') {
                $query->where((string) $column, $value);
            }
        }

        $searchable = array_values(array_filter(
            $this->searchColumns(),
            fn (string $column): bool => $this->columnExists($modelClass, $column)
        ));

        if ($searchable === []) {
            return ActionResult::failure($this->localize('ai-engine::runtime.tools.no_searchable_columns', 'No searchable columns are available for this record type.'), [
                'found' => false,
                'message' => 'No searchable columns are available for this record type.',
            ]);
        }

        $query->where(function ($builder) use ($searchable, $queryText): void {
            foreach ($searchable as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $builder->{$method}($column, 'like', "%{$queryText}%");
            }
        });

        $record = $query->first();
        if (!$record instanceof Model) {
            $payload = [
                'found' => false,
                'message' => 'Record was not found.',
                'required_fields' => $this->missingRequiredFields(),
            ];
            if ($payload['required_fields'] === []) {
                unset($payload['required_fields']);
            }

            return ActionResult::failure($this->localize('ai-engine::runtime.tools.record_not_found', 'Record was not found.'), $payload);
        }

        return ActionResult::success(
            'Record found.',
            array_merge(['found' => true], $this->recordPayload($record, $this->returnColumns()))
        );
    }

    /**
     * @param array<int, string> $preferredKeys
     */
    protected function queryText(mixed $arguments, array $preferredKeys): string
    {
        if (is_scalar($arguments)) {
            return trim((string) $arguments);
        }

        if (!is_array($arguments)) {
            return '';
        }

        foreach ($preferredKeys as $key) {
            $value = $arguments[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach ($arguments as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        // Planners often wrap the value: {"filters": {"customer_code": "C-1"}}.
        foreach (['filters', 'where', 'criteria', 'search', 'conditions'] as $wrapper) {
            if (is_array($arguments[$wrapper] ?? null)) {
                $nested = $this->queryText($arguments[$wrapper], $preferredKeys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    /**
     * @param array<int, string> $columns
     * @return array<string, mixed>
     */
    protected function recordPayload(Model $record, array $columns): array
    {
        $payload = [];
        foreach ($columns as $column) {
            if ($column === 'id') {
                $payload['id'] = $record->getKey();
                continue;
            }

            if (array_key_exists($column, $record->getAttributes())) {
                $payload[$column] = $record->getAttribute($column);
            }
        }

        return $payload;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    protected function tableExists(string $modelClass): bool
    {
        if (!class_exists($modelClass)) {
            return false;
        }

        return Schema::hasTable((new $modelClass())->getTable());
    }

    /**
     * @param class-string<Model> $modelClass
     */
    protected function columnExists(string $modelClass, string $column): bool
    {
        return $this->tableExists($modelClass)
            && Schema::hasColumn((new $modelClass())->getTable(), $column);
    }
}
