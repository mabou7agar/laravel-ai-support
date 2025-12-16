<?php

namespace LaravelAIEngine\Facades;

use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\Services\DataCollector\DataCollectorChatService;

/**
 * @method static \LaravelAIEngine\DTOs\AIResponse startCollection(string $sessionId, \LaravelAIEngine\DTOs\DataCollectorConfig $config, array $initialData = [])
 * @method static \LaravelAIEngine\DTOs\AIResponse processMessage(string $sessionId, string $message, string $engine = 'openai', string $model = 'gpt-4o')
 * @method static bool isDataCollectionSession(string $sessionId)
 * @method static \LaravelAIEngine\DTOs\DataCollectorState|null getSessionState(string $sessionId)
 * @method static \LaravelAIEngine\DTOs\AIResponse cancelSession(string $sessionId)
 * @method static array getCollectedData(string $sessionId)
 * @method static self registerConfig(\LaravelAIEngine\DTOs\DataCollectorConfig $config)
 * @method static \LaravelAIEngine\DTOs\DataCollectorConfig createCollector(string $name, array $definition)
 * 
 * @see \LaravelAIEngine\Services\DataCollector\DataCollectorChatService
 */
class DataCollector extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DataCollectorChatService::class;
    }
}
