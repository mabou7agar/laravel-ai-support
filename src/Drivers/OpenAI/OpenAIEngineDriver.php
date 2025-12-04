<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\OpenAI;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use OpenAI;

class OpenAIEngineDriver extends BaseEngineDriver
{
    private Client $httpClient;
    private $openAIClient;

    public function __construct(array $config, Client $httpClient = null)
    {
        parent::__construct($config);
        
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl(),
        ]);

        // For testing, we can inject a custom HTTP client
        if ($httpClient) {
            $this->openAIClient = OpenAI::factory()
                ->withApiKey($this->getApiKey())
                ->withHttpClient($httpClient)
                ->make();
        } else {
            $this->openAIClient = OpenAI::client($this->getApiKey());
        }
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
            'audio' => $this->audioToText($request),
            'embeddings' => $this->generateEmbeddings($request),
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
        // Check if the prompt is empty
        if (empty($request->getPrompt())) {
            throw new AIEngineException('Prompt is required');
        }

        // Check if the model is supported by this engine
        $supportedModels = [
            EntityEnum::GPT_4O,
            EntityEnum::GPT_4O_MINI,
            EntityEnum::GPT_3_5_TURBO,
            EntityEnum::DALL_E_3,
            EntityEnum::DALL_E_2,
            EntityEnum::WHISPER_1,
        ];

        if (!in_array($request->getModel()->value, $supportedModels)) {
            throw new AIEngineException('Unsupported model: ' . $request->getModel()->value . ' for engine: ' . $this->getEngine()->value);
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::from(EngineEnum::OPENAI);
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
    public function testConnection(): bool
    {
        return $this->safeConnectionTest(
            AIRequest::create('test'),
            fn() => $this->openAIClient->models()->list()
        );
    }

    /**
     * Generate text content
     */
    public function generateText(AIRequest $request): AIResponse
    {
        try {
            $this->logApiRequest('generateText', $request);
            
            $messages = $this->buildMessages($request);
            $payload = $this->buildChatPayload($request, $messages, [
                'seed' => $request->seed,
            ]);
            
            $response = $this->openAIClient->chat()->create($payload);
            $content = $response->choices[0]->message->content;

            return $this->buildSuccessResponse(
                $content,
                $request,
                $response->toArray(),
                'openai'
            );

        } catch (\Exception $e) {
            return $this->handleApiError($e, $request, 'text generation');
        }
    }

    /**
     * Implementation-specific streaming text generation
     */
    protected function doGenerateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            // Build payload using the base method which handles model-specific parameters
            $payload = $this->buildChatPayload($request, $messages, [
                'seed' => $request->seed,
            ]);
            
            $stream = $this->openAIClient->chat()->createStreamed($payload);

            foreach ($stream as $response) {
                $content = $response->choices[0]->delta->content ?? '';
                if (!empty($content)) {
                    yield $content;
                }
            }

        } catch (\Exception $e) {
            throw new \RuntimeException('OpenAI streaming error: ' . $e->getMessage());
        }
    }

    /**
     * Generate images
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        try {
            $imageCount = $request->parameters['image_count'] ?? 1;
            $size = $request->parameters['size'] ?? '1024x1024';
            $quality = $request->parameters['quality'] ?? 'standard';

            $response = $this->openAIClient->images()->create([
                'model' => $request->model->value,
                'prompt' => $request->prompt,
                'n' => $imageCount,
                'size' => $size,
                'quality' => $quality,
            ]);

            $imageUrls = array_map(fn($image) => $image->url, $response->data);

            return AIResponse::success(
                $request->prompt,
                $request->engine,
                $request->model
            )->withFiles($imageUrls)
             ->withUsage(
                 creditsUsed: $imageCount * $request->model->creditIndex()
             );

        } catch (\Exception $e) {
            return AIResponse::error(
                'OpenAI image generation error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Implementation-specific audio to text
     */
    protected function doAudioToText(AIRequest $request): AIResponse
    {
        try {
            $audioFile = $request->files[0] ?? null;
            if (!$audioFile) {
                throw new \InvalidArgumentException('Audio file is required');
            }

            $response = $this->openAIClient->audio()->transcribe([
                'model' => 'whisper-1',
                'file' => fopen($audioFile, 'r'),
                'response_format' => 'json',
            ]);

            $duration = $request->parameters['audio_minutes'] ?? 1.0;

            return AIResponse::success(
                $response->text,
                $request->engine,
                $request->model
            )->withUsage(
                creditsUsed: $duration * $request->model->creditIndex()
            );

        } catch (\Exception $e) {
            return AIResponse::error(
                'OpenAI audio transcription error: ' . $e->getMessage(),
                $request->engine,
                $request->model
            );
        }
    }

    /**
     * Implementation-specific embeddings generation
     */
    protected function doGenerateEmbeddings(AIRequest $request): AIResponse
    {
        try {
            $response = $this->openAIClient->embeddings()->create([
                'model' => $request->model->value,
                'input' => $request->prompt,
            ]);

            $embeddings = $response->embeddings[0]->embedding;
            $tokensUsed = $response->usage->totalTokens ?? $this->calculateTokensUsed($request->prompt);

            return AIResponse::success(
                json_encode($embeddings),
                $request->engine,
                $request->model
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->model->creditIndex()
            )->withDetailedUsage([
                'embeddings' => $embeddings,
                'dimensions' => count($embeddings),
            ]);

        } catch (\Exception $e) {
            return AIResponse::error(
                'OpenAI embeddings error: ' . $e->getMessage(),
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
            $response = $this->openAIClient->models()->list();
            
            return array_map(function ($model) {
                return [
                    'id' => $model->id,
                    'object' => $model->object,
                    'created' => $model->created,
                    'owned_by' => $model->ownedBy,
                ];
            }, $response->data);

        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get supported capabilities for this engine
     */
    protected function getSupportedCapabilities(): array
    {
        return ['text', 'chat', 'images', 'audio', 'embeddings', 'vision', 'streaming', 'speech_to_text'];
    }

    /**
     * Get the engine enum
     */
    protected function getEngineEnum(): EngineEnum
    {
        return new EngineEnum(EngineEnum::OPENAI);
    }

    /**
     * Get the default model for this engine
     */
    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::GPT_4O_MINI;
    }

    /**
     * Validate the engine configuration
     */
    protected function validateConfig(): void
    {
        if (empty($this->config['api_key'])) {
            throw new AIEngineException('OpenAI API key is required');
        }
    }

    /**
     * Build messages array for chat completion
     */
    private function buildMessages(AIRequest $request): array
    {
        // Use centralized method from BaseEngineDriver
        return $this->buildStandardMessages($request);
    }
    
    /**
     * Generate JSON analysis using the best approach for the given model
     * Automatically selects between standard chat and JSON mode based on model type
     * 
     * @param string $prompt The analysis prompt
     * @param string $systemPrompt System instructions
     * @param string|null $model Model to use (null = use config default)
     * @param int $maxTokens Maximum tokens for response
     * @return string JSON response content
     */
    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string {
        $model = $model ?? config('ai-engine.engines.openai.model', 'gpt-4o');
        
        try {
            $payload = [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ];
            
            // GPT-5 and reasoning models: use JSON mode + appropriate parameters
            if ($this->isGpt5FamilyModel($model)) {
                $payload['response_format'] = ['type' => 'json_object'];
                // GPT-5 needs more tokens for reasoning before output
                $payload['max_completion_tokens'] = max($maxTokens, 1000);
                // GPT-5 with json_object format works better with low reasoning for fast analysis
                $payload['reasoning_effort'] = 'low';
            } elseif ($this->isReasoningModel($model)) {
                $payload['response_format'] = ['type' => 'json_object'];
                $payload['max_completion_tokens'] = $maxTokens;
                $payload['temperature'] = 1;
            } else {
                // Standard models (GPT-4o, etc.): JSON mode + standard params
                $payload['response_format'] = ['type' => 'json_object'];
                $payload['max_tokens'] = $maxTokens;
                $payload['temperature'] = 0.3;
            }
            
            \Log::channel('ai-engine')->debug('JSON analysis request', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
                'is_gpt5' => $this->isGpt5FamilyModel($model),
            ]);
            
            $response = $this->openAIClient->chat()->create($payload);
            
            // Debug: log full response structure for GPT-5 models
            if ($this->isGpt5FamilyModel($model)) {
                \Log::channel('ai-engine')->debug('GPT-5 raw response', [
                    'model' => $model,
                    'choices_count' => count($response->choices ?? []),
                    'first_choice' => isset($response->choices[0]) ? [
                        'finish_reason' => $response->choices[0]->finishReason ?? null,
                        'message_role' => $response->choices[0]->message->role ?? null,
                        'message_content' => substr($response->choices[0]->message->content ?? '', 0, 200),
                    ] : null,
                ]);
            }
            
            $content = $response->choices[0]->message->content ?? '';
            
            \Log::channel('ai-engine')->debug('JSON analysis response', [
                'model' => $model,
                'content_length' => strlen($content),
                'has_content' => !empty(trim($content)),
            ]);
            
            return trim($content);
            
        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('JSON analysis failed', [
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
            
            throw new \RuntimeException('JSON analysis error: ' . $e->getMessage(), 0, $e);
        }
    }
}
