<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class WebFetch implements ProviderToolInterface
{
    public function __construct(
        protected ?int $max = null,
        protected array $allow = []
    ) {}

    public function name(): string
    {
        return 'web_fetch';
    }

    public function max(int $max): self
    {
        $this->max = max(1, $max);

        return $this;
    }

    public function allow(array $domains): self
    {
        $this->allow = array_values(array_filter(array_map('strval', $domains)));

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'max' => $this->max,
            'allow' => $this->allow,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
