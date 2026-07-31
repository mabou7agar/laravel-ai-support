<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class ScopedKnowledgeMatch
{
    public function __construct(
        public ScopedKnowledgeDocument $document,
        public float $score,
    ) {
    }
}
