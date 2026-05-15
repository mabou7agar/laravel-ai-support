<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Scope;

use LaravelAIEngine\Contracts\AIScopeResolver;

class DefaultAIScopeResolver implements AIScopeResolver
{
    public function resolve(mixed $userId = null, array $options = []): array
    {
        $configured = $this->resolveConfigured($userId, $options);
        $user = $this->resolveUser($options);

        return array_filter([
            'tenant_id' => $configured['tenant_id']
                ?? $configured['tenant']
                ?? $this->firstObjectValue($user, (array) config('ai-engine.scope.tenant_user_fields', [
                    'tenant_id',
                    'organization_id',
                    'company_id',
                    'team_id',
                ])),
            'workspace_id' => $configured['workspace_id']
                ?? $configured['workspace']
                ?? $this->firstObjectValue($user, (array) config('ai-engine.scope.workspace_user_fields', [
                    'workspace_id',
                    'current_workspace_id',
                ])),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    protected function resolveConfigured(mixed $userId, array $options): array
    {
        $resolver = config('ai-engine.scope.resolver');
        if (!$resolver) {
            return [];
        }

        if (is_string($resolver) && class_exists($resolver)) {
            $resolver = app($resolver);
        }

        if ($resolver instanceof AIScopeResolver && !$resolver instanceof self) {
            return $resolver->resolve($userId, $options);
        }

        if (is_callable($resolver)) {
            $resolved = $resolver($userId, $options);
            return is_array($resolved) ? $resolved : [];
        }

        return [];
    }

    protected function resolveUser(array $options): mixed
    {
        if (isset($options['user']) && is_object($options['user'])) {
            return $options['user'];
        }

        try {
            return function_exists('auth') ? auth()->user() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function firstObjectValue(mixed $object, array $fields): mixed
    {
        if (!is_object($object)) {
            return null;
        }

        foreach ($fields as $field) {
            $field = trim((string) $field);
            if ($field === '') {
                continue;
            }

            $value = data_get($object, $field);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
