<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;

class EngineBuilder
{
    private ?EntityEnum $model = null;
    private ?string $userId = null;
    private array $parameters = [];
    private array $context = [];
    private array $files = [];
    private bool $stream = false;
    private ?string $systemPrompt = null;
    private array $messages = [];
    private ?int $maxTokens = null;
    private ?float $temperature = null;
    private ?int $seed = null;
    private array $metadata = [];
    private bool $withRetry = false;
    private int $maxRetries = 3;
    private string $backoffStrategy = 'exponential';
    private ?EngineEnum $fallbackEngine = null;
    private bool $withCache = true;
    private ?int $cacheTtl = null;
    private bool $withRateLimit = true;
    private bool $withModeration = false;
    private string $moderationLevel = 'medium';

    public function __construct(
        private EngineEnum $engine,
        private AIEngineManager $manager,
        private CreditManager $creditManager,
        private CacheManager $cacheManager,
        private RateLimitManager $rateLimitManager,
        private AnalyticsManager $analyticsManager
    ) {}

    /**
     * Set the model
     */
    public function model(string $model): self
    {
        $this->model = EntityEnum::fromSlug($model);
        
        // Validate that model belongs to this engine
        // Compare values since EngineEnum is a class, not a native enum
        if ($this->model->engine()->value !== $this->engine->value) {
            throw new \InvalidArgumentException(
                "Model {$model} does not belong to engine {$this->engine->value}"
            );
        }

        return $this;
    }

    /**
     * Set the user
     */
    public function forUser(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Set parameters
     */
    public function withParameters(array $parameters): self
    {
        $this->parameters = array_merge($this->parameters, $parameters);
        return $this;
    }

    /**
     * Set context
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Add files
     */
    public function withFiles(array $files): self
    {
        $this->files = array_merge($this->files, $files);
        return $this;
    }

    /**
     * Enable streaming
     */
    public function stream(bool $stream = true): self
    {
        $this->stream = $stream;
        return $this;
    }

    /**
     * Set system prompt
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    /**
     * Set conversation messages
     */
    public function withMessages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * Set max tokens
     */
    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }

    /**
     * Set temperature
     */
    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    /**
     * Set seed for reproducible results
     */
    public function withSeed(int $seed): self
    {
        $this->seed = $seed;
        return $this;
    }

    /**
     * Add metadata
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata, $metadata);
        return $this;
    }

    /**
     * Enable retry mechanism
     */
    public function withRetry(int $maxAttempts = 3, string $backoff = 'exponential'): self
    {
        $this->withRetry = true;
        $this->maxRetries = $maxAttempts;
        $this->backoffStrategy = $backoff;
        return $this;
    }

    /**
     * Set fallback engine
     */
    public function fallbackTo(string $engine): self
    {
        $this->fallbackEngine = EngineEnum::fromSlug($engine);
        return $this;
    }

    /**
     * Configure caching
     */
    public function cache(bool $enabled = true, ?int $ttl = null): self
    {
        $this->withCache = $enabled;
        $this->cacheTtl = $ttl;
        return $this;
    }

    /**
     * Configure rate limiting
     */
    public function rateLimit(bool $enabled = true): self
    {
        $this->withRateLimit = $enabled;
        return $this;
    }

    /**
     * Enable content moderation
     */
    public function withModeration(string $level = 'medium'): self
    {
        $this->withModeration = true;
        $this->moderationLevel = $level;
        return $this;
    }

    /**
     * Generate content
     */
    public function generate(string $prompt): AIResponse
    {
        $request = $this->buildRequest($prompt);

        if ($this->withRetry) {
            return $this->executeWithRetry($request);
        }

        return $this->manager->processRequest($request);
    }

    /**
     * Generate streaming content
     */
    public function generateStream(string $prompt): \Generator
    {
        $request = $this->buildRequest($prompt)->withStreaming(true);
        yield from $this->manager->processStreamingRequest($request);
    }

    /**
     * Generate image
     */
    public function generateImage(string $prompt, int $count = 1): AIResponse
    {
        if (!$this->engine->supports('images')) {
            throw new \InvalidArgumentException("Engine {$this->engine->value} does not support image generation");
        }

        $this->parameters['image_count'] = $count;
        return $this->generate($prompt);
    }

