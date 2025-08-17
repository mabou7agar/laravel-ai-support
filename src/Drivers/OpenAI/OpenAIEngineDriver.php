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
        if (empty($request->prompt)) {
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

        if (!in_array($request->model, $supportedModels)) {
            throw new AIEngineException('Unsupported model: ' . $request->model->value . ' for engine: ' . $this->getEngine()->value);
        }

        return true;
    }

    /**
     * Get the engine this driver handles
     */
    public function getEngine(): EngineEnum
    {
        return EngineEnum::OPENAI;
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
            $this->openAIClient->models()->list();
            return true;
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
            
            $response = $this->openAIClient->chat()->create([
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature ?? 0.7,
                'seed' => $request->seed,
            ]);

            $content = $response->choices[0]->message->content;
            $tokensUsed = $response->usage->totalTokens ?? $this->calculateTokensUsed($content);

            return AIResponse::success(
                $content,
                $request->engine,
                $request->model
            )->withUsage(
                tokensUsed: $tokensUsed,
                creditsUsed: $tokensUsed * $request->model->creditIndex()
            )->withRequestId($response->id ?? null)
             ->withFinishReason($response->choices[0]->finishReason ?? null);

        } catch (RequestException $e) {
            return AIResponse::error(
                'OpenAI API error: ' . $e->getMessage(),
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
     * Implementation-specific streaming text generation
     */
    protected function doGenerateTextStream(AIRequest $request): \Generator
    {
        try {
            $messages = $this->buildMessages($request);
            
            $stream = $this->openAIClient->chat()->createStreamed([
                'model' => $request->model->value,
                'messages' => $messages,
                'max_tokens' => $request->maxTokens,
                'temperature' => $request->temperature ?? 0.7,
                'seed' => $request->seed,
            ]);

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
        return EngineEnum::OPENAI;
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
        $messages = [];

        // Add system message if provided
        if ($request->systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }

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
