<?php

declare(strict_types=1);

namespace MagicAI\LaravelAIEngine;

use Illuminate\Support\ServiceProvider;
use MagicAI\LaravelAIEngine\Services\AIEngineManager;
use MagicAI\LaravelAIEngine\Services\CreditManager;
use MagicAI\LaravelAIEngine\Services\CacheManager;
use MagicAI\LaravelAIEngine\Services\RateLimitManager;
use MagicAI\LaravelAIEngine\Services\AnalyticsManager;

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

        $this->app->singleton(AIEngineManager::class, function ($app) {
            return new AIEngineManager($app);
        });

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

        $this->app->alias(AIEngineManager::class, 'ai-engine');
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
                Commands\TestEnginesCommand::class,
                Commands\SyncModelsCommand::class,
                Commands\UsageReportCommand::class,
                Commands\ClearCacheCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
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
