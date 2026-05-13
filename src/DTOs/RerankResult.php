<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class RerankResult implements \JsonSerializable
{
    public function __construct(
        public readonly int $index,
        public readonly string $document,
        public readonly float $score,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'document' => $this->document,
            'score' => $this->score,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
