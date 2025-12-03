<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Ollama;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;

/**
 * Ollama Engine Driver
 * 
 * Supports local AI models running via Ollama
 * https://ollama.ai
 */
class OllamaEngineDriver extends BaseEngineDriver
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
        $this->validateRequest($request);
        
        $contentType = $request->model->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            'embeddings' => $this->generateEmbeddings($request),
            default => throw new \InvalidArgumentException("Unsupported content type: {$contentType}")
        };
    }

    /**
     * Generate text using Ollama
     */
    protected function generateText(AIRequest $request): AIResponse
    {
        try {
            $payload = $this->buildTextPayload($request);
            
            $response = $this->httpClient->post('/api/generate', [
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            
            return $this->buildTextResponse($body, $request);
        } catch (RequestException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Generate streaming content
     */
    public function stream(AIRequest $request): \Generator
    {
        $this->validateRequest($request);
        
        try {
            $payload = $this->buildTextPayload($request);
            $payload['stream'] = true;
            
            $response = $this->httpClient->post('/api/generate', [
                'json' => $payload,
                'stream' => true,
            ]);

            $stream = $response->getBody();
            
            while (!$stream->eof()) {
                $line = $this->readLine($stream);
                
                if (empty($line)) {
                    continue;
                }
                
                $data = json_decode($line, true);
                
                if (isset($data['response'])) {
                    yield $data['response'];
                }
                
                if (isset($data['done']) && $data['done'] === true) {
                    break;
                }
            }
        } catch (RequestException $e) {
            throw new AIEngineException('Ollama streaming failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate embeddings using Ollama
     */
    protected function generateEmbeddings(AIRequest $request): AIResponse
    {
        try {
            $payload = [
                'model' => $this->getModelName($request),
                'prompt' => $request->getPrompt(),
            ];
            
            $response = $this->httpClient->post('/api/embeddings', [
                'json' => $payload,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            
            return AIResponse::success([
                'embeddings' => $body['embedding'] ?? [],
                'model' => $payload['model'],
            ]);
        } catch (RequestException $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Validate the request before processing
     */
    public function validateRequest(AIRequest $request): bool
    {
        if (empty($request->getPrompt())) {
            throw new AIEngineException('Prompt is required');
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return new EngineEnum(EngineEnum::OLLAMA);
    }

    /**
     * Check if the engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->getSupportedCapabilities());
    }

    /**
     * Get supported capabilities
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'embeddings', 'chat'];
    }

    /**
     * Test the engine connection
     */
    public function test(): bool
    {
        try {
            // Try to list available models
            $response = $this->httpClient->get('/api/tags');
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get available models from Ollama
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get('/api/tags');
            $body = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($model) {
                return [
                    'name' => $model['name'],
                    'size' => $model['size'] ?? null,
                    'modified_at' => $model['modified_at'] ?? null,
                    'digest' => $model['digest'] ?? null,
                ];
            }, $body['models'] ?? []);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Pull a model from Ollama registry
     */
    public function pullModel(string $modelName): bool
    {
        try {
            $response = $this->httpClient->post('/api/pull', [
                'json' => ['name' => $modelName],
            ]);
            
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build text generation payload
     */
    protected function buildTextPayload(AIRequest $request): array
    {
        $payload = [
            'model' => $this->getModelName($request),
            'prompt' => $request->getPrompt(),
            'stream' => false,
        ];

        // Add optional parameters
        if ($request->getTemperature() !== null) {
            $payload['options']['temperature'] = $request->getTemperature();
        }

        if ($request->getMaxTokens() !== null) {
            $payload['options']['num_predict'] = $request->getMaxTokens();
        }

        // Add system message if present
        if ($request->getSystemMessage()) {
            $payload['system'] = $request->getSystemMessage();
        }

        // Add conversation context if present
        if ($request->getMessages()) {
            $payload['context'] = $this->buildContext($request->getMessages());
        }

        return $payload;
    }

    /**
     * Build context from messages
     */
    protected function buildContext(array $messages): array
    {
        // Ollama uses a different format for conversation context
        // This is a simplified version - you may need to adjust based on your needs
        return array_map(function ($message) {
            return [
                'role' => $message['role'] ?? 'user',
                'content' => $message['content'] ?? '',
            ];
        }, $messages);
    }

    /**
     * Build text response
     */
    protected function buildTextResponse(array $body, AIRequest $request): AIResponse
    {
        if (isset($body['error'])) {
            return AIResponse::error($body['error']);
        }

        return AIResponse::success([
            'content' => $body['response'] ?? '',
            'model' => $body['model'] ?? $this->getModelName($request),
            'created_at' => $body['created_at'] ?? now()->toIso8601String(),
            'done' => $body['done'] ?? true,
            'context' => $body['context'] ?? null,
            'total_duration' => $body['total_duration'] ?? null,
            'load_duration' => $body['load_duration'] ?? null,
            'prompt_eval_count' => $body['prompt_eval_count'] ?? null,
            'eval_count' => $body['eval_count'] ?? null,
        ]);
    }

    /**
     * Get model name from request
     */
    protected function getModelName(AIRequest $request): string
    {
        // If a custom model is specified in config, use it
        if (isset($this->config['default_model'])) {
            return $this->config['default_model'];
        }

        // Otherwise, try to get from request model
        $modelValue = $request->getModel()->value ?? 'llama2';
        
        // Map common model names
        return match ($modelValue) {
            'llama-2-7b', 'llama2-7b' => 'llama2:7b',
            'llama-2-13b', 'llama2-13b' => 'llama2:13b',
            'llama-3-8b', 'llama3-8b' => 'llama3:8b',
            'mistral-7b' => 'mistral:7b',
            'mixtral-8x7b' => 'mixtral:8x7b',
            'codellama-7b' => 'codellama:7b',
            'phi-2' => 'phi:2.7b',
            'gemma-2b' => 'gemma:2b',
            'gemma-7b' => 'gemma:7b',
            default => $modelValue,
        };
    }

    /**
     * Build HTTP headers
     */
    protected function buildHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Get API key (Ollama doesn't require one by default)
     */
    protected function getApiKey(): ?string
    {
        return $this->config['api_key'] ?? null;
    }

    /**
     * Get base URL
     */
    protected function getBaseUrl(): string
    {
        return $this->config['base_url'] ?? 'http://localhost:11434';
    }

    /**
     * Get timeout
     */
    protected function getTimeout(): int
    {
        return $this->config['timeout'] ?? 120; // Ollama can be slow on first run
    }

    /**
     * Handle exceptions
     */
    protected function handleException(RequestException $e): AIResponse
    {
        $message = $e->getMessage();
        $code = $e->getCode();

        if ($e->hasResponse()) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $message = $body['error'] ?? $message;
        }

        return AIResponse::error($message, $code);
    }

    /**
     * Read a line from stream
     */
    protected function readLine($stream): string
    {
        $line = '';
        while (!$stream->eof()) {
            $char = $stream->read(1);
            if ($char === "\n") {
                break;
            }
            $line .= $char;
        }
        return trim($line);
    }

    /**
     * Validate configuration
     */
    protected function validateConfig(): void
    {
        // Ollama doesn't require API key, but we should check if base_url is set
        if (empty($this->config['base_url']) && !isset($_ENV['OLLAMA_BASE_URL'])) {
            // Use default localhost
            $this->config['base_url'] = 'http://localhost:11434';
        }
    }
}
