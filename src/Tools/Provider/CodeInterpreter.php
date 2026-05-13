<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class CodeInterpreter implements ProviderToolInterface
{
    public function __construct(
        protected array $fileIds = [],
        protected string $memoryLimit = '4g',
        protected array $container = ['type' => 'auto']
    ) {}

    public function name(): string
    {
        return 'code_interpreter';
    }

    public function withFiles(array $fileIds): self
    {
        $this->fileIds = array_values(array_filter(array_map('strval', $fileIds)));

        return $this;
    }

    public function memoryLimit(string $memoryLimit): self
    {
        $this->memoryLimit = $memoryLimit;

        return $this;
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
            'file_ids' => $this->fileIds,
            'memory_limit' => $this->memoryLimit,
            'container' => $this->container,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
