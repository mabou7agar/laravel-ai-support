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
        if (!$app->bound(\LaravelAIEngine\Contracts\AIScopeResolver::class)) {
            $app->singleton(\LaravelAIEngine\Contracts\AIScopeResolver::class, fn () => new \LaravelAIEngine\Services\Scope\DefaultAIScopeResolver());
        }
        $app->singleton(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class, fn ($app) => new \LaravelAIEngine\Services\Scope\AIScopeOptionsService(
            $app->make(\LaravelAIEngine\Contracts\AIScopeResolver::class)
        ));

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
            $app->make(\LaravelAIEngine\Services\RequestRouteResolver::class),
            $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)
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
            $app->make(\LaravelAIEngine\Services\Drivers\DriverRegistry::class),
            $app->make(\LaravelAIEngine\Services\JobStatusTracker::class),
            $app->make(CreditManager::class)
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

        $app->singleton(\LaravelAIEngine\Services\RAG\RAGChatService::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\RAGChatService(
                $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(DriverRegistry::class),
                $app->make(\LaravelAIEngine\Services\ConversationService::class),
                null,
                $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)
            );
        });
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGRetriever::class, function ($app) {
            $vector = $app->bound(\LaravelAIEngine\Services\Vector\VectorSearchService::class)
                ? $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class)
                : null;
            $graph = $app->bound(\LaravelAIEngine\Services\Graph\Neo4jRetrievalService::class)
                ? $app->make(\LaravelAIEngine\Services\Graph\Neo4jRetrievalService::class)
                : null;
            $hybrid = $app->bound(\LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService::class)
                ? $app->make(\LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService::class)
                : null;

            return new \LaravelAIEngine\Services\RAG\RAGRetriever([
                new \LaravelAIEngine\Services\RAG\Retrievers\VectorRAGRetriever($vector),
                new \LaravelAIEngine\Services\RAG\Retrievers\GraphRAGRetriever($graph),
                new \LaravelAIEngine\Services\RAG\Retrievers\HybridRAGRetriever($hybrid),
            ]);
        });
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGPipeline::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGPipeline(
            $app->make(\LaravelAIEngine\Services\RAG\RAGQueryAnalyzer::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionResolver::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGRetriever::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGContextBuilder::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGPromptBuilder::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGResponseGenerator::class)
        ));
        $app->alias(\LaravelAIEngine\Services\RAG\RAGPipeline::class, \LaravelAIEngine\Contracts\RAGPipelineContract::class);

        $app->singleton(\LaravelAIEngine\Services\AIModelRegistry::class, fn () => new \LaravelAIEngine\Services\AIModelRegistry());
        $app->singleton(\LaravelAIEngine\Repositories\AIModelRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\AgentRunRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\AgentRunStepRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\ProviderToolRunRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\ProviderToolApprovalRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\ProviderToolArtifactRepository::class);
        $app->singleton(\LaravelAIEngine\Repositories\ProviderToolAuditRepository::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderToolPolicyService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderToolApprovalService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderToolRunService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\HostedArtifactService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderToolContinuationService::class);
        $app->singleton(\LaravelAIEngine\Services\ProviderTools\ProviderFileDownloadService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunApprovalService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentTraceMetadataService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class);
        $app->singleton(\LaravelAIEngine\Services\Fal\FalCatalogExecutionService::class);
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class, fn () => new \LaravelAIEngine\Services\RAG\RAGCollectionDiscovery());
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class, fn () => new \LaravelAIEngine\Services\RAG\RAGDecisionPolicy());
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService($app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionPromptService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGDecisionPromptService($app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionStateService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGDecisionStateService($app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGModelMetadataService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGModelMetadataService($app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionStateService::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGContextService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGContextService($app->make(\LaravelAIEngine\Services\RAG\RAGModelMetadataService::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGPlannerService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGPlannerService($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPromptService::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGToolExecutionService::class, fn () => new \LaravelAIEngine\Services\RAG\RAGToolExecutionService());
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGModelScopeGuard::class, fn () => new \LaravelAIEngine\Services\RAG\RAGModelScopeGuard());
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGAggregateService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGAggregateService($app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\RAGModelScopeGuard::class)));
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGStructuredDataService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGStructuredDataService($app->make(\LaravelAIEngine\Services\RAG\RAGDecisionStateService::class), $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class), $app->make(\LaravelAIEngine\Services\RAG\RAGAggregateService::class), null, null, null, $app->make(\LaravelAIEngine\Services\RAG\RAGModelScopeGuard::class)));

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
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunSafetyService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunMaintenanceService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunRetentionService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunRecoveryService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunBudgetService::class);
        $app->singleton(InfrastructureHealthService::class, fn () => new InfrastructureHealthService());
    }
}
