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
    private array $functions;
    private ?array $functionCall;

    public function __construct(
        string $prompt,
        EngineEnum|string|null $engine = null,
        EntityEnum|string|null $model = null,
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
        array $metadata = [],
        array $functions = [],
        ?array $functionCall = null
    ) {
        $this->prompt = $prompt;
        
        // Auto-select engine and model from config if not provided
        if ($engine === null) {
            $defaultEngine = config('ai-engine.default_engine', 'openai');
            $engine = EngineEnum::from($defaultEngine);
        } elseif (is_string($engine)) {
            $engine = EngineEnum::from($engine);
        }
        
        if ($model === null) {
            $defaultModel = config('ai-engine.default_model', 'gpt-4o-mini');
            $model = EntityEnum::from($defaultModel);
        } elseif (is_string($model)) {
            $model = EntityEnum::from($model);
        }
        
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
        $this->functions = $functions;
        $this->functionCall = $functionCall;
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

    public function getFunctions(): array
    {
        return $this->functions;
    }

    public function getFunctionCall(): ?array
    {
        return $this->functionCall;
    }

    /**
     * Create a new AI request
     */
    public static function make(
        string $prompt,
        EngineEnum|string $engine,
        EntityEnum|string $model,
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
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $userId,
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: array_merge($this->context, $context),
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: array_merge($this->files, $files),
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            prompt: $this->prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: array_merge($this->parameters, $parameters),
            userId: $this->userId,
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $temperature,
            seed: $this->seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
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
            conversationId: $this->conversationId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $seed,
            metadata: $this->metadata,
            functions: $this->functions,
            functionCall: $this->functionCall
        );
    }

    /**
     * Set user ID
     */
    public function withUserId(string $userId): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $userId,
            $this->conversationId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata,
            $this->functions,
            $this->functionCall
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
            $this->conversationId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            array_merge($this->metadata, $metadata),
            $this->functions,
            $this->functionCall
        );
    }

    /**
     * Set functions for function calling
     */
    public function withFunctions(array $functions, ?array $functionCall = null): self
    {
        return new self(
            $this->prompt,
            $this->engine,
            $this->model,
            $this->parameters,
            $this->userId,
            $this->conversationId,
            $this->context,
            $this->files,
            $this->stream,
            $this->systemPrompt,
            $this->messages,
            $this->maxTokens,
            $this->temperature,
            $this->seed,
            $this->metadata,
            $functions,
            $functionCall
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
            'functions' => $this->functions,
            'function_call' => $this->functionCall,
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
