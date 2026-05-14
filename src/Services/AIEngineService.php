<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\Scope\AIScopeOptionsService;
use Illuminate\Support\Facades\Event;

class AIEngineService
{
    protected static ?string $globalUserIdResolver = null;

    public function __construct(
        protected CreditManager $creditManager,
        protected ?ConversationManager $conversationManager = null,
        protected ?Drivers\DriverRegistry $driverRegistry = null,
        protected ?RequestRouteResolver $requestRouteResolver = null,
        protected ?AIScopeOptionsService $scopeOptions = null
    ) {
        $this->conversationManager = $conversationManager ?? app(ConversationManager::class);
        $this->driverRegistry = $driverRegistry ?? app(Drivers\DriverRegistry::class);
        $this->requestRouteResolver = $requestRouteResolver ?? app(RequestRouteResolver::class);
        $this->scopeOptions = $scopeOptions ?? (app()->bound(AIScopeOptionsService::class)
            ? app(AIScopeOptionsService::class)
            : null);
    }

    /**
     * Set global user ID resolver at runtime
     */
    public static function setUserIdResolver(?string $resolverClass): void
    {
        static::$globalUserIdResolver = $resolverClass;
    }

    /**
     * Get the configured user ID resolver
     */
    protected function getUserIdResolverClass(): ?string
    {
        return static::$globalUserIdResolver ?? config('ai-engine.credits.user_id_resolver');
    }

    /**
     * Generate AI content using the specified request.
     */
    public function generate(AIRequest $request): AIResponse
    {
        return $this->generateInternal($request, true);
    }

    /**
     * Generate AI content without failover.
     * Useful for deterministic direct APIs where the caller selected engine/model explicitly.
     */
    public function generateDirect(AIRequest $request): AIResponse
    {
        return $this->generateInternal($request, false);
    }

    private function generateInternal(AIRequest $request, bool $withFailover): AIResponse
    {
        $startTime = microtime(true);
        $requestId = uniqid('ai_req_');
        $request = $this->requestRouteResolver->resolve($request);
        $originalEngine = $request->engine;

        // Auto-detect authenticated user if userId not provided
        // IMPORTANT: withUserId returns a NEW immutable request, so we must reassign
        if (!$request->userId && $this->isAuthenticatedSafely()) {
            $request = $request->withUserId($this->resolveUserId());
        }

        if ($this->scopeOptions instanceof AIScopeOptionsService) {
            $request = $request->withMetadata(
                $this->scopeOptions->merge($request->userId, $request->getMetadata())
            );
        }

        // Debug mode: Log prompt before sending
        $debugMode = config('ai-engine.debug', false) || ($request->metadata['debug'] ?? false);
        if ($debugMode) {
            \Log::channel('ai-engine')->info('🔍 AI Request Debug', [
                'request_id' => $requestId,
                'engine' => $request->engine->value,
                'model' => $request->model->value,
                'prompt_length' => strlen($request->prompt),
                'prompt' => $request->prompt,
                'system_prompt' => $request->systemPrompt ?? null,
                'temperature' => $request->temperature,
                'max_tokens' => $request->maxTokens,
                'user_id' => $request->userId,
            ]);
        }

        // Check credits before processing (if enabled)
        // Credits are only managed on the master node to avoid double-deduction
        $creditsEnabled = config('ai-engine.credits.enabled', false) && $this->shouldProcessCredits();

        if ($creditsEnabled && $request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }

        // Dispatch request started event
        Event::dispatch(new AIRequestStarted(
            request: $request,
            requestId: $requestId,
            metadata: $request->metadata
        ));

        $response = $withFailover
            ? $this->generateWithFailover($request, $requestId, $debugMode)
            : $this->generateSingleEngine($request);

        // Calculate credits for this request (always, for accumulation)
        $creditsUsed = $this->creditManager->calculateCredits($request);

        // Always accumulate credits (for cross-node tracking)
        CreditManager::accumulate($creditsUsed);

        // Deduct credits if enabled on this node (master only)
        if ($creditsEnabled && $response->success && $request->userId) {
            $this->creditManager->deductCredits($request->userId, $request, $creditsUsed);
        }

        // Add credits to response for tracking
        if ($response->success) {
            $response = $response->withUsage(
                tokensUsed: $response->tokensUsed,
                creditsUsed: $creditsUsed
            );
        }

        $processingTime = microtime(true) - $startTime;

        // Debug mode: Log response and timing
        if ($debugMode) {
            \Log::channel('ai-engine')->info('✅ AI Response Debug', [
                'request_id' => $requestId,
                'execution_time' => round($processingTime, 3) . 's',
                'response_length' => strlen($response->getContent()),
                'response_preview' => substr($response->getContent(), 0, 200),
                'success' => $response->success,
                'tokens_used' => $response->metadata['usage'] ?? null,
                'failover_used' => $withFailover && $response->engine !== $originalEngine,
            ]);
        }

        // Dispatch request completed event
        Event::dispatch(new AIRequestCompleted(
            request: $request,
            response: $response,
            requestId: $requestId,
            executionTime: $processingTime,
            metadata: array_merge($request->metadata, $response->metadata)
        ));

        return $response;
    }

