<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use Illuminate\Database\Eloquent\Model;

/**
 * Deterministically resolve a foreign-key field on a tool payload by find-or-create
 * against an Eloquent model — in PHP, not via the model-driven tool loop.
 *
 * A final/write tool (e.g. create_invoice) declares its relations and calls resolve()
 * before persisting, so a related record (customer, project, ...) is always looked up by
 * its identity and created when missing — without relying on the planner to remember to
 * call a separate find/create tool. The user-provided identity is the source of truth:
 * if the payload carries a foreign-key id whose identity column no longer matches the
 * provided value (e.g. a loose name match), the id is dropped and re-resolved.
 *
 * Relation shape:
 *   [
 *     'field'    => 'customer_id',          // FK column to populate on the payload
 *     'model'    => Customer::class,
 *     'identity' => ['email'],              // find-or-create key column(s) -> param keys via 'map'
 *     'map'      => ['email' => 'customer_email', 'name' => 'customer_name'], // column => payload key
 *     'create'   => ['name', 'email'],      // columns required to create (must all be present)
 *     // 'defaults' => ['source' => 'ai'],  // create-only columns (NOT used to match)
 *     // 'normalize' => ['email'],          // columns lower-cased before match/create (default: identity)
 *   ]
 *
 * Pass tenant/owner filters as the $scope argument — they constrain BOTH the lookup and
 * the create (so you find within, and create inside, the right tenant). Create-only values
 * (a generated password, a source flag) go in the relation's 'defaults'.
 */
class RelationResolver
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $scope    extra where/create columns (tenant_id, user_id, ...)
     * @return array<string, mixed> payload with the FK field resolved when possible
     */
    public function resolve(array $payload, array $relation, array $scope = []): array
    {
        $field = (string) ($relation['field'] ?? '');
        $modelClass = (string) ($relation['model'] ?? '');
        if ($field === '' || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return $payload;
        }

        $map = (array) ($relation['map'] ?? []);
        $identityCols = array_values((array) ($relation['identity'] ?? array_keys($map)));
        $normalize = array_values((array) ($relation['normalize'] ?? $identityCols));

        // Collect the identity (the source of truth) from the payload.
        $identity = [];
        foreach ($identityCols as $column) {
            $payloadKey = $map[$column] ?? $column;
            $value = $payload[$payloadKey] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                continue;
            }
            $identity[$column] = in_array($column, $normalize, true) ? mb_strtolower((string) $value) : $value;
        }

        // Source of truth: drop a pre-set FK id whose identity no longer matches.
        if (!empty($payload[$field]) && $identity !== []) {
            $existing = $modelClass::query()->find($payload[$field]);
            if ($existing instanceof Model) {
                foreach ($identity as $column => $value) {
                    $stored = $existing->getAttribute($column);
                    $stored = in_array($column, $normalize, true) ? mb_strtolower((string) $stored) : (string) $stored;
                    if ($stored !== (string) $value) {
                        unset($payload[$field]);
                        break;
                    }
                }
            }
        }

        if (!empty($payload[$field]) || $identity === []) {
            return $payload;
        }

        // Find by identity (scoped).
        $query = $modelClass::query();
        $this->applyScope($query, $scope);
        foreach ($identity as $column => $value) {
            $query->where($column, $value);
        }
        $record = $query->first();

        // Create when missing and all required create columns are present.
        if (!$record instanceof Model) {
            $record = $this->create($modelClass, $relation, $payload, $identity, $scope);
        }

        if ($record instanceof Model) {
            $payload[$field] = $record->getKey();
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $relation
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $scope
     */
    private function create(string $modelClass, array $relation, array $payload, array $identity, array $scope): ?Model
    {
        $createColumns = array_values((array) ($relation['create'] ?? []));
        if ($createColumns === []) {
            return null;
        }

        $map = (array) ($relation['map'] ?? []);
        $normalize = array_values((array) ($relation['normalize'] ?? array_keys($identity)));
        $attributes = [];
        foreach ($createColumns as $column) {
            $payloadKey = $map[$column] ?? $column;
            $value = $payload[$payloadKey] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === null || $value === '') {
                return null; // missing a required create column — let the tool re-prompt
            }
            $attributes[$column] = in_array($column, $normalize, true) ? mb_strtolower((string) $value) : $value;
        }

        // Create-only defaults (e.g. a generated password) are applied to the new row but,
        // unlike scope, are NOT used to match an existing one.
        $defaults = (array) ($relation['defaults'] ?? []);

        return $modelClass::query()->firstOrCreate(
            array_merge($scope, $identity),
            array_merge($scope, $defaults, $attributes)
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    private function applyScope(object $query, array $scope): void
    {
        foreach ($scope as $column => $value) {
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
    }
}
