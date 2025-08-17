<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use MagicAI\LaravelAIEngine\Services\AIEngineManager;

/**
 * @method static \MagicAI\LaravelAIEngine\Services\EngineBuilder engine(string $engine)
 * @method static \MagicAI\LaravelAIEngine\Services\EngineBuilder model(string $model)
 * @method static array getAvailableEngines()
 * @method static array getAvailableModels(string $engine = null)
 * @method static \MagicAI\LaravelAIEngine\Services\BatchProcessor batch()
 * @method static \MagicAI\LaravelAIEngine\Services\TemplateManager template(string $name)
 * @method static \MagicAI\LaravelAIEngine\Services\AnalyticsManager analytics()
 * @method static array estimateCost(array $operations)
 *
 * @see \MagicAI\LaravelAIEngine\Services\AIEngineManager
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
