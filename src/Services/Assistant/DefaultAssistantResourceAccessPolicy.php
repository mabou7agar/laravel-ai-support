<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Assistant;

use LaravelAIEngine\Contracts\AssistantResourceAccessPolicy;
use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResourceQuery;

final class DefaultAssistantResourceAccessPolicy implements AssistantResourceAccessPolicy
{
    public function allows(AssistantResourceItem $item, AssistantResourceQuery $query): bool
    {
        $visibility = strtolower(trim($item->visibility));
        if (in_array($visibility, ['public', 'global', 'shared'], true)) {
            return true;
        }

        $scope = $query->scope;
        if (!$this->matches($item->tenantId, $scope['tenant_id'] ?? null)
            || !$this->matches($item->workspaceId, $scope['workspace_id'] ?? null)
            || !$this->matches($item->userId, $scope['user_id'] ?? null)) {
            return false;
        }

        return $item->tenantId !== null
            || $item->workspaceId !== null
            || $item->userId !== null;
    }

    private function matches(?string $expected, mixed $actual): bool
    {
        return $expected === null || hash_equals($expected, trim((string) ($actual ?? '')));
    }
}
