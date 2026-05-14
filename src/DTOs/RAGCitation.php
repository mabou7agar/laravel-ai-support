<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RAGCitation
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $title = null,
        public readonly ?string $url = null,
        public readonly ?string $sourceId = null,
        public readonly array $metadata = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            type: (string) ($data['type'] ?? $data['citation_type'] ?? 'source'),
            title: isset($data['title']) ? (string) $data['title'] : ($data['citation_title'] ?? null),
            url: isset($data['url']) ? (string) $data['url'] : ($data['citation_url'] ?? null),
            sourceId: isset($data['source_id']) ? (string) $data['source_id'] : ($data['id'] ?? null),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : []
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'source_id' => $this->sourceId,
            'metadata' => $this->metadata,
        ];
    }
}
