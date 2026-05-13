<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class ToolSearch implements ProviderToolInterface
{
    public function __construct(
        protected array $namespaces = [],
        protected ?int $maxResults = null
    ) {}

    public function name(): string
    {
        return 'tool_search';
    }

    public function namespaces(array $namespaces): self
    {
        $this->namespaces = array_values(array_filter(array_map('strval', $namespaces)));

        return $this;
    }

    public function maxResults(int $maxResults): self
    {
        $this->maxResults = max(1, $maxResults);

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'namespaces' => $this->namespaces,
            'max_results' => $this->maxResults,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
