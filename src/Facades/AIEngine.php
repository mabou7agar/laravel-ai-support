<?php

declare(strict_types=1);

namespace LaravelAIEngine\Facades;

/**
 * AIEngine facade — alias for Engine, providing the same API for AI operations and memory management
 *
 * @method static \LaravelAIEngine\Services\EngineProxy engine(string $engine)
 * @method static \LaravelAIEngine\Services\EngineProxy model(string $model)
 * @method static \LaravelAIEngine\Services\Memory\MemoryManager memory(string $driver = null)
 * @method static \LaravelAIEngine\Testing\AIEngineFake fake(array $responses = [])
 * @method static array rerank(string $query, array $documents, int $limit = 10)
 * @method static \LaravelAIEngine\Services\SDK\FileStoreService fileStores()
 * @method static \LaravelAIEngine\Services\SDK\VectorStoreService vectorStores()
 * @method static \LaravelAIEngine\Services\SDK\RealtimeSessionService realtime()
 * @method static \LaravelAIEngine\Services\SDK\TraceRecorderService traces()
 * @method static \LaravelAIEngine\Services\SDK\EvaluationService evaluations()
 * @method static array getEngines()
 * @method static array getModels(string $engine = null)
 * @method static array getAvailableEngines()
 * @method static array getAvailableModels(string $engine = null)
 * @method static \LaravelAIEngine\DTOs\ActionResult executeAction(\LaravelAIEngine\DTOs\InteractiveAction $action, mixed $userId = null, ?string $sessionId = null)
 * @method static \LaravelAIEngine\DTOs\InteractiveAction createAction(array $data)
 * @method static array createActions(array $actionsData)
 * @method static void streamResponse(string $sessionId, callable $generator, array $options = [])
 * @method static void streamWithActions(string $sessionId, callable $generator, array $actions = [], array $options = [])
 * @method static array getStreamingStats()
 * @method static void trackRequest(array $data)
 * @method static void trackStreaming(array $data)
 * @method static void trackAction(array $data)
 * @method static array getDashboardData(array $filters = [])
 * @method static array getUsageStats(array $filters = [])
 * @method static array getPerformanceMetrics(array $filters = [])
 * @method static \LaravelAIEngine\Services\BatchProcessor batch()
 * @method static \LaravelAIEngine\Services\TemplateManager template(string $name)
 * @method static \LaravelAIEngine\Services\AnalyticsManager analytics()
 * @method static \LaravelAIEngine\DTOs\AIRequest createRequest(string $prompt, string $engine = null, string $model = null, int $maxTokens = null, float $temperature = null, string $systemPrompt = null, array $parameters = [])
 * @method static \LaravelAIEngine\DTOs\AIResponse audioToText(\LaravelAIEngine\DTOs\AIRequest $request)
 * @method static \LaravelAIEngine\DTOs\AIResponse speechToText(\LaravelAIEngine\DTOs\AIRequest $request)
 * @method static \LaravelAIEngine\DTOs\AIResponse speechToSpeech(\LaravelAIEngine\DTOs\AIRequest $request)
 * @method static array estimateCost(array $operations)
 *
 * @see \LaravelAIEngine\Services\UnifiedEngineManager
 */
class AIEngine extends Engine
{
    // inherits getFacadeAccessor() from Engine
}
