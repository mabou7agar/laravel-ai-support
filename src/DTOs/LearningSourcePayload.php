<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class LearningSourcePayload
{
    public function __construct(
        public string $content,
        public ?string $title = null,
        public array $metadata = [],
    ) {}
}
