<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearnedItemRecord
{
    public function __construct(
        public string $itemId,
        public string $kind,
        public string $content,
        public ?string $title = null,
        public array $metadata = [],
        public float $confidence = 0.7,
        public int $position = 0,
    ) {}
}
