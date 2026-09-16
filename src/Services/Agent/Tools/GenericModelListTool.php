<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Config-driven, scope-aware paginated listing for an Eloquent model.
 */
class GenericModelListTool extends ModelBackedLookupTool
{
    /** @var (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null */
    protected $scopeResolver;

    /**
     * @param class-string $model
     * @param array<int, string> $search
     * @param array<int, string> $returns
     * @param (Closure(UnifiedActionContext, array<string,mixed>): array<string,mixed>)|null $scope
     */
    public function __construct(
        string $name,
        string $model,
        array $search,
        array $returns = [],
        string $description = '',
        ?Closure $scope = null
    ) {
        $this->name = $name;
        $this->model = $model;
        $this->search = array_values($search);
        if ($returns !== []) {
            $this->returns = array_values($returns);
            $this->explicitReturns = true;
        }
        $this->description = $description;
        $this->scopeResolver = $scope;
    }

    public function getDescription(): string
    {
        if ($this->description !== '') {
            return $this->description;
        }

        $label = str_replace('_', ' ', $this->getEntityType());

        return "List {$label} records. Returns a page of rows, each with an `id` and a 1-based "
            . '`position`; use it when the user asks to see, list or browse records rather than to '
            . 'find one specific record. Number the lines of your answer by those positions. If the '
            . 'user then replies with only a number or an ordinal ("1", "the third one"), they mean '
            . 'that position in the list you just showed: call this tool again with `position` set '
            . 'to that number and describe the single row it returns. Never repeat the whole list '
            . 'in answer to such a reply.';
    }

    public function getParameters(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional free-text filter across searchable columns.',
            ],
            'filters' => [
                'type' => 'object',
                'required' => false,
                'description' => 'Optional exact column-value filters. Only existing columns are applied.',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'default' => 10,
                'description' => 'Maximum rows to return for this page (default 10, maximum 50).',
            ],
            'offset' => [
                'type' => 'integer',
                'required' => false,
                'default' => 0,
                'description' => 'Number of matching rows to skip for paging.',
            ],
            'position' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Return only the row at this 1-based position from a list already '
                    . 'shown to the user. Use it when the user answers a numbered list with just a '
                    . 'number or an ordinal ("1", "the third one") so you can fetch that record '
                    . 'instead of repeating the whole list.',
            ],
        ];
    }

    public function getResultSchema(): array
    {
        $properties = [
            'position' => ['type' => 'integer'],
            'id' => ['type' => 'mixed'],
        ];
        foreach ($this->returnColumns() as $column) {
            if ($column !== 'id') {
                $properties[$column] = ['type' => 'mixed'];
            }
        }

        return [
            'found' => 'boolean',
            'count' => 'integer',
            'total' => 'integer',
            'offset' => 'integer',
            'limit' => 'integer',
            'has_more' => 'boolean',
            'rows' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $properties,
                ],
            ],
        ];
    }

    public function getToolKind(): ?string
    {
        return 'read';
    }

    public function getCapabilities(): array
    {
        return ['read', 'list'];
    }

    public function getEntityType(): ?string
    {
        $name = $this->getName();

        return str_starts_with($name, 'list_') ? substr($name, 5) : $name;
    }

    public function getDiscoveryAliases(): array
    {
        $name = str_replace('_', ' ', $this->getEntityType());
        $plural = Str::plural($name);

        // Every alias names the entity. Bare verbs like "list" or "browse" would be
        // advertised identically by every model's list tool, so keyword ranking could
        // surface this one for a question about something else entirely.
        return array_values(array_unique([
            "list {$plural}",
            "list {$name}",
            "show all {$plural}",
            "browse {$plural}",
            "all {$name}",
            "all {$plural}",
        ]));
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

        $configuredMax = (int) config('ai-engine.agent_tools.list_max_limit', 50);
        $maxLimit = max(1, min(50, $configuredMax));
        $limit = max(1, min($maxLimit, (int) ($parameters['limit'] ?? 10)));
        $offset = max(0, (int) ($parameters['offset'] ?? 0));

        // "1" / "the third one" answering a numbered list: fetch that single row
        // rather than repeating the list. Positions are 1-based over the whole
        // result set, so position N is simply offset N-1 with a limit of 1.
        $position = isset($parameters['position']) && is_numeric($parameters['position'])
            ? (int) $parameters['position']
            : null;
        if ($position !== null && $position > 0) {
            $offset = $position - 1;
            $limit = 1;
        }

        $query = $modelClass::query();
        foreach ($this->scope($context, $parameters) as $column => $value) {
            if ($this->columnExists($modelClass, (string) $column) && $value !== null && $value !== '') {
                $query->where((string) $column, $value);
            }
        }

        $queryText = is_scalar($parameters['query'] ?? null)
            ? trim((string) $parameters['query'])
            : '';
        if ($queryText !== '') {
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
        }

        foreach ((array) ($parameters['filters'] ?? []) as $column => $value) {
            if (is_string($column)
                && $this->columnExists($modelClass, $column)
                && (is_scalar($value) || $value === null)) {
                $query->where($column, $value);
            }
        }

        $total = (clone $query)->count();
        $model = new $modelClass();
        $key = $model->getKeyName();

        // The key is always the last sort term. Ordering by created_at alone is not a
        // total order - rows written in the same second tie - and an unstable order
        // makes `position` meaningless: the row at position 1 could differ between the
        // listing and the follow-up that picks it.
        if ($this->columnExists($modelClass, 'created_at')) {
            $query->orderByDesc('created_at');
        }
        $query->orderByDesc($key);

        $rows = $query->offset($offset)->limit($limit)->get()
            ->values()
            ->map(function (Model $record, int $index) use ($offset): array {
                return array_merge(
                    ['position' => $offset + $index + 1, 'id' => $record->getKey()],
                    $this->recordPayload($record, $this->returnColumns())
                );
            })
            ->all();

        $count = count($rows);
        $found = $count > 0;
        $entity = Str::plural(str_replace('_', ' ', $this->getEntityType()), $total);
        $singular = str_replace('_', ' ', $this->getEntityType());
        if ($position !== null && $position > 0) {
            $message = $found
                ? sprintf('The %s at position %d of %d.', $singular, $position, $total)
                : sprintf('There is no %s at position %d; there are %d in total.', $singular, $position, $total);
        } else {
            $message = $found
                ? sprintf('Found %d %s (showing %d-%d).', $total, $entity, $offset + 1, $offset + $count)
                : sprintf('No %s found.', Str::plural($singular));
        }

        return ActionResult::success($message, [
            'found' => $found,
            'count' => $count,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $offset + $count < $total,
            'rows' => $rows,
        ], ['tool' => $this->getName()]);
    }

    protected function scope(UnifiedActionContext $context, array $parameters): array
    {
        return $this->scopeResolver !== null
            ? (array) ($this->scopeResolver)($context, $parameters)
            : [];
    }
}
