<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Contracts\Foundation\Application;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Exceptions\EngineNotSupportedException;
use LaravelAIEngine\Exceptions\ModelNotSupportedException;
use LaravelAIEngine\Services\Memory\MemoryManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;

class AIEngineManager
{
    private array $drivers = [];

    public function __construct(
        private Application $app,
        private CreditManager $creditManager,
        private CacheManager $cacheManager,
        private RateLimitManager $rateLimitManager,
        private AnalyticsManager $analyticsManager,
        private ?MemoryManager $memoryManager = null,
        private ?ActionManager $actionManager = null,
        private ?WebSocketManager $webSocketManager = null
    ) {}

    /**
     * Create an engine builder
     */
    public function engine(string $engine): EngineBuilder
    {
        $engineEnum = EngineEnum::fromSlug($engine);

        return new EngineBuilder(
            $engineEnum,
            $this,
            $this->creditManager,
            $this->cacheManager,
            $this->rateLimitManager,
            $this->analyticsManager
        );
    }

    /**
     * Create a model builder
     */
    public function model(string $model): EngineBuilder
    {
        $entityEnum = EntityEnum::fromSlug($model);
        $engineEnum = $entityEnum->engine();

        return $this->engine($engineEnum->value)->model($model);
    }

    /**
     * Get available engines
     */
    public function getAvailableEngines(): array
    {
        return array_map(function (EngineEnum $engine) {
            return [
                'slug' => $engine->value,
                'label' => $engine->label(),
                'capabilities' => $engine->capabilities(),
                'enabled' => $this->isEngineEnabled($engine),
            ];
        }, EngineEnum::cases());
    }

    /**
     * Get available models for an engine
     */
    public function getAvailableModels(?string $engine = null): array
    {
        if ($engine) {
            $engineEnum = EngineEnum::fromSlug($engine);
            $models = EntityEnum::forEngine($engineEnum);
        } else {
            $models = EntityEnum::cases();
        }

        return array_map(function (EntityEnum $model) {
            return [
                'slug' => $model->value,
                'label' => $model->label(),
                'engine' => $model->engine()->value,
                'content_type' => $model->contentType(),
                'credit_index' => $model->creditIndex(),
                'calculation_method' => $model->calculationMethod(),
                'enabled' => $this->isModelEnabled($model),
            ];
        }, $models);
    }

    /**
     * Get or create engine driver
     */
    public function getEngineDriver(EngineEnum $engine): EngineDriverInterface
    {
        $driverKey = $engine->value;

        if (!isset($this->drivers[$driverKey])) {
            $driverClass = $engine->driverClass();

            if (!class_exists($driverClass)) {
                throw new EngineNotSupportedException("Engine driver {$driverClass} not found");
            }

            $config = $this->getEngineConfig($engine);
            $this->drivers[$driverKey] = new $driverClass($config);
        }

        return $this->drivers[$driverKey];
    }

    /**
     * Process AI request
     */
    public function processRequest(AIRequest $request): AIResponse
    {
        $startTime = microtime(true);

        try {
            // Check rate limits
            $this->rateLimitManager->checkRateLimit($request->engine, $request->userId);

            // Check credits (only on master node to avoid double-deduction)
            if ($this->shouldProcessCredits() && $request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
                throw new \LaravelAIEngine\Exceptions\InsufficientCreditsException();
            }

            // Check cache
            if ($cachedResponse = $this->cacheManager->get($request)) {
                $this->analyticsManager->recordCacheHit($request);
                return $cachedResponse->markAsCached();
            }

            // Get driver and process request
            $driver = $this->getEngineDriver($request->engine);

            $response = match ($request->getContentType()) {
                'text' => $driver->generateText($request),
                'image' => $driver->generateImage($request),
                'video' => $driver->generateVideo($request),
                'audio' => $driver->generateAudio($request),
                default => throw new \InvalidArgumentException("Unsupported content type: {$request->getContentType()}")
            };

            // Calculate latency
            $latency = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
            $response = $response->withUsage(latency: $latency);

            // Deduct credits (only on master node to avoid double-deduction)
            if ($this->shouldProcessCredits() && $request->userId && $response->isSuccess()) {
                $creditsUsed = $response->creditsUsed ?? $this->creditManager->calculateCredits($request);
                $this->creditManager->deductCredits($request->userId, $request, $creditsUsed);
                $response = $response->withUsage(creditsUsed: $creditsUsed);
            }

            // Cache response
            if ($response->isSuccess()) {
                $this->cacheManager->put($request, $response);
            }

            // Record analytics
            $this->analyticsManager->recordRequest($request, $response);

            return $response;

        } catch (\Exception $e) {
            $latency = (microtime(true) - $startTime) * 1000;

            $errorResponse = AIResponse::error(
                $e->getMessage(),
                $request->engine,
                $request->model
            )->withUsage(latency: $latency);

            $this->analyticsManager->recordError($request, $e);

            return $errorResponse;
        }
    }

