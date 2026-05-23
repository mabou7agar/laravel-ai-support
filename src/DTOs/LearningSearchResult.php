<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearningSearchResult
{
    public function __construct(
        public LearningSourceRecord $source,
        public LearnedItemRecord $item,
        public float $score,
        public string $reason = 'sql_lexical',
    ) {}
}
