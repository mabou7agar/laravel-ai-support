<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class AIRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly EngineEnum $engine,
        public readonly EntityEnum $model,
        public readonly array $parameters = [],
        public readonly ?string $userId = null,
        public readonly array $context = [],
        public readonly array $files = [],
        public readonly bool $stream = false,
        public readonly ?string $systemPrompt = null,
        public readonly array $messages = [],
        public readonly ?int $maxTokens = null,
        public readonly ?float $temperature = null,
        public readonly ?int $seed = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a new AI request
     */
    public static function make(
        string $prompt,
        EngineEnum $engine,
        EntityEnum $model,
        array $parameters = []
    ): self {
        return new self(
            prompt: $prompt,
            engine: $engine,
            model: $model,
            parameters: $parameters
        );
    }

    /**
     * Set the user ID
     */
    public function forUser(string $userId): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Enable streaming
     */
    public function withStreaming(bool $stream = true): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Add context data
     */
    public function withContext(array $context): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: array_merge($this->context, $context),
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Add files
     */
    public function withFiles(array $files): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: array_merge($this->files, $files),
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set system prompt
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set conversation messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set parameters
     */
    public function withParameters(array $parameters): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: array_merge($this->parameters, $parameters),
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set max tokens
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set temperature
     */
    public function withTemperature(float $temperature): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Set seed for reproducible results
     */
    public function withSeed(int $seed): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $seed,
            metadata: $this->metadata
        );
    }

    /**
     * Add metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: array_merge($this->metadata, $metadata)
        );
    }

    /**
     * Get the content type based on the model
     */
    public function getContentType(): string
    {
        return $this->model->contentType();
    }

    /**
     * Check if this is a text generation request
     */
    public function isTextGeneration(): bool
    {
        return $this->getContentType() === 'text';
    }

    /**
     * Check if this is an image generation request
     */
    public function isImageGeneration(): bool
    {
        return $this->getContentType() === 'image';
    }

    /**
     * Check if this is a video generation request
     */
    public function isVideoGeneration(): bool
    {
        return $this->getContentType() === 'video';
    }

    /**
     * Check if this is an audio generation request
     */
    public function isAudioGeneration(): bool
    {
        return $this->getContentType() === 'audio';
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'engine' => $this->engine->value,
            'model' => $this->model->value,
            'parameters' => $this->parameters,
            'user_id' => $this->userId,
            'context' => $this->context,
            'files' => $this->files,
            'stream' => $this->stream,
            'system_prompt' => $this->systemPrompt,
            'messages' => $this->messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'seed' => $this->seed,
            'metadata' => $this->metadata,
        ];
    }
}
