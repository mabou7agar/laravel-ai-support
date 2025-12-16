<?php

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\Services\AIEngineManager;

/**
 * Engine facade providing simple API for AI operations and memory management
 * 
 * @method static \LaravelAIEngine\Services\EngineBuilder engine(string $engine)
 * @method static \LaravelAIEngine\Services\EngineBuilder model(string $model)
 * @method static \LaravelAIEngine\Services\Memory\MemoryManager memory(string $driver = null)
 * @method static array getEngines()
 * @method static array getModels(string $engine = null)
 * @method static array getAvailableEngines()
 * @method static array getAvailableModels(string $engine = null)
 * @method static \LaravelAIEngine\DTOs\ActionResponse executeAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
 * @method static \LaravelAIEngine\DTOs\InteractiveAction createAction(array $data)
 * @method static array createActions(array $actionsData)
 * @method static array getSupportedActionTypes()
 * @method static array validateAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
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
 * 
 * @see \LaravelAIEngine\Services\AIEngineManager
 */
class Engine extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AIEngineManager::class;
    }
}
