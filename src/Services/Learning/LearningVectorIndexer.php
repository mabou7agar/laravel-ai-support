<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning;

use LaravelAIEngine\DTOs\LearningSourceRecord;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\VectorStoreService;

class LearningVectorIndexer
{
    public function __construct(
        protected VectorStoreService $vectorStores,
    ) {}

    public function index(LearningSourceRecord $source, ?string $storeId = null, string $storeName = 'Learned Knowledge'): string
    {
        $store = $storeId !== null
            ? $this->vectorStores->get($storeId)
            : null;

        if ($store === null) {
            $store = $this->vectorStores->create($storeName, [
                'source' => 'learning',
                'learn_type' => $source->type,
                'tenant_id' => $source->tenantId,
                'workspace_id' => $source->workspaceId,
            ]);
            $storeId = (string) $store['id'];
        }

        $document = new Document(
            source: $source->source,
            disk: null,
            metadata: array_filter([
                'learn_source_id' => $source->sourceId,
                'learn_type' => $source->type,
                'title' => $source->title,
                'user_id' => $source->userId,
                'tenant_id' => $source->tenantId,
                'workspace_id' => $source->workspaceId,
                'session_id' => $source->sessionId,
            ], static fn ($value): bool => $value !== null)
        );

        $this->vectorStores->add($storeId, $document);

        return $storeId;
    }
}
