<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use InvalidArgumentException;

final readonly class CompatibilitySurface
{
    /**
     * @param list<string> $locations
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public string $replacement,
        public ?string $removeIn = null,
        public array $locations = [],
        public array $metadata = [],
    ) {
        if ($this->id === '' || $this->kind === '' || $this->status === '' || $this->replacement === '') {
            throw new InvalidArgumentException('Compatibility surfaces require id, kind, status, and replacement.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: trim((string) ($data['id'] ?? '')),
            kind: trim((string) ($data['kind'] ?? '')),
            status: trim((string) ($data['status'] ?? '')),
            replacement: trim((string) ($data['replacement'] ?? '')),
            removeIn: self::nullableString($data['remove_in'] ?? null),
            locations: array_values(array_filter(array_map(
                static fn (mixed $location): string => trim((string) $location),
                (array) ($data['locations'] ?? []),
            ))),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'replacement' => $this->replacement,
            'remove_in' => $this->removeIn,
            'locations' => $this->locations,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
