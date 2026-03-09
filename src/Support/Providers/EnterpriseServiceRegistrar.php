<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

use LaravelAIEngine\Services\ActionHandlers\ButtonActionHandler;
use LaravelAIEngine\Services\ActionHandlers\QuickReplyActionHandler;
use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager as NewAnalyticsManager;
use LaravelAIEngine\Services\Analytics\Drivers\DatabaseAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Drivers\RedisAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Metrics\MetricsCollector;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;

class EnterpriseServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(ActionManager::class, function () {
            $manager = new ActionManager();
            $manager->registerHandler(new ButtonActionHandler());
            $manager->registerHandler(new QuickReplyActionHandler());
            return $manager;
        });

        $app->singleton(FailoverManager::class, fn () => new FailoverManager());

        if (interface_exists('Ratchet\\MessageComponentInterface')) {
            $app->singleton(WebSocketManager::class, fn () => new WebSocketManager());
        }

        $app->singleton(MetricsCollector::class, fn () => new MetricsCollector());
        $app->singleton(DatabaseAnalyticsDriver::class, fn () => new DatabaseAnalyticsDriver());
        $app->singleton(RedisAnalyticsDriver::class, fn () => new RedisAnalyticsDriver());
        $app->singleton(NewAnalyticsManager::class, fn ($app) => new NewAnalyticsManager($app->make(MetricsCollector::class)));

        $app->singleton(\LaravelAIEngine\Services\Vector\VectorDriverManager::class, fn () => new \LaravelAIEngine\Services\Vector\VectorDriverManager());
        $app->singleton(\LaravelAIEngine\Services\Vector\EmbeddingService::class, fn ($app) => new \LaravelAIEngine\Services\Vector\EmbeddingService($app->make(\OpenAI\Client::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAccessControl::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAccessControl());
        $app->singleton(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class, fn () => new \LaravelAIEngine\Services\Tenant\MultiTenantVectorService());
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorSearchService::class, fn ($app) => new \LaravelAIEngine\Services\Vector\VectorSearchService($app->make(\LaravelAIEngine\Services\Vector\VectorDriverManager::class), $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class), $app->make(\LaravelAIEngine\Services\Vector\VectorAccessControl::class)));

        $app->singleton(\LaravelAIEngine\Services\Media\VisionService::class, fn ($app) => new \LaravelAIEngine\Services\Media\VisionService($app->make(\OpenAI\Client::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\AudioService::class, fn ($app) => new \LaravelAIEngine\Services\Media\AudioService($app->make(\OpenAI\Client::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\VideoService::class, fn ($app) => new \LaravelAIEngine\Services\Media\VideoService($app->make(\LaravelAIEngine\Services\Media\AudioService::class), $app->make(\LaravelAIEngine\Services\Media\VisionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\DocumentService::class, fn () => new \LaravelAIEngine\Services\Media\DocumentService());
        $app->singleton(\LaravelAIEngine\Services\Media\MediaEmbeddingService::class, fn ($app) => new \LaravelAIEngine\Services\Media\MediaEmbeddingService($app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class)));

        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAuthorizationService::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAuthorizationService());
        $app->singleton(\LaravelAIEngine\Services\Vector\ChunkingService::class, fn () => new \LaravelAIEngine\Services\Vector\ChunkingService());
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAnalyticsService::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAnalyticsService());
    }
}
