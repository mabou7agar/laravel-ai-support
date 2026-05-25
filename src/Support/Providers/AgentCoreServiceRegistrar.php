<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentCoreServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestService::class, fn () => new \LaravelAIEngine\Services\Agent\AgentManifestService());
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestEditorService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentManifestEditorService(
            $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)
        ));

        $app->singleton(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class, function ($app) {
            return new \LaravelAIEngine\Services\Agent\AgentCollectionAdapter(
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\ModelAnalyzer::class)
            );
        });

        $app->singleton(\LaravelAIEngine\Services\Agent\AgentCapabilityRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentCapabilityRegistry($app));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSkillRegistry(
            $app,
            $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSkillMatcher(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\AIEngineService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner::class, fn () => new \LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner());
        $app->singleton(\LaravelAIEngine\Services\Agent\ResponsePointExtractor::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentResponseSuggestionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentResponseSuggestionService(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class),
            $app->make(\LaravelAIEngine\Contracts\ActionFlowHandler::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\ChatResponsePresentationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ChatResponsePresentationService(
            $app->make(\LaravelAIEngine\Services\Agent\ResponsePointExtractor::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentResponseSuggestionService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\StructuredCollectionCallbackService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\StructuredCollectionFieldPresenter::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\StructuredCollectionPreviewRenderer::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\StructuredCollectionSessionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\StructuredCollectionSessionService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Agent\StructuredCollectionCallbackService::class),
            $app->make(\LaravelAIEngine\Services\Agent\StructuredCollectionFieldPresenter::class),
            $app->make(\LaravelAIEngine\Services\Agent\StructuredCollectionPreviewRenderer::class),
            $app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)
        ));
        $app->singleton(\LaravelAIEngine\Contracts\ConversationMemory::class, fn () => new \LaravelAIEngine\Services\Memory\CacheConversationMemory());
        $app->singleton(\LaravelAIEngine\Contracts\ActionAuditLogger::class, fn () => new \LaravelAIEngine\Services\Actions\NullActionAuditLogger());
        $app->singleton(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ConversationContextCompactor(
            $app->make(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy::class),
            $app->make(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor::class),
            $app->make(\LaravelAIEngine\Repositories\ConversationMemoryRepository::class),
            $app->make(\LaravelAIEngine\Services\Agent\Memory\ConversationMemorySemanticIndex::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentAliasCacheService::class, fn () => new \LaravelAIEngine\Services\Agent\IntentAliasCacheService());
        $app->singleton(\LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AiNative\ToolResultAuthorityService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder(
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\IntentSignalService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AiNative\AiNativePromptBuilder::class),
            $app->make(\LaravelAIEngine\Services\Agent\AiNative\AiNativeResponseParser::class),
            $app->make(\LaravelAIEngine\Services\Agent\AiNative\ToolResultAuthorityService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentIntentUnderstandingService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentIntentUnderstandingService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentSignalService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\IntentSignalService(
            $app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentIntentUnderstandingService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class, fn ($app) => new \LaravelAIEngine\Services\Agent\MessageRoutingClassifier(
            $app->make(\LaravelAIEngine\Services\Agent\AgentIntentUnderstandingService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class, fn ($app) => new \LaravelAIEngine\Services\Agent\RoutingContextResolver(
            $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunPayloadSchemaVersioner::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentRunEventStreamService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\ActiveRunContinuationStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\ExplicitModeStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\AgentSkillMatchStage(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage(
            $app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class),
            $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage(
            $app->make(\LaravelAIEngine\Services\Agent\IntentRouter::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\FallbackConversationalStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\RoutingPipeline(array_map(
            static fn (string $stage): \LaravelAIEngine\Contracts\RoutingStageContract => $app->make($stage),
            array_values((array) config('ai-agent.routing_pipeline.stages', []))
        )));
    }
}
