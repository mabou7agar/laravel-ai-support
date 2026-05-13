<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\Files\Document;

class VectorStoreService
{
    protected array $stores = [];

    public function create(string $name, array $metadata = []): array
    {
        $id = 'store_' . hash('sha256', $name . serialize($metadata) . microtime(true));

        return $this->stores[$id] = [
            'id' => $id,
            'name' => $name,
            'metadata' => $metadata,
            'documents' => [],
        ];
    }

    public function add(string $storeId, Document|string $document, array $metadata = []): array
    {
        if (!isset($this->stores[$storeId])) {
            throw new \InvalidArgumentException("Vector store [{$storeId}] does not exist.");
        }

        $payload = $document instanceof Document
            ? $document->toArray()
            : Document::fromPath($document, $metadata)->toArray();

        $this->stores[$storeId]['documents'][] = $payload;

        return $this->stores[$storeId];
    }

    public function get(string $storeId): ?array
    {
        return $this->stores[$storeId] ?? null;
    }
}
