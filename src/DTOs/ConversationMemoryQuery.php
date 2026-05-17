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
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function scope(): array
    {
        return [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'session_id' => $this->sessionId,
            'namespace' => $this->namespace,
        ];
    }
}
