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
        public readonly array $providerOptions = [],
        public readonly string $mode = 'voice_chat',
        public readonly string $transport = 'webrtc',
        public readonly array $inputAudioTranscription = [],
        public readonly array $fallbackPipeline = []
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
            $this->providerOptions,
            $this->mode,
            $this->transport,
            $this->inputAudioTranscription,
            $this->fallbackPipeline
        );
    }

    public function withVoice(?string $voice): self
    {
        return new self($this->provider, $this->model, $this->modalities, $voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions, $this->mode, $this->transport, $this->inputAudioTranscription, $this->fallbackPipeline);
    }

    public function withInstructions(?string $instructions): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions, $this->mode, $this->transport, $this->inputAudioTranscription, $this->fallbackPipeline);
    }

    public function withTools(array $tools): self
    {
        return $this->copy(tools: $tools);
    }

    public function withMetadata(array $metadata): self
    {
        return $this->copy(metadata: array_merge($this->metadata, $metadata));
    }

    public function withAudioFormats(?string $inputAudioFormat = null, ?string $outputAudioFormat = null): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $inputAudioFormat, $outputAudioFormat, $this->turnDetection, $this->temperature, $this->maxResponseOutputTokens, $this->providerOptions, $this->mode, $this->transport, $this->inputAudioTranscription, $this->fallbackPipeline);
    }

    public function withTurnDetection(array $turnDetection): self
    {
        return $this->copy(turnDetection: $turnDetection);
    }

    public function withTemperature(?float $temperature): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $temperature, $this->maxResponseOutputTokens, $this->providerOptions, $this->mode, $this->transport, $this->inputAudioTranscription, $this->fallbackPipeline);
    }

    public function withMaxResponseOutputTokens(int|string|null $maxResponseOutputTokens): self
    {
        return new self($this->provider, $this->model, $this->modalities, $this->voice, $this->instructions, $this->tools, $this->metadata, $this->inputAudioFormat, $this->outputAudioFormat, $this->turnDetection, $this->temperature, $maxResponseOutputTokens, $this->providerOptions, $this->mode, $this->transport, $this->inputAudioTranscription, $this->fallbackPipeline);
    }

    public function withProviderOptions(array $options): self
    {
        return $this->copy(providerOptions: array_merge($this->providerOptions, $options));
    }

    public function withMode(string $mode): self
    {
        return $this->copy(mode: $mode);
    }

    public function voiceChat(): self
    {
        return $this->copy(mode: 'voice_chat', modalities: ['audio']);
    }

    public function transcription(): self
    {
        return $this->copy(mode: 'transcription', modalities: ['text']);
    }

    public function withTransport(string $transport): self
    {
        return $this->copy(transport: $transport);
    }

    public function withInputAudioTranscription(array $transcription): self
    {
        return $this->copy(inputAudioTranscription: $transcription);
    }

    public function withFallbackPipeline(array $pipeline): self
    {
        return $this->copy(fallbackPipeline: $pipeline);
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
            'mode' => $this->mode,
            'transport' => $this->transport,
            'input_audio_transcription' => $this->inputAudioTranscription,
            'fallback_pipeline' => $this->fallbackPipeline,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }

    private function copy(
        ?string $provider = null,
        ?string $model = null,
        ?array $modalities = null,
        ?string $voice = null,
        ?string $instructions = null,
        ?array $tools = null,
        ?array $metadata = null,
        ?string $inputAudioFormat = null,
        ?string $outputAudioFormat = null,
        ?array $turnDetection = null,
        ?float $temperature = null,
        int|string|null $maxResponseOutputTokens = null,
        ?array $providerOptions = null,
        ?string $mode = null,
        ?string $transport = null,
        ?array $inputAudioTranscription = null,
        ?array $fallbackPipeline = null
    ): self {
        return new self(
            $provider ?? $this->provider,
            $model ?? $this->model,
            $modalities ?? $this->modalities,
            $voice ?? $this->voice,
            $instructions ?? $this->instructions,
            $tools ?? $this->tools,
            $metadata ?? $this->metadata,
            $inputAudioFormat ?? $this->inputAudioFormat,
            $outputAudioFormat ?? $this->outputAudioFormat,
            $turnDetection ?? $this->turnDetection,
            $temperature ?? $this->temperature,
            $maxResponseOutputTokens ?? $this->maxResponseOutputTokens,
            $providerOptions ?? $this->providerOptions,
            $mode ?? $this->mode,
            $transport ?? $this->transport,
            $inputAudioTranscription ?? $this->inputAudioTranscription,
            $fallbackPipeline ?? $this->fallbackPipeline
        );
    }
}
