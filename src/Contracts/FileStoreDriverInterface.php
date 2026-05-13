<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\Files\Document;

interface FileStoreDriverInterface
{
    public function upload(Document|string $document, array $metadata = []): array;

    public function get(string $fileId): ?array;

    public function delete(string $fileId): bool;
}
