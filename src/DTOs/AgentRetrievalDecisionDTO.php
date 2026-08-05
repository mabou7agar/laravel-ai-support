<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AgentRetrievalDecisionDTO
{
    public const HOST_MANAGED = 'host_managed';
    public const REQUIRED = 'required';
    public const SKIPPED = 'skipped';
    public const DEGRADED = 'degraded';
    public const UNAVAILABLE = 'unavailable';
    public const DENIED = 'denied';

    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $status = self::HOST_MANAGED,
        public string $mode = self::HOST_MANAGED,
        public string $reason = 'Retrieval remains managed by the host application.',
        public bool $required = false,
        public bool $authoritative = false,
        public bool $fallbackUsed = false,
        public array $metadata = [],
    ) {
    }

    public static function hostManaged(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            status: trim((string) ($data['status'] ?? self::HOST_MANAGED)) ?: self::HOST_MANAGED,
            mode: trim((string) ($data['mode'] ?? self::HOST_MANAGED)) ?: self::HOST_MANAGED,
            reason: trim((string) ($data['reason'] ?? '')) ?: 'Retrieval remains managed by the host application.',
            required: (bool) ($data['required'] ?? false),
            authoritative: (bool) ($data['authoritative'] ?? false),
            fallbackUsed: (bool) ($data['fallback_used'] ?? false),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'mode' => $this->mode,
            'reason' => $this->reason,
            'required' => $this->required,
            'authoritative' => $this->authoritative,
            'fallback_used' => $this->fallbackUsed,
            'metadata' => $this->metadata,
        ];
    }
}
