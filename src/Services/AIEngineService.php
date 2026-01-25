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
use Illuminate\Support\Facades\Event;

class AIEngineService
{
    protected static ?string $globalUserIdResolver = null;

    public function __construct(
        protected CreditManager $creditManager,
        protected ?ConversationManager $conversationManager = null,
        protected ?Drivers\DriverRegistry $driverRegistry = null
    ) {
        $this->conversationManager = $conversationManager ?? app(ConversationManager::class);
        $this->driverRegistry = $driverRegistry ?? app(Drivers\DriverRegistry::class);
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
        $startTime = microtime(true);
        $requestId = uniqid('ai_req_');

        // Auto-detect authenticated user if userId not provided
        // IMPORTANT: withUserId returns a NEW immutable request, so we must reassign
        if (!$request->userId && auth()->check()) {
            $request = $request->withUserId($this->resolveUserId());
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

        try {
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

            // Get the appropriate driver
            $driver = $this->getDriver($request->engine);

            // Validate the request
            $driver->validateRequest($request);

            // Generate the response
            $response = $driver->generate($request);

            // Calculate and deduct credits if successful (if enabled)
            $creditsUsed = 0;
            if ($creditsEnabled && $response->success && $request->userId) {
                $creditsUsed = $this->creditManager->calculateCredits($request);
                $this->creditManager->deductCredits($request->userId, $request, $creditsUsed);

                // Add credits to response for tracking
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

        } catch (InsufficientCreditsException $e) {
            // Re-throw InsufficientCreditsException so caller can handle it
            throw $e;

        } catch (\Exception $e) {
            $processingTime = microtime(true) - $startTime;

            // Create error response
            $errorResponse = new AIResponse(
                content: '',
                engine: $request->engine,
                model: $request->model,
                metadata: [],
                success: false,
                error: $e->getMessage()
            );

            // Dispatch error event
            Event::dispatch(new AIRequestCompleted(
                request: $request,
                response: $errorResponse,
                requestId: $requestId,
                executionTime: $processingTime,
                metadata: $request->metadata
            ));

            return new AIResponse(
                content: '',
                engine: $request->engine,
                model: $request->model,
                metadata: [],
                success: false,
                error: $e->getMessage()
            );
        }
    }

    /**
     * Generate AI content with conversation context.
     */
    public function generateWithConversation(
        string $message,
        string $conversationId,
        EngineEnum $engine,
        EntityEnum $model,
        ?string $userId = null,
        array $parameters = []
    ): AIResponse {
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
        // Auto-detect authenticated user if userId not provided
        if (!$request->userId && auth()->check()) {
            $request = $request->withUserId($this->resolveUserId());
        }

        // Check credits before processing (if enabled)
        // Credits are only managed on the master node to avoid double-deduction
        $creditsEnabled = config('ai-engine.credits.enabled', false) && $this->shouldProcessCredits();
        if ($creditsEnabled && $request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
            throw new InsufficientCreditsException('Insufficient credits for this request');
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

        // Fallback for backward compatibility if registry fails or not present (legacy logic)
        $driverClass = $engine->driverClass();

        if (!class_exists($driverClass)) {
            throw new AIEngineException("Driver class {$driverClass} not found for engine {$engine->value}");
        }

        $config = config("ai-engine.engines.{$engine->value}", []);

        if (
            $driverClass === \LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver::class ||
            $driverClass === \LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver::class
        ) {
            try {
                $httpClient = app(\GuzzleHttp\Client::class);
                return new $driverClass($config, $httpClient);
            } catch (\Exception $e) {
                return new $driverClass($config);
            }
        }

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

        // Fallback to default auth()->id()
        return (string) auth()->id();
    }
}
