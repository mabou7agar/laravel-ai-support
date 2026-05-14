<?php

namespace LaravelAIEngine\Services\Scope;

use LaravelAIEngine\Contracts\AIScopeResolver;

class AIScopeOptionsService
{
    public function __construct(protected AIScopeResolver $resolver)
    {
    }

    public function merge(mixed $userId, array $options): array
    {
        $options = $this->normalizeAliases($options);
        if ((bool) config('ai-engine.scope.auto_inject', true) === false) {
            return $options;
        }

        $scope = $this->normalizeAliases($this->resolver->resolve($userId, $options));

        return array_merge($scope, $options);
    }

    protected function normalizeAliases(array $options): array
    {
        if (!array_key_exists('tenant_id', $options) && array_key_exists('tenant', $options)) {
            $options['tenant_id'] = $options['tenant'];
        }

        if (!array_key_exists('workspace_id', $options) && array_key_exists('workspace', $options)) {
            $options['workspace_id'] = $options['workspace'];
        }

        return $options;
    }
}
