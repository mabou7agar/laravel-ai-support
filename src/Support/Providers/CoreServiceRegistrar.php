<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Services\AIMediaManager;
use LaravelAIEngine\Services\CacheManager;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\DiscoveryCacheWarmer;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class CoreServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\OpenAI\Client::class, function () {
            $apiKey = config('ai-engine.engines.openai.api_key');
            if (empty($apiKey)) {
                throw new \RuntimeException('OpenAI API key is not configured. Please set OPENAI_API_KEY in your .env file.');
            }

            return \OpenAI::client($apiKey);
        });

        $app->singleton(CreditManager::class, fn ($app) => new CreditManager($app));
        $app->singleton(AIMediaManager::class, fn () => new AIMediaManager());
        $app->singleton(CacheManager::class, fn ($app) => new CacheManager($app));
        $app->singleton(RateLimitManager::class, fn ($app) => new RateLimitManager($app));
        $app->singleton(AnalyticsManager::class, fn ($app) => new AnalyticsManager($app));
        $app->singleton(DriverRegistry::class, fn ($app) => new DriverRegistry($app));

        $app->singleton(ConversationManager::class, fn () => new ConversationManager());

        $app->singleton(DiscoveryCacheWarmer::class, function ($app) {
            return new DiscoveryCacheWarmer(
                $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\Memory\MemoryManager::class, fn () => new \LaravelAIEngine\Services\Memory\MemoryManager());
        $app->singleton(\LaravelAIEngine\Services\RequestRouteResolver::class, fn ($app) => new \LaravelAIEngine\Services\RequestRouteResolver(
            $app->make(\LaravelAIEngine\Services\AIModelRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\AIEngineService::class, fn ($app) => new \LaravelAIEngine\Services\AIEngineService(
            $app->make(CreditManager::class),
            $app->make(ConversationManager::class),
            $app->make(DriverRegistry::class),
            $app->make(\LaravelAIEngine\Services\RequestRouteResolver::class)
        ));
        $app->singleton(\LaravelAIEngine\Support\Fal\FalCharacterStore::class, fn () => new \LaravelAIEngine\Support\Fal\FalCharacterStore());
        $app->singleton(\LaravelAIEngine\Services\Fal\FalReferencePackGenerationService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalReferencePackGenerationService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Support\Fal\FalCharacterStore::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Fal\FalCharacterGenerationService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalCharacterGenerationService(
            $app->make(\LaravelAIEngine\Services\Fal\FalReferencePackGenerationService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Fal\FalMediaWorkflowService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalMediaWorkflowService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Support\Fal\FalCharacterStore::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService(
            $app->make(\LaravelAIEngine\Services\Fal\FalReferencePackGenerationService::class),
            $app->make(\LaravelAIEngine\Services\JobStatusTracker::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Fal\FalAsyncCharacterGenerationService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalAsyncCharacterGenerationService(
            $app->make(\LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Fal\FalAsyncVideoService::class, fn ($app) => new \LaravelAIEngine\Services\Fal\FalAsyncVideoService(
            $app->make(\LaravelAIEngine\Services\Fal\FalMediaWorkflowService::class),
            $app->make(\LaravelAIEngine\Services\Drivers\DriverRegistry::class),
            $app->make(\LaravelAIEngine\Services\JobStatusTracker::class),
            $app->make(CreditManager::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\UnifiedEngineManager::class, fn ($app) => new \LaravelAIEngine\Services\UnifiedEngineManager(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Memory\MemoryManager::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\WebhookManager::class, fn () => new \LaravelAIEngine\Services\WebhookManager());
        $app->singleton(\LaravelAIEngine\Services\TemplateManager::class, fn () => new \LaravelAIEngine\Services\TemplateManager());
        $app->singleton(\LaravelAIEngine\Services\JobStatusTracker::class, fn () => new \LaravelAIEngine\Services\JobStatusTracker());
        $app->singleton(\LaravelAIEngine\Services\QueuedAIProcessor::class, fn ($app) => new \LaravelAIEngine\Services\QueuedAIProcessor($app->make(\LaravelAIEngine\Services\JobStatusTracker::class)));

        $app->singleton(\LaravelAIEngine\Services\RAG\IntelligentRAGService::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\IntelligentRAGService(
                $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(DriverRegistry::class),
                $app->make(\LaravelAIEngine\Services\ConversationService::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\AIModelRegistry::class, fn () => new \LaravelAIEngine\Services\AIModelRegistry());
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class, fn () => new \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery());
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class, fn () => new \LaravelAIEngine\Services\RAG\AutonomousRAGPolicy());
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionPromptService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGDecisionPromptService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGStateService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGStateService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService($app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGStateService::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGContextService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGContextService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionPromptService::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService::class, fn () => new \LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService());
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGAggregateService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGAggregateService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService($app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGStateService::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGAggregateService::class)));

        $app->singleton(\LaravelAIEngine\Services\DataCollector\DataCollectorService::class, function ($app) {
            return new \LaravelAIEngine\Services\DataCollector\DataCollectorService(
                $app->make(\LaravelAIEngine\Services\UnifiedEngineManager::class),
                $app->make(\LaravelAIEngine\Services\ConversationService::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\DataCollector\DataCollectorChatService::class, function ($app) {
            return new \LaravelAIEngine\Services\DataCollector\DataCollectorChatService(
                $app->make(\LaravelAIEngine\Services\DataCollector\DataCollectorService::class),
                $app->make(\LaravelAIEngine\Services\ChatService::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\ModelResolver::class, fn () => new \LaravelAIEngine\Services\ModelResolver());
        $app->singleton(\LaravelAIEngine\Services\PendingActionService::class, fn () => new \LaravelAIEngine\Services\PendingActionService());
        $app->singleton(InfrastructureHealthService::class, fn () => new InfrastructureHealthService());
    }
}
