<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class GenericModuleActionDTO
{
    /**
     * @param array<string, mixed> $resource
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $actionId,
        public readonly string $operation,
        public readonly string $resourceKey,
        public readonly array $resource,
        public readonly array $definition,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $payload
     */
    public static function fromDefinition(string $actionId, array $definition, array $payload): self
    {
        return new self(
            actionId: $actionId,
            operation: (string) ($definition['operation'] ?? 'create'),
            resourceKey: (string) ($definition['resource_key'] ?? $actionId),
            resource: (array) ($definition['resource'] ?? []),
            definition: $definition,
            payload: $payload,
        );
    }

    public function modelClass(): string
    {
        return (string) ($this->resource['class'] ?? '');
    }

    public function label(): string
    {
        return (string) ($this->resource['label'] ?? $this->resourceKey);
    }

    /**
     * @return array<int, string>
     */
    public function allowedFields(): array
    {
        return array_keys((array) ($this->resource['fields'] ?? []));
    }

    /**
     * @return array<int, string>
     */
    public function lookupFields(): array
    {
        return (array) ($this->resource['lookup'] ?? ['id']);
    }
}
