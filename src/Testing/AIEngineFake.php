<?php

declare(strict_types=1);

namespace LaravelAIEngine\Testing;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\UnifiedEngineManager;

class AIEngineFake extends UnifiedEngineManager
{
    protected array $responses;
    protected array $requests = [];

    public function __construct(array $responses = [])
    {
        $this->responses = array_values($responses);
    }

    public function generatePrompt(string $prompt, array $options = []): AIResponse
    {
        $this->requests[] = [
            'type' => 'prompt',
            'prompt' => $prompt,
            'options' => $options,
        ];

        return $this->nextResponse($options);
    }

    public function processRequest(AIRequest $request): AIResponse
    {
        $this->requests[] = [
            'type' => 'request',
            'request' => $request,
        ];

        return $this->nextResponse($request->getMetadata());
    }

    public function send(array $messages, array $options = []): AIResponse
    {
        $this->requests[] = [
            'type' => 'messages',
            'messages' => $messages,
            'options' => $options,
        ];

        return $this->nextResponse($options);
    }

    public function requests(): array
    {
        return $this->requests;
    }

    public function assertPrompted(?callable $callback = null): void
    {
        if ($this->requests === []) {
            throw new \RuntimeException('Expected AI engine to be prompted, but no requests were recorded.');
        }

        if ($callback === null) {
            return;
        }

        foreach ($this->requests as $request) {
            if ($callback($request)) {
                return;
            }
        }

        throw new \RuntimeException('No recorded AI engine request matched the assertion callback.');
    }

    protected function nextResponse(array $metadata = []): AIResponse
    {
        $response = array_shift($this->responses);

        if ($response instanceof AIResponse) {
            return $response;
        }

        if (is_string($response)) {
            return AIResponse::success($response, metadata: $metadata);
        }

        if (is_array($response)) {
            return new AIResponse(
                content: (string) ($response['content'] ?? ''),
                engine: $response['engine'] ?? null,
                model: $response['model'] ?? null,
                metadata: array_merge($metadata, (array) ($response['metadata'] ?? [])),
                error: $response['error'] ?? null,
                success: (bool) ($response['success'] ?? true)
            );
        }

        return AIResponse::success('', metadata: $metadata);
    }
}
