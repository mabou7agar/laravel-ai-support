<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Gemini;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\Drivers\Concerns\BuildsMediaResponses;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\SDK\ProviderToolPayloadMapper;

class GeminiEngineDriver extends BaseEngineDriver
{
    use BuildsMediaResponses;

    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
        ]);
    }

    /**
     * Generate content using the AI engine
     */
    public function generate(AIRequest $request): AIResponse
    {
        $contentType = $request->getModel()->getContentType();
        
        return match ($contentType) {
            'text' => $this->generateText($request),
            'image' => $this->generateImage($request),
            'video' => $this->generateVideo($request),
            'audio' => $this->generateAudio($request),
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
        
        if (!$this->supports($request->getModel()->getContentType())) {
            return false;
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::GEMINI);
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
            return $response->isSuccessful();
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
            $this->logApiRequest('generateText', $request);
            
            $contents = $this->buildContents($request);
            
            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $request->getTemperature() ?? 0.7,
                    'maxOutputTokens' => $request->getMaxTokens() ?? 2048,
                ],
            ];

            if ($request->getSystemPrompt()) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $request->getSystemPrompt()]]
                ];
            }

            $this->applyToolPayload($payload, $request);
            $this->applyGeminiStructuredOutputPayload($payload, $request);

            $url = "/v1beta/models/{$request->getModel()->value}:generateContent";
            $response = $this->httpClient->post($url, [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return $this->buildSuccessResponse(
                $content,
                $request,
                $data,
                'gemini'
            );

        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'text generation');
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
                    'temperature' => $request->getTemperature() ?? 0.7,
                    'maxOutputTokens' => $request->getMaxTokens() ?? 2048,
                ],
            ];

            if ($request->getSystemPrompt()) {
                $payload['systemInstruction'] = [
                    'parts' => [['text' => $request->getSystemPrompt()]]
                ];
            }

            $this->applyToolPayload($payload, $request);
            $this->applyGeminiStructuredOutputPayload($payload, $request);

            $url = "/v1beta/models/{$request->getModel()->value}:streamGenerateContent";
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

    public function generateImage(AIRequest $request): AIResponse
    {
        try {
            $parameters = $request->getParameters();
            $payload = [
                'instances' => [
                    ['prompt' => $request->getPrompt()],
                ],
                'parameters' => array_filter([
                    'sampleCount' => $parameters['sample_count'] ?? $parameters['n'] ?? 1,
                    'aspectRatio' => $parameters['aspect_ratio'] ?? null,
                    'negativePrompt' => $parameters['negative_prompt'] ?? null,
                ], static fn ($value): bool => $value !== null),
            ];

            $response = $this->httpClient->post("/v1beta/models/{$request->getModel()->value}:predict", [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $files = [];
            foreach ((array) ($data['predictions'] ?? []) as $prediction) {
                $base64 = $prediction['bytesBase64Encoded']
                    ?? $prediction['image']['bytesBase64Encoded']
                    ?? $prediction['imageBytes']
                    ?? null;

                if (is_string($base64) && $base64 !== '') {
                    $files[] = $this->storeMediaBytes(base64_decode($base64, true) ?: $base64, $request, 'png');
                }
            }

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => 'gemini',
                'model' => $request->getModel()->value,
                'raw' => $data,
            ])->withFiles($files)->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'image generation');
        }
    }

    public function generateVideo(AIRequest $request): AIResponse
    {
        try {
            $payload = [
                'instances' => [
                    array_filter([
                        'prompt' => $request->getPrompt(),
                        'image' => $request->getParameters()['image'] ?? $request->getParameters()['image_url'] ?? null,
                    ], static fn ($value): bool => $value !== null && $value !== ''),
                ],
                'parameters' => array_diff_key($request->getParameters(), ['image' => true, 'image_url' => true]),
            ];

            $response = $this->httpClient->post("/v1beta/models/{$request->getModel()->value}:predictLongRunning", [
                'json' => $payload,
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $files = $this->normalizeOutputFiles($data['response']['videos'] ?? $data['predictions'] ?? $data);

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => 'gemini',
                'model' => $request->getModel()->value,
                'operation' => $data['name'] ?? null,
                'status' => isset($data['name']) && $files === [] ? 'submitted' : 'succeeded',
                'raw' => $data,
            ])->withFiles($files)->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'video generation');
        }
    }

    public function generateAudio(AIRequest $request): AIResponse
    {
        try {
            $response = $this->httpClient->post("/v1beta/models/{$request->getModel()->value}:predict", [
                'json' => [
                    'instances' => [['prompt' => $request->getPrompt()]],
                    'parameters' => $request->getParameters(),
                ],
                'query' => ['key' => $this->getApiKey()],
            ]);

            $data = $this->parseJsonResponse($response->getBody()->getContents());
            $files = $this->normalizeOutputFiles($data['predictions'] ?? $data);

            return AIResponse::success('', $request->getEngine(), $request->getModel(), [
                'provider' => 'gemini',
                'model' => $request->getModel()->value,
                'raw' => $data,
            ])->withFiles($files)->withUsage(creditsUsed: max(1, count($files)) * $request->getModel()->creditIndex());
        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'audio generation');
        }
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
                    'parts' => [['text' => $request->getPrompt()]]
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
                $request->getEngine(),
                $request->getModel()
            )->withDetailedUsage([
                'embeddings' => $embeddings,
                'dimensions' => count($embeddings),
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'Gemini embeddings error: ' . $e->getMessage(),
                $request->getEngine(),
                $request->getModel()
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
        return ['text', 'chat', 'vision', 'embeddings', 'streaming', 'image', 'images', 'video', 'audio'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::GEMINI);
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return new EntityEnum(EntityEnum::GEMINI_1_5_FLASH);
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

        // Get conversation history using centralized method
        $historyMessages = $this->getConversationHistory($request);
        
        // Add conversation history if provided
        if (!empty($historyMessages)) {
            foreach ($historyMessages as $message) {
                // Skip system messages (Gemini handles them separately via systemInstruction)
                if (($message['role'] ?? '') === 'system') {
                    continue;
                }
                
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
            'parts' => [['text' => $request->getPrompt()]]
        ];

        return $contents;
    }

    private function applyToolPayload(array &$payload, AIRequest $request): void
    {
        if (empty($request->getFunctions())) {
            return;
        }

        $split = app(ProviderToolPayloadMapper::class)->splitForProvider(
            EngineEnum::GEMINI,
            $request->getFunctions()
        );

        if (!empty($split['tools'])) {
            $payload['tools'] = $split['tools'];
        }

        if (!empty($split['functions'])) {
            $payload['tools'] = array_merge($payload['tools'] ?? [], [[
                'functionDeclarations' => array_values(array_map(
                    fn (array $function): array => $this->mapFunctionDeclaration($function),
                    $split['functions']
                )),
            ]]);
        }

        if (!empty($split['tool_config'])) {
            $payload = array_replace_recursive($payload, $split['tool_config']);
        }
    }

    private function mapFunctionDeclaration(array $function): array
    {
        return [
            'name' => (string) ($function['name'] ?? 'tool'),
            'description' => (string) ($function['description'] ?? ''),
            'parameters' => (array) ($function['parameters'] ?? [
                'type' => 'object',
                'properties' => (object) [],
            ]),
        ];
    }

    private function applyGeminiStructuredOutputPayload(array &$payload, AIRequest $request): void
    {
        $metadata = $request->getMetadata();
        $definition = $metadata['structured_output'] ?? null;

        if (!is_array($definition) || !is_array($definition['schema'] ?? null)) {
            return;
        }

        $payload['generationConfig'] = array_merge($payload['generationConfig'] ?? [], [
            'responseMimeType' => 'application/json',
            'responseSchema' => $definition['schema'],
        ]);
    }
}
