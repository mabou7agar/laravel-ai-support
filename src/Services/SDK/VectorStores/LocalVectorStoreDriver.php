<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK\VectorStores;

use LaravelAIEngine\Contracts\VectorStoreDriverInterface;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Repositories\VectorStoreRepository;

class LocalVectorStoreDriver implements VectorStoreDriverInterface
{
    protected array $stores = [];

    public function __construct(protected ?VectorStoreRepository $repository = null)
    {
        $this->repository = $repository ?? (function_exists('app') ? app(VectorStoreRepository::class) : null);
    }

    public function create(string $name, array $metadata = []): array
    {
        $id = 'store_' . hash('sha256', $name . serialize($metadata) . microtime(true));

        if ($this->repository?->available()) {
            return $this->serializeStore(
                $this->repository->create($id, $name, $metadata)
            );
        }

        return $this->stores[$id] = [
            'id' => $id,
            'provider' => 'local',
            'name' => $name,
            'metadata' => $metadata,
            'documents' => [],
        ];
    }

    public function add(string $storeId, Document|string $document, array $metadata = []): array
    {
        if (!isset($this->stores[$storeId])) {
            if (!$this->repository?->available() || $this->repository->findByStoreId($storeId) === null) {
                throw new \InvalidArgumentException("Vector store [{$storeId}] does not exist.");
            }
        }

        $document = $document instanceof Document
            ? $document
            : Document::fromPath($document, $metadata);

        if ($this->repository?->available()) {
            return $this->serializeStore(
                $this->repository->addDocument($storeId, $document)
            );
        }

        $this->stores[$storeId]['documents'][] = $document->toArray();

        return $this->stores[$storeId];
    }

    public function get(string $storeId): ?array
    {
        if ($this->repository?->available()) {
            $store = $this->repository->findByStoreId($storeId);

            return $store ? $this->serializeStore($store) : null;
        }

        return $this->stores[$storeId] ?? null;
    }

    public function delete(string $storeId): bool
    {
        if ($this->repository?->available()) {
            return $this->repository->delete($storeId);
        }

        if (!isset($this->stores[$storeId])) {
            return false;
        }

        unset($this->stores[$storeId]);

        return true;
    }

    protected function serializeStore(object $store): array
    {
        return [
            'id' => (string) $store->store_id,
            'provider' => 'local',
            'name' => (string) $store->name,
            'metadata' => (array) ($store->metadata ?? []),
            'documents' => $store->relationLoaded('documents')
                ? $store->documents->map(fn ($document): array => [
                    'id' => (string) $document->document_id,
                    'source' => (string) $document->source,
                    'disk' => $document->disk,
                    'metadata' => (array) ($document->metadata ?? []),
                ])->values()->all()
                : [],
        ];
    }
}
