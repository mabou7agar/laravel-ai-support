<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Drivers\Gemini;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use MagicAI\LaravelAIEngine\Drivers\BaseEngineDriver;
use MagicAI\LaravelAIEngine\DTOs\AIRequest;
use MagicAI\LaravelAIEngine\DTOs\AIResponse;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;

class GeminiEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;

    public function __construct(array $config)
    {
        parent::__construct($config);
        
        $this->httpClient = new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
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
        if (empty($this->getApiKey())) {
            return false;
        }
        
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
        return EngineEnum::GEMINI;
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
            $testRequest = new AIRequest(
                prompt: 'Hello',
                engine: EngineEnum::GEMINI,
                model: EntityEnum::GEMINI_1_5_PRO
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
            $contents = $this->buildContents($request);
            
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $request->temperature ?? 0.7,
                    'maxOutputTokens' => $request->maxTokens ?? 2048,
                ],
            ];

            if ($request->systemPrompt) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $request->systemPrompt]]
                ];
            }

            $url = "/v1beta/models/{$request->model->value}:generateContent";
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $tokensUsed = $data['usageMetadata']['totalTokenCount'] ?? $this->calculateTokensUsed($content);

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->model->creditIndex()
            )->withFinishReason($data['candidates'][0]['finishReason'] ?? null);

        } catch (RequestException $e) {
            return AIResponse::error(
                'Gemini API error: ' . $e->getMessage(),
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
            $contents = $this->buildContents($request);
            
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $request->temperature ?? 0.7,
                    'maxOutputTokens' => $request->maxTokens ?? 2048,
                ],
            ];

            if ($request->systemPrompt) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $request->systemPrompt]]
                ];
            }

            $url = "/v1beta/models/{$request->model->value}:streamGenerateContent";
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
                'stream' => true,
            ]);

            $stream = $response->getBody();
            while (!$stream->eof()) {
                $line = trim($stream->read(1024));
                if (strpos($line, 'data: ') === 0) {
                    $data = json_decode(substr($line, 6), true);
                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        yield $data['candidates'][0]['content']['parts'][0]['text'];
                    }
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('Gemini streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate images (not supported by Gemini directly)
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        return AIResponse::error(
            'Image generation not directly supported by Gemini',
            $request->engine,
            $request->model
        );
    }

    /**
     * Generate embeddings
     */
    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        try {
            $payload = [
                'model' => "models/text-embedding-004",
                'content' => [
                    'parts' => [['text' => $request->prompt]]
                ],
            ];

            $response = $this->httpClient->post('/v1beta/models/text-embedding-004:embedContent', [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $embeddings = $data['embedding']['values'] ?? [];

            return AIResponse::success(
                json_encode($embeddings),
                $request->engine,
                $request->model
            )->withDetailedUsage([
                'embeddings' => $embeddings,
                'dimensions' => count($embeddings),
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Gemini embeddings error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Get available models for this engine
     */
    public function getAvailableModels(): array
    {
        try {
            $response = $this->httpClient->get('/v1beta/models', [
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            
            return array_map(function ($model) {
                return [
                    'id' => $model['name'],
                    'name' => $model['displayName'],
                    'description' => $model['description'] ?? '',
                ];
            }, $data['models'] ?? []);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'vision', 'embeddings', 'streaming'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::GEMINI;
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::GEMINI_1_5_FLASH;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \InvalidArgumentException('Gemini API key is required');
        }
    }

    /**
     * Build contents array for Gemini API
     */
    private function buildContents(AIRequest $request): array
    {
        $contents = [];

        // Add conversation history if provided
        if (!empty($request->messages)) {
            foreach ($request->messages as $message) {
                $role = $message['role'] === 'assistant' ? 'model' : 'user';
                $contents[] = [
                    'role' => $role,
                    'parts' => [['text' => $message['content']]]
                ];
            }
        }

        // Add the main prompt
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $request->prompt]]
        ];

        return $contents;
    }
}
