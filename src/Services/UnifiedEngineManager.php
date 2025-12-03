<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Memory\MemoryManager;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager;

/**
 * Unified Engine Manager providing simple API similar to Bupple's approach
 */
class UnifiedEngineManager
{
    public function __construct(
        protected AIEngineService $aiEngineService,
        protected MemoryManager $memoryManager,
        protected ?ActionManager $actionManager = null,
        protected ?FailoverManager $failoverManager = null,
        protected ?WebSocketManager $streamingManager = null,
        protected ?AnalyticsManager $analyticsManager = null
    ) {}

    /**
     * Get engine proxy for fluent API
     */
    public function engine(?string $engine = null): EngineProxy
    {
        return new EngineProxy($this->aiEngineService, $engine);
    }

    /**
     * Get memory proxy for fluent API
     */
    public function memory(?string $driver = null): MemoryProxy
    {
        return new MemoryProxy($this->memoryManager, $driver);
    }

    /**
     * Send messages directly with default engine
     */
    public function send(array $messages, array $options = []): AIResponse
    {
        $engine = EngineEnum::from($options['engine'] ?? config('ai-engine.default_engine', 'openai'));
        $model = EntityEnum::from($options['model'] ?? config('ai-engine.default_model', 'gpt-4o'));

        $request = new AIRequest(
            prompt: $this->formatMessages($messages),
            engine: $engine,
            model: $model,
            parameters: $options['parameters'] ?? [],
            userId: $options['user_id'] ?? null,
            metadata: $options['metadata'] ?? []
        );

        return $this->aiEngineService->generate($request);
    }

    /**
     * Stream messages directly with default engine
     */
    public function stream(array $messages, array $options = []): \Generator
    {
        $engine = EngineEnum::from($options['engine'] ?? config('ai-engine.default_engine', 'openai'));
        $model = EntityEnum::from($options['model'] ?? config('ai-engine.default_model', 'gpt-4o'));

        $request = new AIRequest(
            prompt: $this->formatMessages($messages),
            engine: $engine,
            model: $model,
            parameters: $options['parameters'] ?? [],
            userId: $options['user_id'] ?? null,
            metadata: $options['metadata'] ?? []
        );

        yield from $this->aiEngineService->stream($request);
    }

    /**
     * Create an AI request
     */
    public function createRequest(
        string $prompt,
        ?string $engine = null,
        ?string $model = null,
        ?int $maxTokens = null,
        ?float $temperature = null,
        ?string $systemPrompt = null,
        array $parameters = []
    ): AIRequest {
        $engineEnum = $engine ? EngineEnum::from($engine) : EngineEnum::from(config('ai-engine.default_engine', 'openai'));
        $modelEnum = $model ? EntityEnum::from($model) : EntityEnum::from(config('ai-engine.default_model', 'gpt-4o'));

        return new AIRequest(
            prompt: $prompt,
            engine: $engineEnum,
            model: $modelEnum,
            parameters: $parameters,
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            temperature: $temperature
        );
    }

    /**
     * Get available engines
     */
    public function getEngines(): array
    {
        return array_map(fn($engine) => $engine->value, EngineEnum::cases());
    }

    /**
     * Get available models for an engine
     */
    public function getModels(?string $engine = null): array
    {
        if (!$engine) {
            return array_map(fn($model) => $model->value, EntityEnum::cases());
        }

        $engineEnum = EngineEnum::from($engine);
        return array_map(
            fn($model) => $model->value,
            array_filter(
                EntityEnum::cases(),
                fn($model) => $model->engine() === $engineEnum
            )
        );
    }

    /**
     * Format messages array to prompt string
     */
    protected function formatMessages(array $messages): string
    {
        if (empty($messages)) {
            return '';
        }

        // If it's already a simple string, return it
        if (is_string($messages[0])) {
            return implode("\n", $messages);
        }

        // Format chat messages
        $formatted = [];
        foreach ($messages as $message) {
            if (isset($message['role']) && isset($message['content'])) {
                $formatted[] = ucfirst($message['role']) . ': ' . $message['content'];
            }
        }

        return implode("\n", $formatted);
    }

