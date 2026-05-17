<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tools\Provider;

use LaravelAIEngine\Contracts\ProviderToolInterface;

class ProviderSkill implements ProviderToolInterface
{
    public function __construct(
        protected string $skillName,
        protected ?string $version = null,
        protected array $inputSchema = []
    ) {}

    public function name(): string
    {
        return 'provider_skill';
    }

    public function version(?string $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function inputSchema(array $schema): self
    {
        $this->inputSchema = $schema;

        return $this;
    }

    public function toArray(): array
    {
        return array_filter([
            'type' => $this->name(),
            'name' => $this->skillName,
            'version' => $this->version,
            'input_schema' => $this->inputSchema,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
