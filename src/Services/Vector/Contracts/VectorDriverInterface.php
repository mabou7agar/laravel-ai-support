<?php

namespace LaravelAIEngine\Services\Vector\Contracts;

interface VectorDriverInterface
{
    /**
     * Create a collection/index
     */
    public function createCollection(string $name, int $dimensions, array $config = []): bool;

    /**
     * Delete a collection/index
     */
    public function deleteCollection(string $name): bool;

    /**
     * Check if collection exists
     */
    public function collectionExists(string $name): bool;

    /**
     * Insert vectors into collection
     */
    public function upsert(string $collection, array $vectors): bool;

    /**
     * Search for similar vectors
     */
    public function search(
        string $collection,
        array $vector,
        int $limit = 10,
        float $threshold = 0.0,
        array $filters = []
    ): array;

    /**
     * Delete vectors by IDs
     */
    public function delete(string $collection, array $ids): bool;

    /**
     * Get collection info
     */
    public function getCollectionInfo(string $collection): array;

    /**
     * Get vector by ID
     */
    public function get(string $collection, string $id): ?array;

    /**
     * Update vector metadata
     */
    public function updateMetadata(string $collection, string $id, array $metadata): bool;

    /**
     * Count vectors in collection
     */
    public function count(string $collection, array $filters = []): int;

    /**
     * Scroll through vectors (pagination)
     */
    public function scroll(string $collection, int $limit = 100, ?string $offset = null): array;
}
