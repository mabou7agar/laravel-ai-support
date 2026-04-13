<?php

declare(strict_types=1);

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\Services\UnifiedEngineManager;

/**
 * @method static \LaravelAIEngine\Services\EngineProxy engine(string $engine)
 * @method static \LaravelAIEngine\Services\EngineProxy model(string $model)
 * @method static array getAvailableEngines()
 * @method static array getAvailableModels(string $engine = null)
 * @method static \LaravelAIEngine\Services\BatchProcessor batch()
 * @method static \LaravelAIEngine\Services\TemplateManager template(string $name)
 * @method static \LaravelAIEngine\Services\AnalyticsManager analytics()
 * @method static array estimateCost(array $operations)
 *
 * @deprecated Use \LaravelAIEngine\Facades\Engine instead.
 *
 * @see \LaravelAIEngine\Services\UnifiedEngineManager
 */
class AIEngine extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'unified-engine';
    }
}
