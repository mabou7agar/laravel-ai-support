<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class ConversationMemoryQuery
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $message,
        public readonly ?string $userId = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $workspaceId = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $namespace = null,
        public readonly int $limit = 5,
        public readonly array $metadata = [],
        public readonly ?string $scopeType = null,
        public readonly ?string $scopeId = null,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function scope(): array
    {
        return [
            'scope_type' => $this->resolvedScope()['scope_type'],
            'scope_id' => $this->resolvedScope()['scope_id'],
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'session_id' => $this->sessionId,
            'namespace' => $this->namespace,
        ];
    }

    /**
     * @return array{scope_type: string, scope_id: string|null, session_id: string|null}
     */
    public function resolvedScope(): array
    {
        return app(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryScopeResolver::class)->fromArray([
            'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'session_id' => $this->sessionId,
        ]);
    }
}