    /**
     * Generate response against one selected engine/model (no failover attempts).
     */
    protected function generateSingleEngine(AIRequest $request): AIResponse
    {
        $driver = $this->getDriver($request->engine);
        $driver->validateRequest($request);

        return $driver->generate($request);
    }

    /**
     * Generate AI content with automatic failover to alternative engines
     */
    protected function generateWithFailover(AIRequest $request, string $requestId, bool $debugMode): AIResponse
    {
        $originalEngine = $request->engine;
        $lastException = null;
        $attemptedEngines = [];

        // Get failover engines from config
        $fallbackEngines = config("ai-engine.error_handling.fallback_engines.{$originalEngine->value}", []);
        $enginesToTry = array_merge([$originalEngine->value], $fallbackEngines);

        foreach ($enginesToTry as $engineName) {
            try {
                $engine = EngineEnum::from($engineName);
                $attemptedEngines[] = $engineName;

                // Update request with new engine if different from original
                if ($engine !== $originalEngine) {
                    if ($debugMode) {
                        \Log::channel('ai-engine')->info('🔄 Attempting failover', [
                            'request_id' => $requestId,
                            'from_engine' => $originalEngine->value,
                            'to_engine' => $engine->value,
                        ]);
                    }

                    // Create new request with failover engine
                    $request = new AIRequest(
                        prompt:       $request->prompt,
                        engine:       $engine,
                        model:        $request->model,
                        parameters:   $request->parameters,
                        userId:       $request->userId,
                        systemPrompt: $request->systemPrompt,
                        maxTokens:    $request->maxTokens,
                        temperature:  $request->temperature,
                        metadata:     array_merge($request->metadata, [
                            'failover_from' => $originalEngine->value,
                            'failover_to' => $engine->value,
                        ])
                    );
                }

                // Get the appropriate driver
                $driver = $this->getDriver($request->engine);

                // Validate the request
                $driver->validateRequest($request);

                // Generate the response
                $response = $driver->generate($request);

                // If successful, return the response
                if ($response->success) {
                    if ($engine !== $originalEngine && $debugMode) {
                        \Log::channel('ai-engine')->info('✅ Failover successful', [
                            'request_id' => $requestId,
                            'original_engine' => $originalEngine->value,
                            'successful_engine' => $engine->value,
                        ]);
                    }
                    return $response;
                }

                // If response indicates failure, try next engine
                $lastException = new \Exception($response->error ?? 'Unknown error');

            } catch (InsufficientCreditsException $e) {
                // Re-throw InsufficientCreditsException immediately - don't failover
                throw $e;

            } catch (\Exception $e) {
                $lastException = $e;

                if ($debugMode) {
                    \Log::channel('ai-engine')->warning('❌ Engine failed', [
                        'request_id' => $requestId,
                        'engine' => $engineName,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Continue to next engine
                continue;
            }
        }

        // All engines failed, return error response
        $errorMessage = $lastException ? $lastException->getMessage() : 'All engines failed';

        if ($debugMode) {
            \Log::channel('ai-engine')->error('❌ All failover attempts exhausted', [
                'request_id' => $requestId,
                'attempted_engines' => $attemptedEngines,
                'error' => $errorMessage,
            ]);
        }

        return new AIResponse(
            content:  '',
            engine:   $originalEngine,
            model:    $request->model,
            metadata: [
                'attempted_engines' => $attemptedEngines,
                'failover_exhausted' => true,
            ],
            error:    $errorMessage,
            success:  false
        );
    }

    /**
     * Generate AI content with conversation context.
     */
    public function generateWithConversation(
        string $message,
        string $conversationId,
        EngineEnum|string $engine,
        EntityEnum|string $model,
        ?string $userId = null,
        array $parameters = []
    ): AIResponse {
        if (!$engine instanceof EngineEnum) {
            $engine = EngineEnum::fromSlug($engine);
        }
        if (!$model instanceof EntityEnum) {
            $model = EntityEnum::from($model);
        }

        // Add user message to conversation
        $this->conversationManager->addUserMessage($conversationId, $message);

        // Create base request
        $baseRequest = new AIRequest(
            prompt: $message,
            engine: $engine,
            model: $model,
            parameters: $parameters,
            userId: $userId,
            metadata: ['conversation_id' => $conversationId]
        );

        // Enhance request with conversation context
        $contextualRequest = $this->conversationManager->enhanceRequestWithContext(
            $baseRequest,
            $conversationId
        );

        // Generate response
        $response = $this->generate($contextualRequest);

        // Add assistant message to conversation if successful
        if ($response->success) {
            $this->conversationManager->addAssistantMessage(
                $conversationId,
                $response->getContent(),
                $response
            );
        }

        return $response;
    }

    /**
     * Stream AI content generation.
     */
    public function stream(AIRequest $request): \Generator
    {
        $request = $this->requestRouteResolver->resolve($request);
        $originalEngine = $request->engine;

        // Auto-detect authenticated user if userId not provided
        if (!$request->userId && $this->isAuthenticatedSafely()) {
            $request = $request->withUserId($this->resolveUserId());
        }

        // Check credits before processing (if enabled)
        // Credits are only managed on the master node to avoid double-deduction
        $creditsEnabled = config('ai-engine.credits.enabled', false) && $this->shouldProcessCredits();
        if ($creditsEnabled && $request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
        }

        // Get failover engines from config
        $fallbackEngines = config("ai-engine.error_handling.fallback_engines.{$originalEngine->value}", []);
        $enginesToTry = array_merge([$originalEngine->value], $fallbackEngines);
        $lastException = null;

        foreach ($enginesToTry as $engineName) {
            try {
                $engine = EngineEnum::from($engineName);

                // Update request with new engine if different from original
                if ($engine !== $originalEngine) {
                    $request = new AIRequest(
                        prompt: $request->prompt,
                        engine: $engine,
                        model: $request->model,
                        systemPrompt: $request->systemPrompt,
                        temperature: $request->temperature,
                        maxTokens: $request->maxTokens,
                        userId: $request->userId,
                        parameters: $request->parameters,
                        metadata: array_merge($request->metadata, [
                            'failover_from' => $originalEngine->value,
                            'failover_to' => $engine->value,
                        ])
                    );
                }

                // Get the appropriate driver
                $driver = $this->getDriver($request->engine);

                // Validate the request
                $driver->validateRequest($request);

                // Stream the response
                yield from $driver->stream($request);

                // Deduct credits after streaming (if enabled)
                if ($creditsEnabled && $request->userId) {
                    $this->creditManager->deductCredits($request->userId, $request);
                }

                // If we got here, streaming was successful
                return;

            } catch (InsufficientCreditsException $e) {
                // Re-throw InsufficientCreditsException immediately - don't failover
                throw $e;

            } catch (\Exception $e) {
                $lastException = $e;
                // Continue to next engine
                continue;
            }
        }

        // All engines failed
        if ($lastException) {
            throw $lastException;
        }

        throw new \Exception('All failover engines failed for streaming request');
    }

    /**
     * Check if credits should be processed on this node.
     * Credits are only managed on the master node to avoid double-deduction
     * when requests are forwarded to child nodes.
     */
    protected function shouldProcessCredits(): bool
    {
        // If nodes are not enabled, always process credits
        if (!config('ai-engine.nodes.enabled', false)) {
            return true;
        }

        // Only process credits on the master node
        return config('ai-engine.nodes.is_master', true);
    }

    /**
     * Get the appropriate engine driver.
     */
    protected function getDriver(EngineEnum $engine): EngineDriverInterface
    {
        if ($this->driverRegistry) {
            return $this->driverRegistry->resolve($engine);
        }

        // Direct driver resolution for package contexts without an injected registry.
        $driverClass = $engine->driverClass();

        if (!class_exists($driverClass)) {
            throw new AIEngineException("Driver class {$driverClass} not found for engine {$engine->value}");
        }

        $config = config("ai-engine.engines.{$engine->value}", []);

        return new $driverClass($config);
    }

    /**
     * Get available engines.
     */
    public function getAvailableEngines(): array
    {
        return array_map(fn($engine) => [
            'name' => $engine->value,
            'capabilities' => $engine->capabilities(),
            'models' => $engine->getDefaultModels(),
        ], EngineEnum::cases());
    }

    /**
     * Test an engine with a simple request.
     */
    public function testEngine(EngineEnum $engine, string $prompt = 'Hello, world!'): AIResponse
    {
        $defaultModels = $engine->getDefaultModels();
        if (empty($defaultModels)) {
            throw new AIEngineException("No default models available for engine {$engine->value}");
        }

        $model = array_key_first($defaultModels);
        $entityEnum = \LaravelAIEngine\Enums\EntityEnum::from($model);

        $request = new AIRequest(
            prompt: $prompt,
            engine: $engine,
            model: $entityEnum
        );

        return $this->generate($request);
    }

    /**
     * Generate text content using the specified request.
     * This is a convenience method for text-specific generation.
     */
    public function generateText(AIRequest $request): AIResponse
    {
        // Ensure the request is for text generation
        if ($request->model->getContentType() !== 'text') {
            throw new AIEngineException('The specified model is not suitable for text generation');
        }

        return $this->generate($request);
    }

    /**
     * Generate image content using the specified request.
     */
    public function generateImage(AIRequest $request): AIResponse
    {
        if ($request->model->getContentType() !== 'image') {
            throw new AIEngineException('The specified model is not suitable for image generation');
        }

        return $this->generate($request);
    }

    /**
     * Generate video content using the specified request.
     */
    public function generateVideo(AIRequest $request): AIResponse
    {
        if ($request->model->getContentType() !== 'video') {
            throw new AIEngineException('The specified model is not suitable for video generation');
        }

        return $this->generate($request);
    }

    /**
     * Generate embeddings using the specified request.
     */
    public function generateEmbeddings(AIRequest $request): AIResponse
    {
        if ($request->model->getContentType() !== 'embeddings') {
            throw new AIEngineException('The specified model is not suitable for embeddings generation');
        }

        return $this->generate($request);
    }

    /**
     * Generate audio content using the specified request.
     */
    public function generateAudio(AIRequest $request): AIResponse
    {
        if ($request->model->getContentType() !== 'audio') {
            throw new AIEngineException('The specified model is not suitable for audio generation');
        }

        return $this->generate($request);
    }

    /**
     * Resolve the user ID for credit management
     * Supports custom resolvers via config for multi-tenant applications
     */
    protected function resolveUserId(): string
    {
        $resolverClass = $this->getUserIdResolverClass();

        // If custom resolver is configured, use it
        if ($resolverClass && class_exists($resolverClass)) {
            try {
                $resolver = app($resolverClass);

                // Support callable resolvers (with __invoke method)
                if (is_callable($resolver)) {
                    $userId = $resolver();
                    if ($userId) {
                        return (string) $userId;
                    }
                }

                // Support resolvers with resolve() method
                if (method_exists($resolver, 'resolve')) {
                    $userId = $resolver->resolve();
                    if ($userId) {
                        return (string) $userId;
                    }
                }

                // Support static resolveUserId() method
                if (method_exists($resolver, 'resolveUserId')) {
                    $userId = $resolver::resolveUserId();
                    if ($userId) {
                        return (string) $userId;
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('Failed to resolve user ID with custom resolver', [
                    'resolver' => $resolverClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback to default auth()->id() (safe on optional-auth routes)
        return $this->currentAuthUserId() ?? '';
    }

    protected function isAuthenticatedSafely(): bool
    {
        try {
            return auth()->check();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function currentAuthUserId(): ?string
    {
        try {
            $id = auth()->id();
            return $id !== null ? (string) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
