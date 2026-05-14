<?php

namespace LaravelAIEngine\Contracts;

interface AIScopeResolver
{
    /**
     * Resolve trusted execution scope for AI/RAG/agent requests.
     *
     * @return array{tenant_id?:mixed, workspace_id?:mixed}
     */
    public function resolve(mixed $userId = null, array $options = []): array;
}
