<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class ConversationEntityReference
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public string $visibility = 'private',
        public ?string $tenantId = null,
        public ?string $workspaceId = null,
        public ?string $url = null,
        public array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            type: trim((string) ($data['type'] ?? $data['entity_type'] ?? '')),
            id: trim((string) ($data['id'] ?? $data['entity_id'] ?? '')),
            label: trim((string) ($data['label'] ?? $data['title'] ?? '')),
            visibility: trim((string) ($data['visibility'] ?? 'private')) ?: 'private',
            tenantId: self::nullableString($data['tenant_id'] ?? null),
            workspaceId: self::nullableString($data['workspace_id'] ?? null),
            url: self::nullableString($data['url'] ?? null),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'label' => $this->label,
            'visibility' => $this->visibility,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'url' => $this->url,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
