<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestService::class, fn () => new \LaravelAIEngine\Services\Agent\AgentManifestService());
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestEditorService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentManifestEditorService($app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)));

        $app->singleton(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\AgentCollectionAdapter(
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\ModelAnalyzer::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\Agent\AgentMode::class, fn () => new \LaravelAIEngine\Services\Agent\AgentMode());
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class, function () {
            $registry = new \LaravelAIEngine\Services\Agent\Tools\ToolRegistry();
            $registry->discoverFromConfig();
            return $registry;
        });

        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class);

        $app->singleton(\LaravelAIEngine\Services\RAG\AutonomousRAGAgent::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\AutonomousRAGAgent(
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(\LaravelAIEngine\Services\RAG\IntelligentRAGService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGStateService::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGPolicy::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGContextService::class),
                $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\Agent\ContextManager::class, fn () => new \LaravelAIEngine\Services\Agent\ContextManager());
        $app->singleton(\LaravelAIEngine\Services\Agent\WorkflowDiscoveryService::class, fn () => new \LaravelAIEngine\Services\Agent\WorkflowDiscoveryService());
        $app->singleton(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class, fn () => new \LaravelAIEngine\Services\Agent\SelectedEntityContextService());
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentRouter::class, fn ($app) => new \LaravelAIEngine\Services\Agent\IntentRouter($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentPlanner::class, fn () => new \LaravelAIEngine\Services\Agent\AgentPlanner());
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentResponseFinalizer($app->make(\LaravelAIEngine\Services\Agent\ContextManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\NodeSessionManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\NodeSessionManager($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRouterService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSelectionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSelectionService($app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentActionExecutionService($app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class), $app->make(\LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), null, $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentConversationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentConversationService($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\RAG\AutonomousRAGAgent::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentExecutionFacade::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentExecutionFacade($app->make(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentConversationService::class), $app->make(\LaravelAIEngine\Services\Agent\NodeSessionManager::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentOrchestrator::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentOrchestrator($app->make(\LaravelAIEngine\Services\Agent\ContextManager::class), $app->make(\LaravelAIEngine\Services\Agent\IntentRouter::class), $app->make(\LaravelAIEngine\Services\Agent\AgentPlanner::class), $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionFacade::class)));
    }
}
