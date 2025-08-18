<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * Engine proxy for fluent API
 */
class EngineProxy
{
    protected ?EngineEnum $engine = null;
    protected ?EntityEnum $model = null;
    protected array $parameters = [];
    protected ?string $userId = null;
    protected array $metadata = [];

    public function __construct(
        protected AIEngineService $aiEngineService,
        ?string $engine = null
    ) {
        if ($engine) {
            $this->engine = EngineEnum::from($engine);
        }
    }

    /**
     * Set the AI engine
     */
    public function engine(string $engine): self
    {
        $this->engine = EngineEnum::from($engine);
        return $this;
    }

    /**
     * Set the AI model
     */
    public function model(string $model): self
    {
        $this->model = EntityEnum::from($model);
        return $this;
    }

    /**
     * Set parameters
     */
    public function parameters(array $parameters): self
    {
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }

    /**
     * Set temperature
     */
    public function temperature(float $temperature): self
    {
        $this->parameters['temperature'] = $temperature;
        return $this;
    }

    /**
     * Set max tokens
     */
    public function maxTokens(int $maxTokens): self
    {
        $this->parameters['max_tokens'] = $maxTokens;
        return $this;
    }

    /**
     * Set user ID
     */
    public function user(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Set metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Send messages and get response
     */
    public function send(array $messages): AIResponse
    {
        $request = $this->buildRequest($messages);
        return $this->aiEngineService->generate($request);
    }

    /**
     * Stream messages
     */
    public function stream(array $messages): \Generator
    {
        $request = $this->buildRequest($messages);
        yield from $this->aiEngineService->stream($request);
    }

    /**
     * Send with conversation context
     */
    public function sendWithConversation(string $message, string $conversationId): AIResponse
    {
        return $this->aiEngineService->generateWithConversation(
            message: $message,
            conversationId: $conversationId,
            engine: $this->getEngine(),
            model: $this->getModel(),
            userId: $this->userId,
            parameters: $this->parameters
        );
    }

    /**
     * Build AI request from current configuration
     */
    protected function buildRequest(array $messages): AIRequest
    {
        return new AIRequest(
            prompt: $this->formatMessages($messages),
            engine: $this->getEngine(),
            model: $this->getModel(),
            parameters: $this->parameters,
            userId: $this->userId,
            metadata: $this->metadata
        );
    }

    /**
     * Get engine with fallback to default
     */
    protected function getEngine(): EngineEnum
    {
        return $this->engine ?? EngineEnum::from(config('ai-engine.default_engine', 'openai'));
    }

    /**
     * Get model with fallback to default
     */
    protected function getModel(): EntityEnum
    {
        return $this->model ?? EntityEnum::from(config('ai-engine.default_model', 'gpt-4o'));
    }

    /**
     * Format messages array to prompt string
     */
    protected function formatMessages(array $messages): string
    {
        if (empty($messages)) {
            return '';
        }

        // If it's already a simple string, return it
        if (is_string($messages[0])) {
            return implode("\n", $messages);
        }

        // Format chat messages
        $formatted = [];
        foreach ($messages as $message) {
            if (isset($message['role']) && isset($message['content'])) {
                $formatted[] = ucfirst($message['role']) . ': ' . $message['content'];
            }
        }

        return implode("\n", $formatted);
    }
}
