<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class HostedShell implements ProviderToolInterface
{
    public function __construct(
        protected array $container = ['type' => 'auto']
    ) {}

    public function name(): string
    {
        return 'hosted_shell';
    }

    public function container(array $container): self
    {
        $this->container = $container;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'container' => $this->container,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
