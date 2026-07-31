<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AssistantResourceItem
{
    /** @param list<array<string, mixed>> $actions @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public string $title,
        public ?string $summary = null,
        public ?string $image = null,
        public ?string $url = null,
        public string $visibility = 'private',
        public array $actions = [],
        public array $metadata = [],
        public ?string $tenantId = null,
        public ?string $workspaceId = null,
        public ?string $userId = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            type: trim((string) ($data['type'] ?? '')),
            title: trim((string) ($data['title'] ?? '')),
            summary: self::nullableString($data['summary'] ?? null),
            image: self::nullableString($data['image'] ?? null),
            url: self::nullableString($data['url'] ?? null),
            visibility: trim((string) ($data['visibility'] ?? 'private')) ?: 'private',
            actions: array_values((array) ($data['actions'] ?? [])),
            metadata: (array) ($data['metadata'] ?? []),
            tenantId: self::nullableString($data['tenant_id'] ?? null),
            workspaceId: self::nullableString($data['workspace_id'] ?? null),
            userId: self::nullableString($data['user_id'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'summary' => $this->summary,
            'image' => $this->image,
            'url' => $this->url,
            'visibility' => $this->visibility,
            'actions' => $this->actions,
            'metadata' => $this->metadata,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
