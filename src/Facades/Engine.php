<?php

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Unified Engine facade providing simple API for AI operations and memory management
 * 
 * @method static \LaravelAIEngine\Services\EngineProxy engine(string $engine = null)
 * @method static \LaravelAIEngine\Services\MemoryProxy memory(string $driver = null)
 * @method static \LaravelAIEngine\DTOs\AIResponse send(array $messages, array $options = [])
 * @method static \Generator stream(array $messages, array $options = [])
 * @method static \LaravelAIEngine\DTOs\AIRequest createRequest(string $prompt, string $engine = null, string $model = null, int $maxTokens = null, float $temperature = null, string $systemPrompt = null, array $parameters = [])
 * @method static array getEngines()
 * @method static array getModels(string $engine = null)
 * @method static \LaravelAIEngine\DTOs\ActionResponse executeAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
 * @method static \LaravelAIEngine\DTOs\InteractiveAction createAction(array $data)
 * @method static array createActions(array $actionsData)
 * @method static array getSupportedActionTypes()
 * @method static array validateAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
 * @method static mixed executeWithFailover(callable $callback, array $providers, string $strategy = 'priority', array $options = [])
 * @method static array getProviderHealth(string $provider = null)
 * @method static array getSystemHealth()
 * @method static void streamResponse(string $sessionId, callable $generator, array $options = [])
 * @method static void streamWithActions(string $sessionId, callable $generator, array $actions = [], array $options = [])
 * @method static array getStreamingStats()
 * @method static void trackRequest(array $data)
 * @method static void trackStreaming(array $data)
 * @method static void trackAction(array $data)
 * @method static array getDashboardData(array $filters = [])
 * @method static array getUsageStats(array $filters = [])
 * @method static array getPerformanceMetrics(array $filters = [])
 * 
 * @see \LaravelAIEngine\Services\UnifiedEngineManager
 */
class Engine extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unified-engine';
    }
}
