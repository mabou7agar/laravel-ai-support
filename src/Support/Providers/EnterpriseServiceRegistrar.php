<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

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
        $app->singleton(FailoverManager::class, fn () => new FailoverManager());

        if (interface_exists('Ratchet\\MessageComponentInterface')) {
            $app->singleton(WebSocketManager::class, fn () => new WebSocketManager());
        }

        $app->singleton(MetricsCollector::class, fn () => new MetricsCollector());
        $app->singleton(DatabaseAnalyticsDriver::class, fn () => new DatabaseAnalyticsDriver());
        $app->singleton(RedisAnalyticsDriver::class, fn () => new RedisAnalyticsDriver());
        $app->singleton(NewAnalyticsManager::class, fn ($app) => new NewAnalyticsManager($app->make(MetricsCollector::class)));

        $app->singleton(\LaravelAIEngine\Services\Vector\VectorDriverManager::class, fn () => new \LaravelAIEngine\Services\Vector\VectorDriverManager());
        $app->singleton(\LaravelAIEngine\Services\Vector\EmbeddingService::class, fn ($app) => new \LaravelAIEngine\Services\Vector\EmbeddingService($app->make(\OpenAI\Contracts\ClientContract::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAccessControl::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAccessControl());
        $app->singleton(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class, fn () => new \LaravelAIEngine\Services\Tenant\MultiTenantVectorService());
        $app->singleton(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class, fn () => new \LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder());
        $app->singleton(\LaravelAIEngine\Services\Graph\Neo4jHttpTransport::class, fn () => new \LaravelAIEngine\Services\Graph\Neo4jHttpTransport());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphBackendResolver::class, fn ($app) => new \LaravelAIEngine\Services\Graph\GraphBackendResolver(
            $app->make(\LaravelAIEngine\Services\Vector\VectorDriverManager::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphVectorNamingService::class, fn () => new \LaravelAIEngine\Services\Graph\GraphVectorNamingService());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphOntologyService::class, fn () => new \LaravelAIEngine\Services\Graph\GraphOntologyService());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphNaturalLanguagePlanService::class, fn ($app) => new \LaravelAIEngine\Services\Graph\GraphNaturalLanguagePlanService(
            $app->make(\LaravelAIEngine\Services\Graph\GraphOntologyService::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphRankingFeedbackService::class, fn () => new \LaravelAIEngine\Services\Graph\GraphRankingFeedbackService());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService::class, fn () => new \LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService::class, fn () => new \LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphQueryPlanner::class, fn ($app) => new \LaravelAIEngine\Services\Graph\GraphQueryPlanner(
            $app->make(\LaravelAIEngine\Services\Graph\GraphOntologyService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphNaturalLanguagePlanService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphRankingFeedbackService::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphCypherPlanCompiler::class, fn () => new \LaravelAIEngine\Services\Graph\GraphCypherPlanCompiler());
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphDriftDetectionService::class, fn ($app) => new \LaravelAIEngine\Services\Graph\GraphDriftDetectionService(
            $app->make(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jHttpTransport::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jGraphSyncService::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService::class, fn ($app) => new \LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService(
            $app->make(\LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jHttpTransport::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphQueryPlanner::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jRetrievalService::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\Neo4jGraphSyncService::class, fn ($app) => new \LaravelAIEngine\Services\Graph\Neo4jGraphSyncService(
            $app->make(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class),
            $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jHttpTransport::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphVectorNamingService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphBackendResolver::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Graph\Neo4jRetrievalService::class, fn ($app) => new \LaravelAIEngine\Services\Graph\Neo4jRetrievalService(
            $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jHttpTransport::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphQueryPlanner::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphCypherPlanCompiler::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphVectorNamingService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphRankingFeedbackService::class),
            $app->make(\LaravelAIEngine\Services\Graph\GraphBackendResolver::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorSearchService::class, fn ($app) => new \LaravelAIEngine\Services\Vector\VectorSearchService(
            $app->make(\LaravelAIEngine\Services\Vector\VectorDriverManager::class),
            $app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class),
            $app->make(\LaravelAIEngine\Services\Vector\VectorAccessControl::class),
            $app->make(\LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder::class),
            $app->make(\LaravelAIEngine\Services\Vector\ChunkingService::class),
            $app->make(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class),
        ));
        $app->singleton(\LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService::class, fn ($app) => new \LaravelAIEngine\Services\RAG\HybridGraphVectorSearchService(
            $app->make(\LaravelAIEngine\Services\Vector\VectorSearchService::class),
            $app->make(\LaravelAIEngine\Services\Graph\Neo4jRetrievalService::class),
        ));

        $app->singleton(\LaravelAIEngine\Services\Media\VisionService::class, fn ($app) => new \LaravelAIEngine\Services\Media\VisionService($app->make(\OpenAI\Contracts\ClientContract::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\AudioService::class, fn ($app) => new \LaravelAIEngine\Services\Media\AudioService($app->make(\OpenAI\Contracts\ClientContract::class), $app->make(CreditManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\VideoService::class, fn ($app) => new \LaravelAIEngine\Services\Media\VideoService($app->make(\LaravelAIEngine\Services\Media\AudioService::class), $app->make(\LaravelAIEngine\Services\Media\VisionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Media\DocumentService::class, fn () => new \LaravelAIEngine\Services\Media\DocumentService());
        $app->singleton(\LaravelAIEngine\Services\Media\MediaEmbeddingService::class, fn ($app) => new \LaravelAIEngine\Services\Media\MediaEmbeddingService($app->make(\LaravelAIEngine\Services\Vector\EmbeddingService::class)));

        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAuthorizationService::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAuthorizationService());
        $app->singleton(\LaravelAIEngine\Services\Vector\ChunkingService::class, fn () => new \LaravelAIEngine\Services\Vector\ChunkingService());
        $app->singleton(\LaravelAIEngine\Services\Vector\VectorAnalyticsService::class, fn () => new \LaravelAIEngine\Services\Vector\VectorAnalyticsService());
    }
}
