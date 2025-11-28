<?php

declare(strict_types=1);

namespace LaravelAIEngine;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\BrandVoiceManager;
use LaravelAIEngine\Services\WebhookManager;
use LaravelAIEngine\Services\TemplateManager;
use LaravelAIEngine\Services\ContentModerationService;
use LaravelAIEngine\Services\JobStatusTracker;
use LaravelAIEngine\Services\QueuedAIProcessor;
use LaravelAIEngine\Console\Commands\TestEnginesCommand;
use LaravelAIEngine\Console\Commands\UsageReportCommand;
use LaravelAIEngine\Console\Commands\ClearCacheCommand;
use LaravelAIEngine\Events\AIRequestStarted;
use LaravelAIEngine\Events\AIRequestCompleted;
use LaravelAIEngine\Listeners\LogAIRequest;
use LaravelAIEngine\Listeners\SendWebhookNotification;

class LaravelAIEngineServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/../config/ai-engine.php',
            'ai-engine'
        );

        // Register core services
        $this->app->singleton(AIEngineService::class, function ($app) {
            return new AIEngineService($app->make(CreditManager::class));
        });

        $this->app->singleton(CreditManager::class, function ($app) {
            return new CreditManager($app);
        });

        $this->app->singleton(BrandVoiceManager::class, function ($app) {
            return new BrandVoiceManager();
        });

        $this->app->singleton(WebhookManager::class, function ($app) {
            return new WebhookManager();
        });

        $this->app->singleton(TemplateManager::class, function ($app) {
            return new TemplateManager();
        });

        $this->app->singleton(ContentModerationService::class, function ($app) {
            return new ContentModerationService();
        });

        // Register job queue services
        $this->app->singleton(JobStatusTracker::class, function ($app) {
            return new JobStatusTracker();
        });

        $this->app->singleton(QueuedAIProcessor::class, function ($app) {
            return new QueuedAIProcessor($app->make(JobStatusTracker::class));
        });

        // Register Intelligent RAG Service
        $this->app->singleton(\LaravelAIEngine\Services\RAG\IntelligentRAGService::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\IntelligentRAGService(
                $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
                $app->make(\LaravelAIEngine\Services\AIEngineManager::class)
            );
        });

        // Register AI Model Registry
        $this->app->singleton(\LaravelAIEngine\Services\AIModelRegistry::class, function ($app) {
            return new \LaravelAIEngine\Services\AIModelRegistry();
        });

        // Register RAG Collection Discovery
        $this->app->singleton(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery();
        });

        // Register aliases
        $this->app->alias(AIEngineService::class, 'ai-engine');
        $this->app->alias(CreditManager::class, 'ai-engine.credits');
        $this->app->alias(BrandVoiceManager::class, 'ai-engine.brand-voice');
        $this->app->alias(WebhookManager::class, 'ai-engine.webhooks');
        $this->app->alias(TemplateManager::class, 'ai-engine.templates');
        $this->app->alias(ContentModerationService::class, 'ai-engine.moderation');
        $this->app->alias(JobStatusTracker::class, 'ai-engine.job-tracker');
        $this->app->alias(QueuedAIProcessor::class, 'ai-engine.queue-processor');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/ai-engine.php' => config_path('ai-engine.php'),
            ], 'ai-engine-config');

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'ai-engine-migrations');

            // Register console commands
            $this->commands([
                TestEnginesCommand::class,
                UsageReportCommand::class,
                ClearCacheCommand::class,
                \LaravelAIEngine\Console\Commands\TestAiChatCommand::class,
                \LaravelAIEngine\Console\Commands\TestEmailAssistantCommand::class,
                \LaravelAIEngine\Console\Commands\TestDynamicActionsCommand::class,
                \LaravelAIEngine\Console\Commands\SyncAIModelsCommand::class,
                \LaravelAIEngine\Console\Commands\ListAIModelsCommand::class,
                \LaravelAIEngine\Console\Commands\AddAIModelCommand::class,
                \LaravelAIEngine\Console\Commands\ListRAGCollectionsCommand::class,
            ]);
        }

        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register event listeners
        $this->registerEventListeners();

        // Register routes if needed
        if (config('ai-engine.routes.enabled', true)) {
            // Load API routes
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
            
            // Load web routes (views)
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        }
    }

    /**
     * Register event listeners for AI operations.
     */
    protected function registerEventListeners(): void
    {
        // Register AI request logging
        Event::listen(AIRequestStarted::class, LogAIRequest::class . '@handleStarted');
        Event::listen(AIRequestCompleted::class, LogAIRequest::class . '@handleCompleted');

        // Register webhook notifications
        if (config('ai-engine.webhooks.enabled', false)) {
            Event::listen(AIRequestStarted::class, SendWebhookNotification::class . '@handleStarted');
            Event::listen(AIRequestCompleted::class, SendWebhookNotification::class . '@handleCompleted');
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AIEngineService::class,
            CreditManager::class,
            BrandVoiceManager::class,
            WebhookManager::class,
            TemplateManager::class,
            ContentModerationService::class,
            JobStatusTracker::class,
            QueuedAIProcessor::class,
            'ai-engine',
            'ai-engine.credits',
            'ai-engine.brand-voice',
            'ai-engine.webhooks',
            'ai-engine.templates',
            'ai-engine.moderation',
            'ai-engine.job-tracker',
            'ai-engine.queue-processor',
        ];
    }
}
