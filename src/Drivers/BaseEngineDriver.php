<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

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
    abstract protected function getDefaultModel(): EntityEnum;

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

    /**
     * Build standard messages array with conversation history
     *
     * This centralizes the logic for building messages across all drivers:
     * 1. Add system prompt if provided
     * 2. Add conversation history if provided
     * 3. Add current user message
     *
     * @param AIRequest $request
     * @param bool $includeSystemPrompt Whether to include system prompt (default: true)
     * @return array Standard message format: [['role' => 'user|assistant|system', 'content' => '...']]
     */
    protected function buildStandardMessages(AIRequest $request, bool $includeSystemPrompt = true): array
    {
        $messages = [];

        // Add system message if provided
        if ($includeSystemPrompt && $request->systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $request->systemPrompt,
            ];
        }

        // Add conversation history if provided (using getter method)
        $historyMessages = $request->getMessages();
        if (!empty($historyMessages)) {
            $messages = array_merge($messages, $historyMessages);
        }

        // Add the main prompt
        $messages[] = [
            'role' => 'user',
            'content' => $request->prompt,
        ];

        return $messages;
    }

    /**
     * Get conversation history from request
     *
     * Centralized method to safely get conversation history
     *
     * @param AIRequest $request
     * @return array
     */
    protected function getConversationHistory(AIRequest $request): array
    {
        return $request->getMessages();
    }

    /**
     * Handle API errors consistently across all drivers
     *
     * @param \Exception $exception
     * @param AIRequest $request
     * @param string $context Additional context (e.g., 'text generation', 'image generation')
     * @return AIResponse
     */
    protected function handleApiError(\Exception $exception, AIRequest $request, string $context = 'API request'): AIResponse
    {
        $errorMessage = $exception instanceof \GuzzleHttp\Exception\RequestException
            ? "{$this->getEngineEnum()->value} API error: {$exception->getMessage()}"
            : "Unexpected error during {$context}: {$exception->getMessage()}";

        \Log::error($errorMessage, [
            'engine' => $request->engine->value,
            'model' => $request->model->value,
            'context' => $context,
            'exception' => get_class($exception),
            'trace' => config('app.debug') ? $exception->getTraceAsString() : null,
        ]);

        return AIResponse::error(
            $errorMessage,
            $request->engine,
            $request->model
        );
    }

    /**
     * Safely test engine connection
     *
     * @param AIRequest $testRequest
     * @param callable $testCallback
     * @return bool
     */
    protected function safeConnectionTest(AIRequest $testRequest, callable $testCallback): bool
    {
        try {
            $response = $testCallback($testRequest);
            return $response instanceof AIResponse ? $response->isSuccess() : (bool) $response;
        } catch (\Exception $e) {
            \Log::warning("Connection test failed for {$this->getEngineEnum()->value}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Extract token usage from API response
     *
     * Handles different API response formats for token counting
     *
     * @param array $data API response data
     * @param string $content Fallback content for estimation
     * @param string $format API format ('openai', 'anthropic', 'gemini', etc.)
     * @return int
     */
    protected function extractTokenUsage(array $data, string $content, string $format = 'openai'): int
    {
        $tokens = match($format) {
            'openai', 'deepseek', 'perplexity' => $data['usage']['total_tokens'] ?? null,
            'anthropic' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            'gemini' => $data['usageMetadata']['totalTokenCount'] ?? null,
            default => null,
        };

        return $tokens ?? $this->calculateTokensUsed($content);
    }

    /**
     * Build standard request payload for chat completion
     *
     * @param AIRequest $request
     * @param array $messages
     * @param array $additionalParams
     * @return array
     */
    protected function buildChatPayload(AIRequest $request, array $messages, array $additionalParams = []): array
    {
        $model = $request->model->value;

        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        // Add function calling parameters if present
        if (!empty($request->getFunctions())) {
            $payload['functions'] = $request->getFunctions();
            
            if ($request->getFunctionCall() !== null) {
                $payload['function_call'] = $request->getFunctionCall();
            }
        }

        // GPT-5 family models have different parameter requirements
        if ($this->isGpt5FamilyModel($model)) {
            // GPT-5 uses max_completion_tokens and doesn't support temperature
            $payload['max_completion_tokens'] = $request->maxTokens;
            // Use reasoning_effort instead of temperature for GPT-5
            $payload['reasoning_effort'] = $this->mapTemperatureToReasoningEffort($request->temperature ?? 0.7);
        } elseif ($this->isReasoningModel($model)) {
            // o1, o3 models use max_completion_tokens
            $payload['max_completion_tokens'] = $request->maxTokens;
            $payload['temperature'] = 1; // Reasoning models only support temperature=1
        } else {
            // Standard models (GPT-4, GPT-3.5, etc.)
            $payload['max_tokens'] = $request->maxTokens;
            $payload['temperature'] = $request->temperature ?? 0.7;
        }

        return array_merge($payload, $additionalParams);
    }

    /**
     * Check if model is GPT-5 family (gpt-5, gpt-5-mini, gpt-5-nano, gpt-5.1, etc.)
     *
     * @param string $model
     * @return bool
     */
    protected function isGpt5FamilyModel(string $model): bool
    {
        return str_starts_with($model, 'gpt-5');
    }

    /**
     * Check if model is a reasoning model (o1, o3, etc.)
     *
     * @param string $model
     * @return bool
     */
    protected function isReasoningModel(string $model): bool
    {
        $reasoningPrefixes = ['o1', 'o3'];
        foreach ($reasoningPrefixes as $prefix) {
            if (str_starts_with($model, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Map temperature value to GPT-5 reasoning_effort
     * GPT-5 doesn't support temperature, uses reasoning_effort instead
     *
     * @param float $temperature
     * @return string
     */
    protected function mapTemperatureToReasoningEffort(float $temperature): string
    {
        // Map temperature (0-2) to reasoning effort levels
        // 'none' = fast, minimal reasoning (good for simple tasks)
        // 'low' = light reasoning
        // 'medium' = balanced
        // 'high' = deep reasoning
        if ($temperature <= 0.2) {
            return 'none';
        } elseif ($temperature <= 0.5) {
            return 'low';
        } elseif ($temperature <= 0.8) {
            return 'medium';
        } else {
            return 'high';
        }
    }

    /**
     * Log API request for debugging
     *
     * @param string $operation
     * @param AIRequest $request
     * @param array $additionalData
     * @return void
     */
    protected function logApiRequest(string $operation, AIRequest $request, array $additionalData = []): void
    {
        if (config('ai-engine.debug', false)) {
            \Log::debug("{$this->getEngineEnum()->value} API Request: {$operation}", array_merge([
                'engine' => $request->engine->value,
                'model' => $request->model->value,
                'prompt_length' => strlen($request->prompt),
                'has_history' => !empty($request->getMessages()),
                'history_count' => count($request->getMessages()),
            ], $additionalData));
        }
    }

    /**
     * Validate required files in request
     *
     * @param AIRequest $request
     * @param int $minFiles
     * @param int $maxFiles
     * @throws \InvalidArgumentException
     * @return void
     */
    protected function validateFiles(AIRequest $request, int $minFiles = 1, int $maxFiles = 1): void
    {
        $fileCount = count($request->files);

        if ($fileCount < $minFiles) {
            throw new \InvalidArgumentException("At least {$minFiles} file(s) required");
        }

        if ($fileCount > $maxFiles) {
            throw new \InvalidArgumentException("Maximum {$maxFiles} file(s) allowed");
        }
    }

    /**
     * Build successful AIResponse with common metadata
     *
     * @param string $content
     * @param AIRequest $request
     * @param array $apiResponse Raw API response data
     * @param string $format API format for token extraction ('openai', 'anthropic', 'gemini')
     * @return AIResponse
     */
    protected function buildSuccessResponse(
        string $content,
        AIRequest $request,
        array $apiResponse = [],
        string $format = 'openai'
    ): AIResponse {
        $tokensUsed = !empty($apiResponse)
            ? $this->extractTokenUsage($apiResponse, $content, $format)
            : $this->calculateTokensUsed($content);

        $response = AIResponse::success(
            $content,
            $request->engine,
            $request->model
        )->withUsage(
            tokensUsed: $tokensUsed,
            creditsUsed: $tokensUsed * $request->model->creditIndex()
        );

        // Add request ID if available
        if ($requestId = $this->extractRequestId($apiResponse, $format)) {
            $response = $response->withRequestId($requestId);
        }

        // Add finish reason if available
        if ($finishReason = $this->extractFinishReason($apiResponse, $format)) {
            $response = $response->withFinishReason($finishReason);
        }

        // Add detailed usage if available
        if ($detailedUsage = $this->extractDetailedUsage($apiResponse, $format)) {
            $response = $response->withDetailedUsage($detailedUsage);
        }

        return $response;
    }

    /**
     * Extract request ID from API response
     *
     * @param array $data
     * @param string $format
     * @return string|null
     */
    protected function extractRequestId(array $data, string $format = 'openai'): ?string
    {
        return match($format) {
            'openai', 'anthropic', 'deepseek', 'perplexity' => $data['id'] ?? null,
            'gemini' => $data['name'] ?? null,
            default => null,
        };
    }

    /**
     * Extract finish reason from API response
     *
     * @param array $data
     * @param string $format
     * @return string|null
     */
    protected function extractFinishReason(array $data, string $format = 'openai'): ?string
    {
        return match($format) {
            'openai', 'deepseek', 'perplexity' => $data['choices'][0]['finish_reason'] ?? null,
            'anthropic' => $data['stop_reason'] ?? null,
            'gemini' => $data['candidates'][0]['finishReason'] ?? null,
            default => null,
        };
    }

    /**
     * Extract detailed token usage from API response
     *
     * @param array $data
     * @param string $format
     * @return array|null
     */
    protected function extractDetailedUsage(array $data, string $format = 'openai'): ?array
    {
        $usage = match($format) {
            'openai', 'deepseek', 'perplexity' => $data['usage'] ?? null,
            'anthropic' => $data['usage'] ?? null,
            'gemini' => $data['usageMetadata'] ?? null,
            default => null,
        };

        if (!$usage) {
            return null;
        }

        // Normalize to common format
        return match($format) {
            'openai', 'deepseek', 'perplexity' => [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
            ],
            'anthropic' => [
                'prompt_tokens' => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ],
            'gemini' => [
                'prompt_tokens' => $usage['promptTokenCount'] ?? 0,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'total_tokens' => $usage['totalTokenCount'] ?? 0,
            ],
            default => null,
        };
    }

    /**
     * Create error response for unsupported operations
     *
     * @param string $operation
     * @param AIRequest $request
     * @return AIResponse
     */
    protected function unsupportedOperation(string $operation, AIRequest $request): AIResponse
    {
        return AIResponse::error(
            "{$operation} not supported by {$this->getEngineEnum()->value}",
            $request->engine,
            $request->model
        );
    }

    /**
     * Parse JSON response safely
     *
     * @param string $jsonString
     * @param bool $associative
     * @return array|object|null
     */
    protected function parseJsonResponse(string $jsonString, bool $associative = true): array|object|null
    {
        try {
            return json_decode($jsonString, $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            \Log::error("JSON parsing error: {$e->getMessage()}", [
                'json' => substr($jsonString, 0, 500),
            ]);
            return null;
        }
    }

    /**
     * Calculate credits used based on tokens and model
     *
     * @param int $tokens
     * @param EntityEnum $model
     * @return float
     */
    protected function calculateCredits(int $tokens, EntityEnum $model): float
    {
        return $tokens * $model->creditIndex();
    }

    /**
     * Merge metadata arrays safely
     *
     * @param array ...$metadataArrays
     * @return array
     */
    protected function mergeMetadata(array ...$metadataArrays): array
    {
        $merged = [];

        foreach ($metadataArrays as $metadata) {
            foreach ($metadata as $key => $value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    $merged[$key] = array_merge($merged[$key], $value);
                } else {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * Generate JSON analysis using the best approach for the model
     * Default implementation uses standard text generation with JSON instructions
     * Drivers can override this for model-specific optimizations (e.g., response_format)
     *
     * @param string $prompt The analysis prompt
     * @param string $systemPrompt System instructions
     * @param string|null $model Model to use (null = use default)
     * @param int $maxTokens Maximum tokens for response
     * @return string JSON response content
     */
    public function generateJsonAnalysis(
        string $prompt,
        string $systemPrompt,
        ?string $model = null,
        int $maxTokens = 300
    ): string {
        // Default implementation: use standard text generation
        // The prompt should already instruct the model to respond with JSON
        $request = new AIRequest(
            prompt: $prompt,
            engine: $this->getEngineEnum(),
            model: new EntityEnum($model ?? $this->getDefaultModel()->value),
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: 0.3
        );

        $response = $this->generateText($request);

        return $response->getContent() ?? '';
    }
}
