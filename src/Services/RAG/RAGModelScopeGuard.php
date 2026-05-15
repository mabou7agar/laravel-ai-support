<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Schema;

class RAGModelScopeGuard
{
    public function apply($query, string $modelClass, mixed $userId, array $filterConfig = [], array $options = []): array
    {
        if ($this->isPublicAccessAllowed($filterConfig)) {
            return ['allowed' => true, 'query' => $query, 'scope' => 'public'];
        }

        $instance = new $modelClass();
        $table = $instance->getTable();
        $appliedScopes = [];
        $requiresScope = (bool) config('ai-engine.rag.require_structured_scope', true);

        $tenantScope = $this->applyDimensionScope(
            query: $query,
            modelClass: $modelClass,
            table: $table,
            value: $options['tenant_id'] ?? $options['tenant'] ?? null,
            method: 'scopeForTenant',
            fieldKey: 'tenant_field',
            fieldsKey: 'tenant_fields',
            configuredFields: (array) config('ai-engine.rag.tenant_scope_fields', config('vector-access-control.tenant_fields', ['tenant_id'])),
            enabled: (bool) config('vector-access-control.enable_tenant_scope', true),
            label: 'tenant',
            filterConfig: $filterConfig
        );
        if (($tenantScope['allowed'] ?? true) === false) {
            return $this->blocked($modelClass, $tenantScope['error']);
        }
        $query = $tenantScope['query'];
        $appliedScopes = array_merge($appliedScopes, $tenantScope['scopes']);

        $workspaceScope = $this->applyDimensionScope(
            query: $query,
            modelClass: $modelClass,
            table: $table,
            value: $options['workspace_id'] ?? $options['workspace'] ?? null,
            method: 'scopeForWorkspace',
            fieldKey: 'workspace_field',
            fieldsKey: 'workspace_fields',
            configuredFields: (array) config('ai-engine.rag.workspace_scope_fields', config('vector-access-control.workspace_fields', ['workspace_id'])),
            enabled: (bool) config('vector-access-control.enable_workspace_scope', true),
            label: 'workspace',
            filterConfig: $filterConfig
        );
        if (($workspaceScope['allowed'] ?? true) === false) {
            return $this->blocked($modelClass, $workspaceScope['error']);
        }
        $query = $workspaceScope['query'];
        $appliedScopes = array_merge($appliedScopes, $workspaceScope['scopes']);

        if ($userId !== null && $userId !== '') {
            if (method_exists($modelClass, 'scopeForUser')) {
                $query = $query->forUser($userId);
                $appliedScopes[] = 'user:scopeForUser';
            } else {
                $scopeField = $this->resolveScopeField(
                    $table,
                    $filterConfig,
                    'user_field',
                    'user_fields',
                    (array) config('ai-engine.rag.user_scope_fields', ['user_id', 'created_by', 'owner_id'])
                );

                if ($scopeField !== null) {
                    $query = $query->where($scopeField, $userId);
                    $appliedScopes[] = "user:{$scopeField}";
                }
            }
        }

        if ($appliedScopes !== []) {
            return ['allowed' => true, 'query' => $query, 'scope' => implode(',', $appliedScopes)];
        }

        if (!$requiresScope) {
            return ['allowed' => true, 'query' => $query, 'scope' => 'disabled'];
        }

        if ($userId === null || $userId === '') {
            return $this->blocked($modelClass, 'No authenticated user id was provided for scoped structured RAG access.');
        }

        return $this->blocked(
            $modelClass,
            'Structured RAG access is blocked because this model has no usable scope. Add scopeForUser(), scopeForTenant(), scopeForWorkspace(), configure filter_config scope fields, or mark the model public with filter_config.public_access=true.'
        );
    }

    protected function applyDimensionScope(
        $query,
        string $modelClass,
        string $table,
        mixed $value,
        string $method,
        string $fieldKey,
        string $fieldsKey,
        array $configuredFields,
        bool $enabled,
        string $label,
        array $filterConfig
    ): array {
        if (!$enabled || $value === null || $value === '') {
            return ['allowed' => true, 'query' => $query, 'scopes' => []];
        }

        if (method_exists($modelClass, $method)) {
            $scopeMethod = lcfirst(substr($method, 5));

            return [
                'allowed' => true,
                'query' => $query->{$scopeMethod}($value),
                'scopes' => ["{$label}:{$scopeMethod}"],
            ];
        }

        $scopeField = $this->resolveScopeField($table, $filterConfig, $fieldKey, $fieldsKey, $configuredFields);
        if ($scopeField !== null) {
            return [
                'allowed' => true,
                'query' => $query->where($scopeField, $value),
                'scopes' => ["{$label}:{$scopeField}"],
            ];
        }

        return [
            'allowed' => false,
            'query' => null,
            'scopes' => [],
            'error' => "Structured RAG {$label} scope was provided but this model has no {$label} scope field or {$method}().",
        ];
    }

    protected function resolveScopeField(
        string $table,
        array $filterConfig,
        string $fieldKey,
        string $fieldsKey,
        array $configuredFields
    ): ?string
    {
        $candidates = [];
        if (!empty($filterConfig[$fieldKey]) && is_string($filterConfig[$fieldKey])) {
            $candidates[] = $filterConfig[$fieldKey];
        }

        if (!empty($filterConfig[$fieldsKey]) && is_array($filterConfig[$fieldsKey])) {
            $candidates = array_merge($candidates, $filterConfig[$fieldsKey]);
        }

        $candidates = array_merge(
            $candidates,
            $configuredFields
        );

        foreach (array_unique(array_filter(array_map(
            static fn ($field): string => trim((string) $field),
            $candidates
        ))) as $field) {
            if (Schema::hasColumn($table, $field)) {
                return $field;
            }
        }

        return null;
    }

    protected function isPublicAccessAllowed(array $filterConfig): bool
    {
        return ($filterConfig['public_access'] ?? false) === true
            || ($filterConfig['public'] ?? false) === true
            || ($filterConfig['scope_required'] ?? null) === false;
    }

    protected function blocked(string $modelClass, string $reason): array
    {
        return [
            'allowed' => false,
            'query' => null,
            'scope' => null,
            'error' => $reason,
            'model_class' => $modelClass,
        ];
    }
}
