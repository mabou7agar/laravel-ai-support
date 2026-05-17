<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class ApplyPatchTool implements ProviderToolInterface
{
    public function __construct(
        protected ?string $workspace = null,
        protected array $allowedPaths = []
    ) {}

    public function name(): string
    {
        return 'apply_patch';
    }

    public function workspace(?string $workspace): self
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function allowedPaths(array $paths): self
    {
        $this->allowedPaths = array_values(array_filter(array_map('strval', $paths)));

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'workspace' => $this->workspace,
            'allowed_paths' => $this->allowedPaths,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
