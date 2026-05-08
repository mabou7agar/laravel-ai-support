<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class GenericModuleActionRepository
{
    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $payload
     * @param array<int, string> $lookupFields
     */
    public function findForAction(string $modelClass, array $payload, array $lookupFields, ?object $actor = null): ?Model
    {
        $query = $this->ownedQuery($modelClass, $actor);

        foreach ($lookupFields as $field) {
            if (!array_key_exists($field, $payload) || $payload[$field] === null || $payload[$field] === '') {
                continue;
            }

            return (clone $query)->where($this->columnForLookup($field), $payload[$field])->first();
        }

        return null;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, string> $lookup
     * @param array<string, mixed> $payload
     */
    public function findRelation(string $modelClass, array $lookup, array $payload, ?object $actor = null): ?Model
    {
        $query = $this->ownedQuery($modelClass, $actor);

        foreach ($lookup as $payloadField => $column) {
            if (!array_key_exists($payloadField, $payload) || $payload[$payloadField] === null || $payload[$payloadField] === '') {
                continue;
            }

            return (clone $query)->where($column, $payload[$payloadField])->first();
        }

        return null;
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $attributes
     */
    public function create(string $modelClass, array $attributes): Model
    {
        return $modelClass::query()->create($attributes)->fresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Model $model, array $attributes): Model
    {
        $model->fill($attributes);
        $model->save();

        return $model->fresh();
    }

    public function delete(Model $model): Model
    {
        $snapshot = $model->replicate();
        $snapshot->id = $model->id;
        $model->delete();

        return $snapshot;
    }

    /**
     * @param class-string<Model> $modelClass
     */
    public function ownedQuery(string $modelClass, ?object $actor = null): Builder
    {
        /** @var Model $model */
        $model = new $modelClass();
        $query = $modelClass::query();
        $ownerId = $this->ownerId($actor);

        if (!$ownerId) {
            return $query;
        }

        $table = $model->getTable();
        $hasCreatedBy = Schema::hasColumn($table, 'created_by');
        $hasCreatorId = Schema::hasColumn($table, 'creator_id');

        if ($hasCreatedBy || $hasCreatorId) {
            $query->where(function (Builder $scoped) use ($hasCreatedBy, $hasCreatorId, $ownerId, $actor): void {
                if ($hasCreatedBy) {
                    $scoped->where('created_by', $ownerId);
                }

                if ($hasCreatorId) {
                    $method = $hasCreatedBy ? 'orWhere' : 'where';
                    $scoped->{$method}('creator_id', $this->actorId($actor) ?? $ownerId);
                }
            });
        }

        return $query;
    }

    /**
     * @param class-string<Model>|string $modelClass
     */
    public function tableExists(string $modelClass): bool
    {
        if (!class_exists($modelClass)) {
            return false;
        }

        /** @var Model $model */
        $model = new $modelClass();

        return Schema::hasTable($model->getTable());
    }

    /**
     * @param class-string<Model>|string $modelClass
     */
    public function columnExists(string $modelClass, string $column): bool
    {
        if (!$this->tableExists($modelClass)) {
            return false;
        }

        /** @var Model $model */
        $model = new $modelClass();

        return Schema::hasColumn($model->getTable(), $column);
    }

    private function columnForLookup(string $field): string
    {
        return str_ends_with($field, '_id') ? 'id' : $field;
    }

    private function ownerId(?object $actor): ?int
    {
        if (!$actor) {
            return null;
        }

        $type = (string) ($actor->type ?? '');
        if (in_array($type, ['superadmin', 'company'], true)) {
            return $this->actorId($actor);
        }

        return (int) ($actor->created_by ?? $actor->creator_id ?? $this->actorId($actor) ?? 0) ?: null;
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
}
