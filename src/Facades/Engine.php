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
 * @method static \LaravelAIEngine\Services\EngineProxy model(string $model)
 * @method static array getEngines()
 * @method static array getModels(string $engine = null)
 * @method static \LaravelAIEngine\DTOs\ActionResponse executeAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
 * @method static \LaravelAIEngine\DTOs\InteractiveAction createAction(array $data)
 * @method static array createActions(array $actionsData)
 * @method static array getSupportedActionTypes()
 * @method static array validateAction(\LaravelAIEngine\DTOs\InteractiveAction $action, array $payload = [])
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