    /**
     * Generate video
     */
    public function generateVideo(string $prompt, int $count = 1): AIResponse
    {
        if (!$this->engine->supports('video')) {
            throw new \InvalidArgumentException("Engine {$this->engine->value} does not support video generation");
        }

        $this->parameters['video_count'] = $count;
        return $this->generate($prompt);
    }

    /**
     * Generate audio
     */
    public function generateAudio(string $prompt, float $minutes = 1.0): AIResponse
    {
        if (!$this->engine->supports('audio')) {
            throw new \InvalidArgumentException("Engine {$this->engine->value} does not support audio generation");
        }

        $this->parameters['audio_minutes'] = $minutes;
        return $this->generate($prompt);
    }

    /**
     * Convert audio to text
     */
    public function audioToText(string $audioPath): AIResponse
    {
        if (!$this->engine->supports('audio')) {
            throw new \InvalidArgumentException("Engine {$this->engine->value} does not support audio processing");
        }

        $this->files[] = $audioPath;
        return $this->generate(''); // Empty prompt for audio-to-text
    }

    /**
     * Calculate cost estimate
     */
    public function estimateCost(string $prompt): array
    {
        $request = $this->buildRequest($prompt);
        $credits = $this->creditManager->calculateCredits($request);

        return [
            'credits' => $credits,
            'credit_index' => $request->model->creditIndex(),
            'calculation_method' => $request->model->calculationMethod(),
            'currency' => config('ai-engine.credits.currency', 'credits'),
        ];
    }

    /**
     * Check if user has sufficient credits
     */
    public function canAfford(string $prompt): bool
    {
        if (!$this->userId) {
            return true; // No user means no credit checking
        }

        $request = $this->buildRequest($prompt);
        return $this->creditManager->hasCredits($this->userId, $request);
    }

    /**
     * Build AI request
     */
    private function buildRequest(string $prompt): AIRequest
    {
        if (!$this->model) {
            // Use default model for engine
            $defaultModels = $this->engine->getDefaultModels();
            $this->model = $defaultModels[0] ?? throw new \InvalidArgumentException("No default model available for engine {$this->engine->value}");
        }

        return new AIRequest(
            prompt: $prompt,
            engine: $this->engine,
            model: $this->model,
            parameters: $this->parameters,
            userId: $this->userId,
            context: $this->context,
            files: $this->files,
            stream: $this->stream,
            systemPrompt: $this->systemPrompt,
            messages: $this->messages,
            maxTokens: $this->maxTokens,
            temperature: $this->temperature,
            seed: $this->seed,
            metadata: $this->metadata
        );
    }

    /**
     * Execute request with retry mechanism
     */
    private function executeWithRetry(AIRequest $request): AIResponse
    {
        $attempt = 1;
        $lastException = null;

        while ($attempt <= $this->maxRetries) {
            try {
                return $this->manager->processRequest($request);
            } catch (\Exception $e) {
                $lastException = $e;
                
                if ($attempt === $this->maxRetries) {
                    // Try fallback engine if available
                    if ($this->fallbackEngine && $this->fallbackEngine !== $this->engine) {
                        try {
                            $fallbackRequest = new AIRequest(
                                prompt: $request->prompt,
                                engine: $this->fallbackEngine,
                                model: $this->fallbackEngine->getDefaultModels()[0],
                                parameters: $request->parameters,
                                userId: $request->userId,
                                context: $request->context,
                                files: $request->files,
                                stream: $request->stream,
                                systemPrompt: $request->systemPrompt,
                                messages: $request->messages,
                                maxTokens: $request->maxTokens,
                                temperature: $request->temperature,
                                seed: $request->seed,
                                metadata: $request->metadata
                            );

                            return $this->manager->processRequest($fallbackRequest);
                        } catch (\Exception $fallbackException) {
                            // Fallback also failed, throw original exception
                            throw $lastException;
                        }
                    }
                    
                    throw $lastException;
                }

                // Calculate delay based on backoff strategy
                $delay = $this->calculateRetryDelay($attempt);
                usleep($delay * 1000); // Convert to microseconds
                
                $attempt++;
            }
        }

        throw $lastException;
    }

    /**
     * Calculate retry delay
     */
    private function calculateRetryDelay(int $attempt): int
    {
        return match ($this->backoffStrategy) {
            'linear' => $attempt * 1000, // 1s, 2s, 3s...
            'exponential' => (int) (pow(2, $attempt - 1) * 1000), // 1s, 2s, 4s, 8s...
            default => 1000, // 1 second default
        };
    }
}
