<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class HybridRetrievalResult
{
    /**
     * @param array<int, string> $sources
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly object $item,
        public readonly float $score,
        public readonly array $sources,
        public readonly array $metadata = []
    ) {}

    public function toContextObject(): object
    {
        $item = clone $this->item;
        $item->vector_score = $this->score;

        $metadata = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];
        $item->vector_metadata = array_merge($metadata, [
            'hybrid_score' => $this->score,
            'hybrid_sources' => $this->sources,
        ], $this->metadata);

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->item->id ?? null,
            'score' => $this->score,
            'sources' => $this->sources,
            'metadata' => $this->metadata,
        ];
    }
}
