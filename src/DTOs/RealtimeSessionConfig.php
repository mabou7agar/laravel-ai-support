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
        public readonly array $metadata = [],
        public readonly ?string $inputAudioFormat = null,
        public readonly ?string $outputAudioFormat = null,
        public readonly array $turnDetection = [],
        public readonly ?float $temperature = null,
        public readonly int|string|null $maxResponseOutputTokens = null,
        public readonly array $providerOptions = []
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
            $this->metadata,
            $this->inputAudioFormat,
            $this->outputAudioFormat,
            $this->turnDetection,
            $this->temperature,
            $this->maxResponseOutputTokens,
            $this->providerOptions
        );
    }

    public function withVoice(?string $voice): self
    {
        return new self($this->provider, $this->model, $this->modalities, $voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withInstructions(?string $instructions): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withTools(array $tools): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withMetadata(array $metadata): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, array_merge($this->metadata, $metadata), $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withAudioFormats(?string $inputAudioFormat = null, ?string $outputAudioFormat = null): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $inputAudioFormat, $outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withTurnDetection(array $turnDetection): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withTemperature(?float $temperature): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $temperature, $this->maxResponseOutputTokens, $this->providerOptions);
    }

    public function withMaxResponseOutputTokens(int|string|null $maxResponseOutputTokens): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $maxResponseOutputTokens, $this->providerOptions);
    }

    public function withProviderOptions(array $options): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, array_merge($this->providerOptions, $options));
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
            'input_audio_format' => $this->inputAudioFormat,
            'output_audio_format' => $this->outputAudioFormat,
            'turn_detection' => $this->turnDetection,
            'temperature' => $this->temperature,
            'max_response_output_tokens' => $this->maxResponseOutputTokens,
            'provider_options' => $this->providerOptions,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
