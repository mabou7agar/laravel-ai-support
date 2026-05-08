<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaravelAIEngine\DTOs\GenericModuleActionDTO;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class GenericModuleActionService
{
    public function __construct(private readonly GenericModuleActionRepository $records)
    {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(?object $actor = null): array
    {
        return collect((array) config('ai-agent.generic_module_actions', []))
            ->filter(fn (array $resource): bool => $this->resourceAvailable($resource))
            ->flatMap(fn (array $resource, string $key): array => $this->resourceDefinitions($key, $resource))
            ->all();
    }

    public function supports(string $actionId): bool
    {
        return array_key_exists($actionId, $this->definitions());
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function prepareById(string $actionId, array $payload, ?object $actor = null): array
    {
        $definition = $this->definition($actionId);
        $dto = GenericModuleActionDTO::fromDefinition($actionId, $definition, $payload);
        $payload = $this->resolveRelations($dto, $payload, $actor);
        $validated = $this->validate($dto, $payload);

        if ($dto->operation === 'update' && !$this->records->findForAction($dto->modelClass(), $validated, $dto->lookupFields(), $actor)) {
            throw ValidationException::withMessages(['record' => __('I could not find the requested :resource.', [
                'resource' => $dto->label(),
            ])]);
        }

        return [
            'payload' => $validated,
            'summary' => $this->summary($dto, $validated),
            'pending_relations' => $this->pendingRelations($dto, $validated),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function executeById(string $actionId, array $payload, object $actor): Model
    {
        $definition = $this->definition($actionId);
        $dto = GenericModuleActionDTO::fromDefinition($actionId, $definition, $payload);
        $validated = $this->validate($dto, $this->resolveRelations($dto, $payload, $actor));

        return DB::transaction(function () use ($dto, $validated, $actor): Model {
            if ($dto->operation === 'update') {
                $record = $this->records->findForAction($dto->modelClass(), $validated, $dto->lookupFields(), $actor);
                if (!$record) {
                    throw ValidationException::withMessages(['record' => __('I could not find the requested :resource.', [
                        'resource' => $dto->label(),
                    ])]);
                }

                return $this->records->update($record, $this->attributesForWrite($dto, $validated, $actor, false));
            }

            $record = $this->records->create($dto->modelClass(), $this->attributesForWrite($dto, $validated, $actor, true));
            $this->createLineItems($dto, $record, $validated, $actor);

            return $record->fresh();
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resourceDefinition(string $actionId): ?array
    {
        return $this->definitions()[$actionId] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $actionId): array
    {
        $definition = $this->resourceDefinition($actionId);
        if (!$definition) {
            throw ValidationException::withMessages(['action_id' => __('Business action is not available.')]);
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, array<string, mixed>>
     */
    private function resourceDefinitions(string $key, array $resource): array
    {
        $definitions = [];
        foreach ((array) ($resource['actions'] ?? ['create', 'update']) as $operation) {
            $actionId = "{$operation}_{$key}";
            $required = (array) ($resource["{$operation}_required"] ?? []);

            $definitions[$actionId] = [
                'id' => $actionId,
                'resource_key' => $key,
                'resource' => $resource,
                'module' => $resource['module'] ?? 'business',
                'label' => $this->labelFor($operation, $resource),
                'description' => $this->descriptionFor($operation, $resource),
                'operation' => $operation,
                'permission' => $resource['permissions'][$operation] ?? null,
                'confirmation_required' => true,
                'prepare' => fn (array $payload, ?UnifiedActionContext $context = null, array $action = []): array => app(self::class)
                    ->prepareById((string) ($action['id'] ?? $actionId), $payload, $this->actor($context)),
                'handler' => function (array $payload, ?UnifiedActionContext $context = null, array $action = []): Model {
                    $actor = $this->actor($context);
                    if (!$actor) {
                        throw ValidationException::withMessages(['user' => __('Authenticated user is required.')]);
                    }

                    return app(self::class)->executeById((string) ($action['id'] ?? $actionId), $payload, $actor);
                },
                'required' => $required,
                'parameters' => $this->parametersFor($resource, $operation, $required),
                'summary_fields' => $resource['summary_fields'] ?? [],
                'generic_module_action' => true,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $resource
     * @param array<int, string> $required
     * @return array<string, array<string, mixed>>
     */
    private function parametersFor(array $resource, string $operation, array $required): array
    {
        $parameters = [];
        foreach ((array) ($resource['fields'] ?? []) as $field => $type) {
            $parameters[$field] = [
                'type' => $this->catalogType($type),
                'required' => in_array($field, $required, true),
            ];
        }

        if ($operation === 'update') {
            foreach ((array) ($resource['lookup'] ?? ['id']) as $field) {
                $parameters[$field] ??= ['type' => 'string', 'required' => false];
            }
        }

        if ($lineItems = $resource['line_items'] ?? null) {
            $itemsKey = $lineItems['key'] ?? 'items';
            $parameters[$itemsKey] = ['type' => 'array', 'required' => in_array($itemsKey, $required, true)];
            foreach ((array) ($lineItems['fields'] ?? []) as $field => $type) {
                $parameters["{$itemsKey}.*.{$field}"] = [
                    'type' => $this->catalogType($type),
                    'required' => in_array($field, (array) ($lineItems['required'] ?? []), true),
                ];
            }
        }

        foreach ((array) ($resource['relations'] ?? []) as $relation) {
            foreach (array_keys((array) ($relation['lookup'] ?? [])) as $field) {
                $parameters[$field] ??= ['type' => 'string', 'required' => false];
            }
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(GenericModuleActionDTO $dto, array $payload): array
    {
        $rules = [];
        $resource = $dto->resource;
        $required = (array) ($resource["{$dto->operation}_required"] ?? []);

        foreach ((array) ($resource['fields'] ?? []) as $field => $type) {
            if ($dto->operation === 'update' && !array_key_exists($field, $payload)) {
                continue;
            }

            $rules[$field] = $this->ruleFor($type, in_array($field, $required, true));
        }

        foreach ((array) ($resource['lookup'] ?? ['id']) as $field) {
            if ($dto->operation === 'update') {
                $rules[$field] = $this->ruleFor(str_ends_with($field, '_id') || $field === 'id' ? 'integer' : 'string', false);
            }
        }

        if ($lineItems = $resource['line_items'] ?? null) {
            $itemsKey = $lineItems['key'] ?? 'items';
            $rules[$itemsKey] = in_array($itemsKey, $required, true) ? 'required|array|min:1' : 'nullable|array';
            foreach ((array) ($lineItems['fields'] ?? []) as $field => $type) {
                $rules["{$itemsKey}.*.{$field}"] = $this->ruleFor($type, in_array($field, (array) ($lineItems['required'] ?? []), true));
            }
        }

        $validator = Validator::make($payload, $rules);
        $validator->after(function ($validator) use ($dto, $payload): void {
            if ($dto->operation !== 'update') {
                return;
            }

            $hasLookup = collect($dto->lookupFields())->contains(fn (string $field): bool => filled($payload[$field] ?? null));
            if (!$hasLookup) {
                $validator->errors()->add('record', __('Tell me which :resource to update.', ['resource' => $dto->label()]));
            }

            $writeFields = array_diff($dto->allowedFields(), $dto->lookupFields());
            $hasChange = collect($writeFields)->contains(fn (string $field): bool => array_key_exists($field, $payload));
            if (!$hasChange) {
                $validator->errors()->add('changes', __('Tell me what to update for :resource.', ['resource' => $dto->label()]));
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveRelations(GenericModuleActionDTO $dto, array $payload, ?object $actor): array
    {
        foreach ((array) ($dto->resource['relations'] ?? []) as $relation) {
            $field = (string) ($relation['field'] ?? '');
            $class = (string) ($relation['class'] ?? '');
            if (!$field || !$class || !empty($payload[$field]) || !$this->records->tableExists($class)) {
                continue;
            }

            $record = $this->records->findRelation($class, (array) ($relation['lookup'] ?? []), $payload, $actor);
            if ($record) {
                $payload[$field] = $record->getKey();
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function attributesForWrite(GenericModuleActionDTO $dto, array $payload, object $actor, bool $creating): array
    {
        $attributes = array_merge($this->defaultAttributes($dto, $payload), Arr::only($payload, $dto->allowedFields()));
        $attributes = $this->filterExistingColumns($dto->modelClass(), $attributes);

        if ($creating) {
            $attributes = $this->withOwnership($dto->modelClass(), $attributes, $actor);
        }

        if (($dto->resource['line_items'] ?? null) && $dto->operation === 'create') {
            $attributes = array_merge($attributes, $this->lineItemTotals($dto, $payload));
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function defaultAttributes(GenericModuleActionDTO $dto, array $payload): array
    {
        $defaults = [];
        foreach ((array) ($dto->resource['defaults'] ?? []) as $field => $value) {
            if (!array_key_exists($field, $payload)) {
                $defaults[$field] = $this->defaultValue($value, $field);
            }
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function withOwnership(string $modelClass, array $attributes, object $actor): array
    {
        $ownerId = $this->ownerId($actor) ?? $this->actorId($actor);

        if ($this->records->columnExists($modelClass, 'creator_id') && empty($attributes['creator_id'])) {
            $attributes['creator_id'] = $this->actorId($actor);
        }

        if ($this->records->columnExists($modelClass, 'created_by') && empty($attributes['created_by'])) {
            $attributes['created_by'] = $ownerId;
        }

        if ($this->records->columnExists($modelClass, 'workspace_id') && empty($attributes['workspace_id']) && function_exists('workspaceId') && workspaceId()) {
            $attributes['workspace_id'] = workspaceId();
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createLineItems(GenericModuleActionDTO $dto, Model $record, array $payload, object $actor): void
    {
        $lineItems = $dto->resource['line_items'] ?? null;
        $itemModel = (string) ($lineItems['model'] ?? '');
        if (!$lineItems || !$itemModel || !$this->records->tableExists($itemModel)) {
            return;
        }

        $itemsKey = $lineItems['key'] ?? 'items';
        foreach ((array) ($payload[$itemsKey] ?? []) as $itemData) {
            $attributes = array_merge(
                Arr::only((array) $itemData, array_keys((array) ($lineItems['fields'] ?? []))),
                [$lineItems['foreign_key'] ?? 'invoice_id' => $record->getKey()]
            );
            $attributes = $this->filterExistingColumns($itemModel, $attributes);
            $attributes = $this->withOwnership($itemModel, $attributes, $actor);
            $item = $this->records->create($itemModel, $attributes);

            $taxModel = (string) ($lineItems['tax_model'] ?? '');
            if (!$taxModel || !$this->records->tableExists($taxModel)) {
                continue;
            }

            foreach ((array) ($itemData['taxes'] ?? []) as $tax) {
                $this->records->create($taxModel, [
                    $lineItems['tax_foreign_key'] ?? 'item_id' => $item->getKey(),
                    $lineItems['tax_name_field'] ?? 'tax_name' => $tax['tax_name'] ?? $tax['name'] ?? 'Tax',
                    $lineItems['tax_rate_field'] ?? 'tax_rate' => $tax['tax_rate'] ?? $tax['rate'] ?? 0,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, float>
     */
    private function lineItemTotals(GenericModuleActionDTO $dto, array $payload): array
    {
        $lineItems = $dto->resource['line_items'] ?? null;
        if (!$lineItems) {
            return [];
        }

        $subtotal = 0.0;
        $discount = 0.0;
        $tax = 0.0;

        foreach ((array) ($payload[$lineItems['key'] ?? 'items'] ?? []) as $item) {
            $lineTotal = (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
            $discountAmount = ($lineTotal * (float) ($item['discount_percentage'] ?? 0)) / 100;
            $taxAmount = (($lineTotal - $discountAmount) * (float) ($item['tax_percentage'] ?? 0)) / 100;
            $subtotal += $lineTotal;
            $discount += $discountAmount;
            $tax += $taxAmount;
        }

        $total = round($subtotal + $tax - $discount, 2);

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'tax_amount' => round($tax, 2),
            'total_amount' => $total,
            'balance_amount' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function filterExistingColumns(string $modelClass, array $attributes): array
    {
        return array_filter(
            $attributes,
            fn (string $column): bool => $this->records->columnExists($modelClass, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function summary(GenericModuleActionDTO $dto, array $payload): array
    {
        $summary = Arr::only($payload, array_slice(array_merge($dto->lookupFields(), $dto->allowedFields()), 0, 8));

        if ($dto->resource['line_items'] ?? null) {
            $summary['items_count'] = count((array) ($payload[$dto->resource['line_items']['key'] ?? 'items'] ?? []));
            $summary['totals'] = $this->lineItemTotals($dto, $payload);
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function pendingRelations(GenericModuleActionDTO $dto, array $payload): array
    {
        return collect((array) ($dto->resource['relations'] ?? []))
            ->filter(fn (array $relation): bool => empty($payload[$relation['field'] ?? '']))
            ->map(fn (array $relation): array => [
                'field' => $relation['field'] ?? null,
                'label' => $relation['label'] ?? $relation['field'] ?? null,
                'needs_user_input' => true,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function resourceAvailable(array $resource): bool
    {
        $class = (string) ($resource['class'] ?? '');

        return $class !== '' && $this->records->tableExists($class);
    }

    private function ruleFor(string|array $type, bool $required): string
    {
        $base = $required ? 'required' : 'nullable';
        $type = is_array($type) ? ($type['type'] ?? 'string') : $type;

        return match ($type) {
            'integer' => "{$base}|integer",
            'number', 'decimal', 'float' => "{$base}|numeric",
            'date' => "{$base}|date",
            'datetime' => "{$base}|date",
            'time' => "{$base}|date_format:H:i",
            'boolean' => "{$base}|boolean",
            'array', 'json' => "{$base}|array",
            'email' => "{$base}|email|max:255",
            default => "{$base}|string|max:65535",
        };
    }

    private function catalogType(string|array $type): string
    {
        $type = is_array($type) ? ($type['type'] ?? 'string') : $type;

        return match ($type) {
            'integer' => 'integer',
            'number', 'decimal', 'float' => 'number',
            'date' => 'date',
            'datetime' => 'datetime',
            'time' => 'string',
            'boolean' => 'boolean',
            'array', 'json' => 'array',
            default => 'string',
        };
    }

    private function defaultValue(mixed $value, string $field): mixed
    {
        if ($value === '@today') {
            return now()->toDateString();
        }

        if (is_string($value) && str_starts_with($value, '@code:')) {
            $prefix = Str::upper(Str::after($value, '@code:') ?: Str::slug($field));

            return $prefix . '-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(4));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function labelFor(string $operation, array $resource): string
    {
        $verb = $operation === 'update' ? 'Update' : 'Create';

        return $verb . ' ' . (string) ($resource['label'] ?? 'record');
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function descriptionFor(string $operation, array $resource): string
    {
        $label = (string) ($resource['label'] ?? 'record');

        return $operation === 'update'
            ? "Update an existing {$label} after user confirmation."
            : "Create a {$label} after user confirmation.";
    }

    private function ownerId(?object $actor): ?int
    {
        if (!$actor) {
            return null;
        }

        $resolver = config('ai-agent.generic_module_actions_ownership.owner_id_resolver');
        if (is_callable($resolver)) {
            $resolved = $resolver($actor);

            return $resolved ? (int) $resolved : null;
        }

        foreach ((array) config('ai-agent.generic_module_actions_ownership.owner_fields', ['created_by', 'creator_id', 'owner_id', 'user_id']) as $field) {
            $value = $actor->{$field} ?? null;
            if ($value) {
                return (int) $value;
            }
        }

        return $this->actorId($actor);
    }

    private function actorId(?object $actor): ?int
    {
        if (!$actor) {
            return null;
        }

        if (method_exists($actor, 'getAuthIdentifier')) {
            return (int) $actor->getAuthIdentifier();
        }

        return isset($actor->id) ? (int) $actor->id : null;
    }

    private function actor(?UnifiedActionContext $context): ?object
    {
        if (!$context?->userId) {
            return auth()->user();
        }

        $userModel = config('auth.providers.users.model');
        if (is_string($userModel) && class_exists($userModel)) {
            return $userModel::query()->find((int) $context->userId);
        }

        return auth()->user();
    }
}
