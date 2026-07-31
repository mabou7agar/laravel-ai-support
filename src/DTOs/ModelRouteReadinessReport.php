<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class ModelRouteReadinessReport
{
    /** @param list<string> $issues */
    public function __construct(
        public TaskModelRoute $route,
        public array $issues = [],
    ) {
    }

    public function ready(): bool
    {
        return $this->issues === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->route->toArray(),
            'ready' => $this->ready(),
            'issues' => $this->issues,
        ];
    }
}
