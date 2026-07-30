<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AssistantResourceResult
{
    /** @param list<AssistantResourceItem> $items @param list<array<string, mixed>> $metrics @param list<array<string, mixed>> $sources */
    public function __construct(
        public array $items = [],
        public ?string $message = null,
        public array $metrics = [],
        public array $sources = [],
        public array $metadata = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'items' => array_map(static fn (AssistantResourceItem $item): array => $item->toArray(), $this->items),
            'metrics' => $this->metrics,
            'sources' => $this->sources,
            'metadata' => $this->metadata,
        ];
    }
}
