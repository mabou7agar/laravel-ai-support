<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class ConversationMemoryResult
{
    public function __construct(
        public readonly ConversationMemoryItem $item,
        public readonly float $score,
        public readonly string $reason = 'lexical',
    ) {
    }
}
