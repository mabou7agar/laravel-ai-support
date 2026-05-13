<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class FileSearch implements ProviderToolInterface
{
    public function __construct(
        protected array $stores,
        protected array $where = []
    ) {}

    public function name(): string
    {
        return 'file_search';
    }

    public function where(array $filters): self
    {
        $this->where = $filters;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'stores' => array_values(array_filter(array_map('strval', $this->stores))),
            'where' => $this->where,
        ], static fn ($value): bool => $value !== []);
    }
}
