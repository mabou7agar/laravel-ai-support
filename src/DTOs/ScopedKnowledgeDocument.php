<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\KnowledgeScope;

final readonly class ScopedKnowledgeDocument
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public string $text,
        public KnowledgeScope $scope,
        public ?string $tenantId = null,
        public ?string $workspaceId = null,
        public ?string $userId = null,
        public array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            text: trim((string) ($data['text'] ?? '')),
            scope: KnowledgeScope::from((string) ($data['scope'] ?? KnowledgeScope::TenantPrivate->value)),
            tenantId: self::nullableString($data['tenant_id'] ?? null),
            workspaceId: self::nullableString($data['workspace_id'] ?? null),
            userId: self::nullableString($data['user_id'] ?? null),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'scope' => $this->scope->value,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
