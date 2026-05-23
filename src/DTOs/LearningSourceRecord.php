<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearningSourceRecord
{
    public function __construct(
        public string $sourceId,
        public string $sourceType,
        public string $source,
        public string $type,
        public ?string $title,
        public ?string $adapter,
        public array $metadata,
        public ?string $content = null,
        public mixed $userId = null,
        public mixed $tenantId = null,
        public mixed $workspaceId = null,
        public ?string $sessionId = null,
        public ?string $vectorStoreId = null,
    ) {}
}
