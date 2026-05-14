<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class CollectorToolCall
{
    public function __construct(
        public string $tool,
        public array $arguments = [],
    ) {
    }

    public static function fromArray(array $payload): ?self
    {
        $tool = trim((string) ($payload['tool'] ?? $payload['name'] ?? ''));
        if ($tool === '') {
            return null;
        }

        $arguments = $payload['arguments'] ?? $payload['parameters'] ?? [];

        return new self(
            tool: $tool,
            arguments: is_array($arguments) ? $arguments : [],
        );
    }

    public function toArray(): array
    {
        return [
            'tool' => $this->tool,
            'arguments' => $this->arguments,
        ];
    }
}
