<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AgentTurnDecisionDTO
{
    public const PASSTHROUGH = 'passthrough';

    /**
     * @param list<string> $requestedCapabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $route = self::PASSTHROUGH,
        public float $confidence = 0.0,
        public string $reason = 'Host-managed routing remains unchanged.',
        public array $requestedCapabilities = [],
        public string $retrievalMode = 'host_managed',
        public ?string $clarification = null,
        public array $metadata = [],
    ) {
    }

    public static function passthrough(string $reason = 'Host-managed routing remains unchanged.'): self
    {
        return new self(reason: $reason);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            route: trim((string) ($data['route'] ?? self::PASSTHROUGH)) ?: self::PASSTHROUGH,
            confidence: max(0.0, min(1.0, (float) ($data['confidence'] ?? 0.0))),
            reason: trim((string) ($data['reason'] ?? '')) ?: 'Host-managed routing remains unchanged.',
            requestedCapabilities: self::strings($data['requested_capabilities'] ?? []),
            retrievalMode: trim((string) ($data['retrieval_mode'] ?? 'host_managed')) ?: 'host_managed',
            clarification: self::nullableString($data['clarification'] ?? null),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'route' => $this->route,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'requested_capabilities' => $this->requestedCapabilities,
            'retrieval_mode' => $this->retrievalMode,
            'clarification' => $this->clarification,
            'metadata' => $this->metadata,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            is_array($value) ? $value : [],
        ), static fn (string $item): bool => $item !== '')));
    }

    private static function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