    /**
     * Execute an interactive action
     */
    public function executeAction(InteractiveAction $action, array $payload = []): ActionResponse
    {
        if (!$this->actionManager) {
            throw new \RuntimeException('ActionManager not available');
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
        return array_map([$this, 'createAction'], $actionsData);
    }

    /**
     * Get supported action types
     */
    public function getSupportedActionTypes(): array
    {
        if (!$this->actionManager) {
            return [];
        }

        return $this->actionManager->getSupportedActionTypes();
    }

    /**
     * Validate an action
     */
    public function validateAction(InteractiveAction $action, array $payload = []): array
    {
        if (!$this->actionManager) {
            return ['error' => 'ActionManager not available'];
        }

        return $this->actionManager->validateAction($action, $payload);
    }

    /**
     * Execute request with automatic failover
     */
    public function executeWithFailover(
        callable $callback,
        array $providers,
        string $strategy = 'priority',
        array $options = []
    ): mixed {
        if (!$this->failoverManager) {
            throw new \RuntimeException('FailoverManager not available');
        }

        return $this->failoverManager->executeWithFailover($callback, $providers, $strategy, $options);
    }

    /**
     * Get provider health status
     */
    public function getProviderHealth(?string $provider = null): array
    {
        if (!$this->failoverManager) {
            return [];
        }

        return $this->failoverManager->getProviderHealth($provider);
    }

    /**
     * Get system health overview
     */
    public function getSystemHealth(): array
    {
        if (!$this->failoverManager) {
            return ['status' => 'unknown', 'message' => 'FailoverManager not available'];
        }

        return $this->failoverManager->getSystemHealth();
    }

    /**
     * Stream AI response in real-time
     */
    public function streamResponse(
        string $sessionId,
        callable $generator,
        array $options = []
    ): void {
        if (!$this->streamingManager) {
            throw new \RuntimeException('StreamingManager not available');
        }

        $this->streamingManager->streamResponse($sessionId, $generator, $options);
    }

    /**
     * Stream AI response with interactive actions
     */
    public function streamWithActions(
        string $sessionId,
        callable $generator,
        array $actions = [],
        array $options = []
    ): void {
        if (!$this->streamingManager) {
            throw new \RuntimeException('StreamingManager not available');
        }

        $this->streamingManager->streamWithActions($sessionId, $generator, $actions, $options);
    }

    /**
     * Get streaming statistics
     */
    public function getStreamingStats(): array
    {
        if (!$this->streamingManager) {
            return [];
        }

        return $this->streamingManager->getStats();
    }

    /**
     * Track AI request for analytics
     */
    public function trackRequest(array $data): void
    {
        if ($this->analyticsManager) {
            $this->analyticsManager->trackRequest($data);
        }
    }

    /**
     * Track streaming session for analytics
     */
    public function trackStreaming(array $data): void
    {
        if ($this->analyticsManager) {
            $this->analyticsManager->trackStreaming($data);
        }
    }

    /**
     * Track interactive action for analytics
     */
    public function trackAction(array $data): void
    {
        if ($this->analyticsManager) {
            $this->analyticsManager->trackAction($data);
        }
    }

    /**
     * Get analytics dashboard data
     */
    public function getDashboardData(array $filters = []): array
    {
        if (!$this->analyticsManager) {
            return [];
        }

        return $this->analyticsManager->getDashboardData($filters);
    }

    /**
     * Get usage statistics
     */
    public function getUsageStats(array $filters = []): array
    {
        if (!$this->analyticsManager) {
            return [];
        }

        return $this->analyticsManager->getUsageAnalytics($filters);
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        if (!$this->analyticsManager) {
            return [];
        }

        return $this->analyticsManager->getPerformanceMetrics($filters);
    }

    /**
     * Get real-time metrics
     */
    public function getRealTimeMetrics(): array
    {
        if (!$this->analyticsManager) {
            return [];
        }

        return $this->analyticsManager->getRealTimeMetrics();
    }

    /**
     * Generate analytics report
     */
    public function generateReport(array $options = []): array
    {
        if (!$this->analyticsManager) {
            return [];
        }

        return $this->analyticsManager->generateReport($options);
    }

    /**
     * Get comprehensive system status
     */
    public function getSystemStatus(): array
    {
        return [
            'core' => [
                'ai_engine' => class_exists(AIEngineService::class),
                'memory_manager' => $this->memoryManager !== null,
            ],
            'enterprise' => [
                'actions' => $this->actionManager !== null,
                'failover' => $this->failoverManager !== null,
                'streaming' => $this->streamingManager !== null,
                'analytics' => $this->analyticsManager !== null,
            ],
            'health' => $this->getSystemHealth(),
            'metrics' => $this->getRealTimeMetrics(),
            'timestamp' => now()->toISOString(),
        ];
    }
}
