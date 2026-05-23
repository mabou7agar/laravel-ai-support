<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearningSourceRequest
{
    public function __construct(
        public string $sourceType,
        public string $source,
        public string $type = 'general',
        public ?string $title = null,
        public ?string $adapter = null,
        public array $metadata = [],
        public mixed $userId = null,
        public mixed $tenantId = null,
        public mixed $workspaceId = null,
        public ?string $sessionId = null,
        public bool $shouldIndex = false,
        public ?string $vectorStoreId = null,
        public string $vectorStoreName = 'Learned Knowledge',
    ) {}

    public function scope(): array
    {
        return [
            'user_id' => $this->userId === null ? null : (string) $this->userId,
            'tenant_id' => $this->tenantId === null ? null : (string) $this->tenantId,
            'workspace_id' => $this->workspaceId === null ? null : (string) $this->workspaceId,
            'session_id' => $this->sessionId,
        ];
    }
}
