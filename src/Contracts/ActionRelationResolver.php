<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\UnifiedActionContext;

interface ActionRelationResolver
{
    /**
     * Resolve existing related records from natural keys before validation.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array{payload?: array<string, mixed>, resolved_relations?: array<int, array<string, mixed>>, pending_relations?: array<int, array<string, mixed>>}
     */
    public function resolveExisting(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array;

    /**
     * Create confirmed missing relations immediately before the primary action executes.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $action
     * @return array{payload?: array<string, mixed>, created_relations?: array<int, array<string, mixed>>}
     */
    public function createMissing(string $actionId, array $payload, ?UnifiedActionContext $context, array $action): array;
}

