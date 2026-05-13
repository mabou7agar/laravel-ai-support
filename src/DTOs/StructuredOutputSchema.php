<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class StructuredOutputSchema
{
    public function __construct(
        public readonly string $name,
        public readonly array $schema,
        public readonly bool $strict = true
    ) {}

    public static function make(array $schema, string $name = 'response', bool $strict = true): self
    {
        return new self($name, $schema, $strict);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'schema' => $this->schema,
            'strict' => $this->strict,
        ];
    }
}
