<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class DocumentIngestionResult
{
    public function __construct(
        public readonly string $storeId,
        public readonly string $documentId,
        public readonly string $source,
        public readonly ?string $disk,
        public readonly string $content,
        public readonly array $metadata,
        public readonly array $store,
    ) {}

    public function toArray(): array
    {
        return [
            'store_id' => $this->storeId,
            'document_id' => $this->documentId,
            'source' => $this->source,
            'disk' => $this->disk,
            'content' => $this->content,
            'metadata' => $this->metadata,
            'store' => $this->store,
        ];
    }
}
