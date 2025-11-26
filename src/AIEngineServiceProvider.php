<?php

declare(strict_types=1);

namespace LaravelAIEngine;

use Illuminate\Support\ServiceProvider;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\CacheManager;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Services\ActionHandlers\ButtonActionHandler;
use LaravelAIEngine\Services\ActionHandlers\QuickReplyActionHandler;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager as NewAnalyticsManager;
use LaravelAIEngine\Services\Analytics\Metrics\MetricsCollector;
use LaravelAIEngine\Services\Analytics\Drivers\DatabaseAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Drivers\RedisAnalyticsDriver;

class AIEngineServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/ai-engine.php',
            'ai-engine'
        );

        // Core AI Engine Services
        $this->registerCoreServices();
        
        // Enterprise Features
        $this->registerEnterpriseServices();
        
        // Unified Engine Manager (combines all services)
        $this->registerUnifiedEngine();
        
        // Service Aliases
        $this->registerAliases();
    }

    /**
     * Register core AI engine services
     */
    protected function registerCoreServices(): void
    {
        // Register dependencies first
        $this->app->singleton(CreditManager::class, function ($app) {
            return new CreditManager($app);
        });

        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager($app);
        });

        $this->app->singleton(RateLimitManager::class, function ($app) {
            return new RateLimitManager($app);
        });

        $this->app->singleton(AnalyticsManager::class, function ($app) {
            return new AnalyticsManager($app);
        });

        // Register AIEngineManager with all dependencies
        $this->app->singleton(AIEngineManager::class, function ($app) {
            return new AIEngineManager(
                $app,
                $app->make(CreditManager::class),
                $app->make(CacheManager::class),
                $app->make(RateLimitManager::class),
                $app->make(AnalyticsManager::class)
            );
        });

        $this->app->singleton(ConversationManager::class, function ($app) {
            return new ConversationManager();
        });

        $this->app->singleton(\LaravelAIEngine\Services\Memory\MemoryManager::class, function ($app) {
            return new \LaravelAIEngine\Services\Memory\MemoryManager();
        });
    }

    /**
     * Register enterprise services
     */
    protected function registerEnterpriseServices(): void
    {
        // Interactive Actions System
        $this->app->singleton(ActionManager::class, function ($app) {
            $manager = new ActionManager();
            
            // Register default action handlers
            $manager->registerHandler(new ButtonActionHandler());
            $manager->registerHandler(new QuickReplyActionHandler());
            
            return $manager;
        });

        // Automatic Failover System
        $this->app->singleton(FailoverManager::class, function ($app) {
            return new FailoverManager();
        });

        // WebSocket Streaming System
        $this->app->singleton(WebSocketManager::class, function ($app) {
            return new WebSocketManager();
        });

        // Analytics and Monitoring System
        $this->app->singleton(MetricsCollector::class, function ($app) {
            return new MetricsCollector();
        });

        $this->app->singleton(DatabaseAnalyticsDriver::class, function ($app) {
            return new DatabaseAnalyticsDriver();
        });

        $this->app->singleton(RedisAnalyticsDriver::class, function ($app) {
            return new RedisAnalyticsDriver();
        });

        $this->app->singleton(NewAnalyticsManager::class, function ($app) {
            return new NewAnalyticsManager($app->make(MetricsCollector::class));
        });
    }

    /**
     * Register unified engine manager
     */
    protected function registerUnifiedEngine(): void
    {
        $this->app->singleton(\LaravelAIEngine\Services\UnifiedEngineManager::class, function ($app) {
            return new \LaravelAIEngine\Services\UnifiedEngineManager(
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(\LaravelAIEngine\Services\Memory\MemoryManager::class),
                $app->make(ActionManager::class),
                $app->make(FailoverManager::class),
                $app->make(WebSocketManager::class),
                $app->make(NewAnalyticsManager::class)
            );
        });
    }

    /**
     * Register service aliases
     */
    protected function registerAliases(): void
    {
        // Core aliases
        $this->app->alias(AIEngineManager::class, 'ai-engine');
        $this->app->alias(\LaravelAIEngine\Services\UnifiedEngineManager::class, 'unified-engine');
        
        // Enterprise aliases
        $this->app->alias(ActionManager::class, 'ai-actions');
        $this->app->alias(FailoverManager::class, 'ai-failover');
        $this->app->alias(WebSocketManager::class, 'ai-streaming');
        $this->app->alias(NewAnalyticsManager::class, 'ai-analytics');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-engine.php' => config_path('ai-engine.php'),
            ], 'ai-engine-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-engine-migrations');

            $this->commands([
                Console\Commands\TestEnginesCommand::class,
                Console\Commands\SyncModelsCommand::class,
                Console\Commands\UsageReportCommand::class,
                Console\Commands\ClearCacheCommand::class,
                Console\Commands\AnalyticsReportCommand::class,
                Console\Commands\FailoverStatusCommand::class,
                Console\Commands\StreamingServerCommand::class,
                Console\Commands\SystemHealthCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/chat.php');
        
        // Register views
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-engine');
        
        // Publish assets
        $this->publishes([
            __DIR__.'/../resources/js' => public_path('vendor/ai-engine/js'),
        ], 'ai-engine-assets');
        
        // Register Blade components
        $this->registerBladeComponents();
        
        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Register event listeners for enterprise features
     */
    protected function registerEventListeners(): void
    {
        $events = $this->app['events'];

        // Analytics tracking listeners
        $events->listen(
            \LaravelAIEngine\Events\AIResponseChunk::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleResponseChunk']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIResponseComplete::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleResponseComplete']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIActionTriggered::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleActionTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AISessionStarted::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleSessionStarted']
        );

        $events->listen(
            \LaravelAIEngine\Events\AISessionEnded::class,
            [\LaravelAIEngine\Listeners\AnalyticsTrackingListener::class, 'handleSessionEnded']
        );

        // Logging listeners
        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIFailoverTriggered::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleFailoverTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIProviderHealthChanged::class,
            [\LaravelAIEngine\Listeners\StreamingLoggingListener::class, 'handleProviderHealthChanged']
        );

        // Notification listeners for critical events
        $events->listen(
            \LaravelAIEngine\Events\AIStreamingError::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleStreamingError']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIFailoverTriggered::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleFailoverTriggered']
        );

        $events->listen(
            \LaravelAIEngine\Events\AIProviderHealthChanged::class,
            [\LaravelAIEngine\Listeners\StreamingNotificationListener::class, 'handleProviderHealthChanged']
        );
    }

    /**
     * Register Blade components
     */
    protected function registerBladeComponents(): void
    {
        $this->app->make('blade.compiler')->component('ai-chat', \LaravelAIEngine\View\Components\AiChat::class);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AIEngineManager::class,
            CreditManager::class,
            CacheManager::class,
            RateLimitManager::class,
            AnalyticsManager::class,
            'ai-engine',
        ];
    }
}
