<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearningIngestionResult
{
    public function __construct(
        public LearningSourceRecord $source,
        public int $itemsCount,
        public array $items = [],
        public ?string $vectorStoreId = null,
    ) {}

    public function toArray(): array
    {
        return [
            'source' => [
                'source_id' => $this->source->sourceId,
                'source_type' => $this->source->sourceType,
                'source' => $this->source->source,
                'type' => $this->source->type,
                'title' => $this->source->title,
                'adapter' => $this->source->adapter,
                'metadata' => $this->source->metadata,
                'user_id' => $this->source->userId,
                'tenant_id' => $this->source->tenantId,
                'workspace_id' => $this->source->workspaceId,
                'session_id' => $this->source->sessionId,
                'vector_store_id' => $this->source->vectorStoreId,
            ],
            'items_count' => $this->itemsCount,
            'vector_store_id' => $this->vectorStoreId,
        ];
    }
}
