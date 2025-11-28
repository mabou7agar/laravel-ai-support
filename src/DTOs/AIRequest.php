<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * AI Request DTO (Laravel 9 compatible - without readonly properties)
 */
class AIRequest
{
    private string $prompt;
    private EngineEnum $engine;
    private EntityEnum $model;
    private array $parameters;
    private ?string $userId;
    private ?string $conversationId;
    private array $context;
    private array $files;
    private bool $stream;
    private ?string $systemPrompt;
    private array $messages;
    private ?int $maxTokens;
    private ?float $temperature;
    private ?int $seed;
    private array $metadata;

    public function __construct(
        string $prompt,
        EngineEnum $engine,
        EntityEnum $model,
        array $parameters = [],
        ?string $userId = null,
        ?string $conversationId = null,
        array $context = [],
        array $files = [],
        bool $stream = false,
        ?string $systemPrompt = null,
        array $messages = [],
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?int $seed = null,
        array $metadata = []
    ) {
        $this->prompt = $prompt;
        $this->engine = $engine;
        $this->model = $model;
        $this->parameters = $parameters;
        $this->userId = $userId;
        $this->conversationId = $conversationId;
        $this->context = $context;
        $this->files = $files;
        $this->stream = $stream;
        $this->systemPrompt = $systemPrompt;
        $this->messages = $messages;
        $this->maxTokens = $maxTokens;
        $this->temperature = $temperature;
        $this->seed = $seed;
        $this->metadata = $metadata;
    }

    // Getters
    public function getPrompt(): string
    {
        return $this->prompt;
    }

    public function getEngine(): EngineEnum
    {
        return $this->engine;
    }

    public function getModel(): EntityEnum
    {
        return $this->model;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function getFiles(): array
    {
        return $this->files;
    }

    public function isStream(): bool
    {
        return $this->stream;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getMaxTokens(): ?int
    {
        return $this->maxTokens;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

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
            $prompt,
            $engine,
            $model,
            $parameters
        );
    }

    /**
     * Set the user ID
     */
    public function forUser(string $userId): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Enable streaming
     */
    public function withStreaming(bool $stream = true): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Add context data
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            array_merge($this->context, $context),
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Add files
     */
    public function withFiles(array $files): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            array_merge($this->files, $files),
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Set system prompt
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
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
            conversationId: $this->conversationId,  // ← MISSING!
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
            $this->prompt,
            $this->engine,
            $this->model,
            array_merge($this->parameters, $parameters),
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Set max tokens
     */
    public function withMaxTokens(int $maxTokens): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Set temperature
     */
    public function withTemperature(float $temperature): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $temperature,
            $this->seed,
            $this->metadata
        );
    }

    /**
     * Set seed for reproducible results
     */
    public function withSeed(int $seed): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $seed,
            $this->metadata
        );
    }

    /**
     * Add metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            array_merge($this->metadata, $metadata)
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

    // Magic getter for backward compatibility with readonly properties
    public function __get(string $name)
    {
        $getter = 'get' . ucfirst($name);
        if (method_exists($this, $getter)) {
            return $this->$getter();
        }
        
        // Direct property access for backward compatibility
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        
        throw new \InvalidArgumentException("Property {$name} does not exist");
    }
}
