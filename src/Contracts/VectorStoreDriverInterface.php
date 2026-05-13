<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\Files\Document;

interface VectorStoreDriverInterface
{
    public function create(string $name, array $metadata = []): array;

    public function add(string $storeId, Document|string $document, array $metadata = []): array;

    public function get(string $storeId): ?array;

    public function delete(string $storeId): bool;
}