    /**
     * Process streaming request
     */
    public function processStreamingRequest(AIRequest $request): \Generator
    {
        try {
            // Check rate limits and credits (same as regular request)
            $this->rateLimitManager->checkRateLimit($request->engine, $request->userId);

            if ($this->shouldProcessCredits() && $request->userId && !$this->creditManager->hasCredits($request->userId, $request)) {
                throw new \LaravelAIEngine\Exceptions\InsufficientCreditsException();
            }

            $driver = $this->getEngineDriver($request->engine);

            if (!$driver->supports('streaming')) {
                throw new \InvalidArgumentException("Engine {$request->engine->value} does not support streaming");
            }

            yield from $driver->generateTextStream($request);

        } catch (\Exception $e) {
            $this->analyticsManager->recordError($request, $e);
            throw $e;
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
     * Create batch processor
     */
    public function batch(): BatchProcessor
    {
        return new BatchProcessor($this);
    }

    /**
     * Create template manager
     */
    public function template(string $name): TemplateManager
    {
        return new TemplateManager($name, $this);
    }

    /**
     * Get analytics manager
     */
    public function analytics(): AnalyticsManager
    {
        return $this->analyticsManager;
    }

    /**
     * Estimate cost for operations
     */
    public function estimateCost(array $operations): array
    {
        $totalCredits = 0.0;
        $breakdown = [];

        foreach ($operations as $operation) {
            $engine = EngineEnum::fromSlug($operation['engine']);
            $model = EntityEnum::fromSlug($operation['model']);

            $request = AIRequest::make(
                $operation['prompt'] ?? '',
                $engine,
                $model,
                $operation['parameters'] ?? []
            );

            $credits = $this->creditManager->calculateCredits($request);
            $totalCredits += $credits;

            $breakdown[] = [
                'operation' => $operation,
                'credits' => $credits,
                'cost_breakdown' => [
                    'input_count' => $this->getInputCount($request),
                    'credit_index' => $model->creditIndex(),
                    'calculation_method' => $model->calculationMethod(),
                ],
            ];
        }

        return [
            'total_credits' => $totalCredits,
            'breakdown' => $breakdown,
            'currency' => config('ai-engine.credits.currency', 'credits'),
        ];
    }

    /**
     * Check if engine is enabled
     */
    private function isEngineEnabled(EngineEnum $engine): bool
    {
        $config = config("ai-engine.engines.{$engine->value}");
        return $config && !empty($config['api_key']);
    }

    /**
     * Check if model is enabled
     */
    private function isModelEnabled(EntityEnum $model): bool
    {
        $engineConfig = config("ai-engine.engines.{$model->engine()->value}");
        return $engineConfig &&
               isset($engineConfig['models'][$model->value]) &&
               ($engineConfig['models'][$model->value]['enabled'] ?? false);
    }

    /**
     * Get engine configuration
     */
    private function getEngineConfig(EngineEnum $engine): array
    {
        $config = config("ai-engine.engines.{$engine->value}", []);

        if (empty($config)) {
            throw new EngineNotSupportedException("Engine {$engine->value} is not configured");
        }

        return $config;
    }

    /**
     * Get input count for cost estimation
     */
    private function getInputCount(AIRequest $request): float
    {
        return match ($request->model->calculationMethod()) {
            'words' => str_word_count(strip_tags($request->prompt)),
            'characters' => mb_strlen(strip_tags($request->prompt)),
            'images' => $request->parameters['image_count'] ?? 1,
            'videos' => $request->parameters['video_count'] ?? 1,
            'minutes' => $request->parameters['audio_minutes'] ?? 1,
            default => 1,
        };
    }

    // ========================================
    // Methods migrated from UnifiedEngineManager
    // ========================================

    /**
     * Get memory manager for conversation history
     */
    public function memory(?string $driver = null): MemoryManager
    {
        if (!$this->memoryManager) {
            $this->memoryManager = $this->app->make(MemoryManager::class);
        }

        if ($driver) {
            $this->memoryManager->driver($driver);
        }

        return $this->memoryManager;
    }

    /**
     * Track a request for analytics
     */
    public function trackRequest(array $data): void
    {
        $this->analyticsManager->trackRequest($data);
    }

    /**
     * Track an action for analytics
     */
    public function trackAction(array $data): void
    {
        $this->analyticsManager->trackAction($data);
    }

    /**
     * Track streaming for analytics
     */
    public function trackStreaming(array $data): void
    {
        $this->analyticsManager->trackStreaming($data);
    }

    /**
     * Execute an interactive action
     */
    public function executeAction(InteractiveAction $action, array $payload = []): ActionResponse
    {
        if (!$this->actionManager) {
            $this->actionManager = $this->app->make(ActionManager::class);
        }

        return $this->actionManager->executeAction($action, $payload);
    }

    /**
     * Create an interactive action
     */
    public function createAction(array $data): InteractiveAction
    {
        return InteractiveAction::fromArray($data);
    }

    /**
     * Create multiple interactive actions
     */
    public function createActions(array $actionsData): array
    {
        return array_map(fn($data) => InteractiveAction::fromArray($data), $actionsData);
    }

    /**
     * Get supported action types
     */
    public function getSupportedActionTypes(): array
    {
        if (!$this->actionManager) {
            $this->actionManager = $this->app->make(ActionManager::class);
        }

        return $this->actionManager->getSupportedActionTypes();
    }

    /**
     * Validate an action
     */
    public function validateAction(InteractiveAction $action, array $payload = []): array
    {
        if (!$this->actionManager) {
            $this->actionManager = $this->app->make(ActionManager::class);
        }

        return $this->actionManager->validateAction($action, $payload);
    }

    /**
     * Stream response via WebSocket
     */
    public function streamResponse(string $sessionId, callable $generator, array $options = []): void
    {
        if (!$this->webSocketManager) {
            $this->webSocketManager = $this->app->bound(WebSocketManager::class)
                ? $this->app->make(WebSocketManager::class)
                : null;
        }

        if ($this->webSocketManager) {
            $this->webSocketManager->streamResponse($sessionId, $generator, $options);
        } else {
            // Fallback: just execute the generator
            foreach ($generator() as $chunk) {
                // Process chunks without WebSocket
            }
        }
    }

    /**
     * Stream response with actions
     */
    public function streamWithActions(string $sessionId, callable $generator, array $actions = [], array $options = []): void
    {
        if (!$this->webSocketManager) {
            $this->webSocketManager = $this->app->bound(WebSocketManager::class)
                ? $this->app->make(WebSocketManager::class)
                : null;
        }

        if ($this->webSocketManager) {
            $this->webSocketManager->streamWithActions($sessionId, $generator, $actions, $options);
        }
    }

    /**
     * Get streaming stats
     */
    public function getStreamingStats(): array
    {
        if (!$this->webSocketManager) {
            return [];
        }

        return $this->webSocketManager->getStats();
    }

    /**
     * Get dashboard data
     */
    public function getDashboardData(array $filters = []): array
    {
        return $this->analyticsManager->getDashboardData($filters);
    }

    /**
     * Get usage stats
     */
    public function getUsageStats(array $filters = []): array
    {
        return $this->analyticsManager->getUsageStats($filters);
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        return $this->analyticsManager->getPerformanceMetrics($filters);
    }

    /**
     * Get available engines (alias for getAvailableEngines)
     */
    public function getEngines(): array
    {
        return $this->getAvailableEngines();
    }

    /**
     * Get available models (alias for getAvailableModels)
     */
    public function getModels(?string $engine = null): array
    {
        return $this->getAvailableModels($engine);
    }

    /**
     * Create an AI request object
     */
    public function createRequest(
        string $prompt,
        ?string $engine = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?string $systemPrompt = null,
        array $parameters = [],
        ?string $userId = null
    ): AIRequest {
        $engineEnum = EngineEnum::fromSlug($engine ?? config('ai-engine.default_engine', 'openai'));
        $modelEnum = EntityEnum::fromSlug($model ?? config('ai-engine.default_model', 'gpt-4o'));

        $params = $parameters;
        if ($maxTokens !== null) {
            $params['max_tokens'] = $maxTokens;
        }
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }
        if ($systemPrompt !== null) {
            $params['system_prompt'] = $systemPrompt;
        }

        return new AIRequest(
            prompt: $prompt,
            engine: $engineEnum,
            model: $modelEnum,
            parameters: $params,
            userId: $userId
        );
    }
}
