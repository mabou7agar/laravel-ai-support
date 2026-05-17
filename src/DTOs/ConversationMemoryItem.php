<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use DateTimeInterface;

class ConversationMemoryItem
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly ?string $id,
        public readonly ?string $memoryId,
        public readonly string $namespace,
        public readonly string $key,
        public readonly ?string $value,
        public readonly string $summary,
        public readonly ?string $userId = null,
        public readonly ?string $tenantId = null,
        public readonly ?string $workspaceId = null,
        public readonly ?string $sessionId = null,
        public readonly float $confidence = 0.7,
        public readonly array $metadata = [],
        public readonly ?DateTimeInterface $lastSeenAt = null,
        public readonly ?DateTimeInterface $expiresAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (string) $data['id'] : null,
            memoryId: isset($data['memory_id']) ? (string) $data['memory_id'] : null,
            namespace: trim((string) ($data['namespace'] ?? 'conversation')) ?: 'conversation',
            key: trim((string) ($data['key'] ?? '')) ?: 'memory',
            value: array_key_exists('value', $data) && $data['value'] !== null ? (string) $data['value'] : null,
            summary: trim((string) ($data['summary'] ?? '')),
            userId: self::nullableString($data['user_id'] ?? $data['userId'] ?? null),
            tenantId: self::nullableString($data['tenant_id'] ?? $data['tenantId'] ?? null),
            workspaceId: self::nullableString($data['workspace_id'] ?? $data['workspaceId'] ?? null),
            sessionId: self::nullableString($data['session_id'] ?? $data['sessionId'] ?? null),
            confidence: min(1.0, max(0.0, (float) ($data['confidence'] ?? 0.7))),
            metadata: (array) ($data['metadata'] ?? []),
            lastSeenAt: $data['last_seen_at'] ?? $data['lastSeenAt'] ?? null,
            expiresAt: $data['expires_at'] ?? $data['expiresAt'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'memory_id' => $this->memoryId,
            'namespace' => $this->namespace,
            'key' => $this->key,
            'value' => $this->value,
            'summary' => $this->summary,
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'workspace_id' => $this->workspaceId,
            'session_id' => $this->sessionId,
            'confidence' => $this->confidence,
            'metadata' => $this->metadata,
            'last_seen_at' => $this->lastSeenAt,
            'expires_at' => $this->expiresAt,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
