<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final readonly class TaskModelRoute
{
    /** @param array<string, mixed> $parameters @param list<string> $requiredCapabilities */
    public function __construct(
        public string $task,
        public string $engine,
        public string $model,
        public ?string $fallbackEngine = null,
        public ?string $fallbackModel = null,
        public array $parameters = [],
        public array $requiredCapabilities = [],
        public bool $enabled = true,
        public array $metadata = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, ?string $task = null): self
    {
        return new self(
            task: trim((string) ($data['task'] ?? $task ?? '')),
            engine: trim((string) ($data['engine'] ?? '')),
            model: trim((string) ($data['model'] ?? '')),
            fallbackEngine: self::nullableString($data['fallback_engine'] ?? $data['fallbackEngine'] ?? null),
            fallbackModel: self::nullableString($data['fallback_model'] ?? $data['fallbackModel'] ?? null),
            parameters: (array) ($data['parameters'] ?? []),
            requiredCapabilities: array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($data['required_capabilities'] ?? $data['requiredCapabilities'] ?? []),
            ))),
            enabled: (bool) ($data['enabled'] ?? true),
            metadata: (array) ($data['metadata'] ?? []),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'engine' => $this->engine,
            'model' => $this->model,
            'fallback_engine' => $this->fallbackEngine,
            'fallback_model' => $this->fallbackModel,
            'parameters' => $this->parameters,
            'required_capabilities' => $this->requiredCapabilities,
            'enabled' => $this->enabled,
            'metadata' => $this->metadata,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
