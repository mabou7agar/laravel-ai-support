<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class RealtimeSessionConfig
{
    public function __construct(
        public readonly string $provider,
        public readonly string $model,
        public readonly array $modalities = ['text', 'audio'],
        public readonly ?string $voice = null,
        public readonly ?string $instructions = null,
        public readonly array $tools = [],
        public readonly array $metadata = []
    ) {}

    public static function make(string $provider, string $model): self
    {
        return new self($provider, $model);
    }

    public function withModalities(array $modalities): self
    {
        return new self(
            $this->provider,
            $this->model,
            array_values(array_filter(array_map('strval', $modalities))),
            $this->voice,
            $this->instructions,
            $this->tools,
            $this->metadata
        );
    }

    public function withVoice(?string $voice): self
    {
        return new self($this->provider, $this->model, $this->modalities, $voice, $this->instructions, $this->tools, $this->metadata);
    }

    public function withInstructions(?string $instructions): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $instructions, $this->tools, $this->metadata);
    }

    public function withTools(array $tools): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $tools, $this->metadata);
    }

    public function withMetadata(array $metadata): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, array_merge($this->metadata, $metadata));
    }

    public function toArray(): array
    {
        return array_filter([
            'provider' => $this->provider,
            'model' => $this->model,
            'modalities' => $this->modalities,
            'voice' => $this->voice,
            'instructions' => $this->instructions,
            'tools' => $this->tools,
            'metadata' => $this->metadata,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
