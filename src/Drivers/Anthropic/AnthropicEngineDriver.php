<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Drivers\Anthropic;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use MagicAI\LaravelAIEngine\Drivers\BaseEngineDriver;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use MagicAI\LaravelAIEngine\Exceptions\AIEngineException;

class AnthropicEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config, Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
            'headers' => $this->buildHeaders(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        // Route to appropriate generation method based on content type
        $contentType = $request->model->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            'image' => $this->generateImage($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        return $this->generateTextStream($request);
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        // Check if API key is configured
        if (empty($this->getApiKey())) {
            return false;
        }

        // Check if model is supported
        if (!$this->supports($request->model->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::ANTHROPIC;
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
            $testRequest = new AIRequest(
                prompt: 'Hello',
                engine: EngineEnum::ANTHROPIC,
                model: EntityEnum::CLAUDE_3_5_SONNET
            );
            
            $response = $this->generateText($testRequest);
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Generate text content
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.7,
            ];

            if ($request->systemPrompt) {
                $payload['system'] = $request->systemPrompt;
            }

            $response = $this->httpClient->post('/v1/messages', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $content = $data['content'][0]['text'] ?? '';
            $tokensUsed = $data['usage']['output_tokens'] ?? $this->calculateTokensUsed($content);

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->model->creditIndex()
            )->withRequestId($data['id'] ?? null)
             ->withFinishReason($data['stop_reason'] ?? null);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Anthropic API error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        } catch (\Exception $e) {
            return AIResponse::error(
                'Unexpected error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Generate streaming text content
     */
    public function generateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            $payload = [
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens ?? 4096,
                'temperature' => $request->temperature ?? 0.7,
                'stream' => true,
            ];

            if ($request->systemPrompt) {
                $payload['system'] = $request->systemPrompt;
            }

            $response = $this->httpClient->post('/v1/messages', [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $line = trim($stream->read(1024));
                if (strpos($line, 'data: ') === 0) {
                    $data = json_decode(substr($line, 6), true);
                    if (isset($data['delta']['text'])) {
                        yield $data['delta']['text'];
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Anthropic streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate images (not supported by Anthropic)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not supported by Anthropic',
            $request->engine,
            $request->model
        );
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        return [
            ['id' => 'claude-3-5-sonnet-20240620', 'name' => 'Claude 3.5 Sonnet'],
            ['id' => 'claude-3-opus-20240229', 'name' => 'Claude 3 Opus'],
            ['id' => 'claude-3-haiku-20240307', 'name' => 'Claude 3 Haiku'],
        ];
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'vision', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::ANTHROPIC;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::CLAUDE_3_5_SONNET;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AIEngineException('Anthropic API key is required');
        }
    }

    /**
     * Build request headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'x-api-key' => $this->getApiKey(),
            'anthropic-version' => '2023-06-01',
            'User-Agent' => 'Laravel-AI-Engine/1.0',
        ];
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        $messages = [];

        // Add conversation history if provided
        if (!empty($request->messages)) {
            $messages = array_merge($messages, $request->messages);
        }

        // Add the main prompt
        $messages[] = [
            'role' => 'user',
            'content' => $request->prompt,
        ];

        return $messages;
    }
}
