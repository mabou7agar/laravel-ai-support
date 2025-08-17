<?php

declare(strict_types=1);

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\Services\AIEngineManager;

/**
 * @method static \LaravelAIEngine\Services\EngineBuilder engine(string $engine)
 * @method static \LaravelAIEngine\Services\EngineBuilder model(string $model)
 * @method static array getAvailableEngines()
 * @method static array getAvailableModels(string $engine = null)
 * @method static \LaravelAIEngine\Services\BatchProcessor batch()
 * @method static \LaravelAIEngine\Services\TemplateManager template(string $name)
 * @method static \LaravelAIEngine\Services\AnalyticsManager analytics()
 * @method static array estimateCost(array $operations)
 *
 * @see \LaravelAIEngine\Services\AIEngineManager
 */
class AIEngine extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return AIEngineManager::class;
    }
}
