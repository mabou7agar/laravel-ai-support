<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class AssistantResourceQuery
{
    /** @param array<string, mixed> $scope @param array<string, mixed> $context */
    public function __construct(
        public string $query,
        public ?string $type = null,
        public int $limit = 8,
        public ?string $locale = null,
        public array $scope = [],
        public array $context = [],
    ) {
    }
}
