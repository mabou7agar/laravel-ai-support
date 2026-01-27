<?php

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorService;

/**
 * @method static \LaravelAIEngine\Services\DataCollector\AutonomousCollectorResponse start(string $sessionId, \LaravelAIEngine\DTOs\AutonomousCollectorConfig $config, string $initialMessage = '')
 * @method static \LaravelAIEngine\Services\DataCollector\AutonomousCollectorResponse process(string $sessionId, string $message)
 * @method static \LaravelAIEngine\Services\DataCollector\AutonomousCollectorResponse confirm(string $sessionId)
 * @method static bool hasSession(string $sessionId)
 * @method static string|null getStatus(string $sessionId)
 * @method static array getData(string $sessionId)
 * @method static void deleteSession(string $sessionId)
 * @method static void registerConfig(\LaravelAIEngine\DTOs\AutonomousCollectorConfig $config)
 * 
 * @see \LaravelAIEngine\Services\DataCollector\AutonomousCollectorService
 */
class AutonomousCollector extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AutonomousCollectorService::class;
    }
}
