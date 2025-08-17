<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;

abstract class BaseEngineDriver implements EngineDriverInterface
{
    protected array $config;
    protected EngineEnum $engine;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
    }

    /**
     * Get the engine configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Set the engine configuration
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            // Create a simple test request
            $testRequest = AIRequest::make(
                'Test connection',
                $this->getEngineEnum(),
                $this->getDefaultModel()
            );

            $response = $this->generateText($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate video content (default implementation throws exception)
     */
    public function generateVideo(AIRequest $request): AIResponse
    {
        if (!$this->supports('video')) {
            throw new \InvalidArgumentException('Video generation not supported by this engine');
        }

        return $this->doGenerateVideo($request);
    }

    /**
     * Generate audio content (default implementation throws exception)
     */
    public function generateAudio(AIRequest $request): AIResponse
    {
        if (!$this->supports('audio')) {
            throw new \InvalidArgumentException('Audio generation not supported by this engine');
        }

        return $this->doGenerateAudio($request);
    }

    /**
     * Process audio to text (default implementation throws exception)
     */
    public function audioToText(AIRequest $request): AIResponse
    {
        if (!$this->supports('speech_to_text')) {
            throw new \InvalidArgumentException('Audio to text not supported by this engine');
        }

        return $this->doAudioToText($request);
    }

    /**
     * Generate embeddings (default implementation throws exception)
     */
    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        if (!$this->supports('embeddings')) {
            throw new \InvalidArgumentException('Embeddings not supported by this engine');
        }

        return $this->doGenerateEmbeddings($request);
    }

    /**
     * Generate streaming text content (default implementation throws exception)
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        if (!$this->supports('streaming')) {
            throw new \InvalidArgumentException('Streaming not supported by this engine');
        }

        yield from $this->doGenerateTextStream($request);
    }

    /**
     * Get supported capabilities for this engine
     */
    abstract protected function getSupportedCapabilities(): array;

    /**
     * Get the engine enum
     */
    abstract protected function getEngineEnum(): EngineEnum;

    /**
     * Get the default model for this engine
     */
    abstract protected function getDefaultModel(): \LaravelAIEngine\Enums\EntityEnum;

    /**
     * Validate the engine configuration
     */
    abstract protected function validateConfig(): void;

    /**
     * Implementation-specific video generation
     */
    protected function doGenerateVideo(AIRequest $request): AIResponse
    {
        throw new \BadMethodCallException('Video generation not implemented');
    }

    /**
     * Implementation-specific audio generation
     */
    protected function doGenerateAudio(AIRequest $request): AIResponse
    {
        throw new \BadMethodCallException('Audio generation not implemented');
    }

    /**
     * Implementation-specific audio to text
     */
    protected function doAudioToText(AIRequest $request): AIResponse
    {
        throw new \BadMethodCallException('Audio to text not implemented');
    }

    /**
     * Implementation-specific embeddings generation
     */
    protected function doGenerateEmbeddings(AIRequest $request): AIResponse
    {
        throw new \BadMethodCallException('Embeddings generation not implemented');
    }

    /**
     * Implementation-specific streaming text generation
     */
    protected function doGenerateTextStream(AIRequest $request): \Generator
    {
        throw new \BadMethodCallException('Streaming text generation not implemented');
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Handle API response
     */
    protected function handleResponse(array $response, AIRequest $request): AIResponse
    {
        if (isset($response['error'])) {
            return AIResponse::error(
                $response['error']['message'] ?? 'Unknown error',
                $request->engine,
                $request->model
            );
        }

        return AIResponse::success(
            $response['content'] ?? '',
            $request->engine,
            $request->model,
            $response
        );
    }

    /**
     * Calculate tokens used (basic implementation)
     */
    protected function calculateTokensUsed(string $content): int
    {
        // Basic estimation: ~4 characters per token
        return (int) ceil(strlen($content) / 4);
    }

    /**
     * Get API timeout
     */
    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 30;
    }

    /**
     * Get API base URL
     */
    protected function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? '';
    }

    /**
     * Get API key
     */
    protected function getApiKey(): string
    {
        return $this->config['api_key'] ?? '';
    }
}
