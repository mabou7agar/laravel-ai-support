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
        $app->singleton(\LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry($app));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentCapabilityRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentCapabilityRegistry($app));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSkillRegistry($app, $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSkillMatcher($app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner::class, fn () => new \LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner());
        $app->singleton(\LaravelAIEngine\Contracts\ConversationMemory::class, fn () => new \LaravelAIEngine\Services\Memory\CacheConversationMemory());
        $app->singleton(\LaravelAIEngine\Contracts\ActionAuditLogger::class, fn () => new \LaravelAIEngine\Services\Actions\NullActionAuditLogger());
        $app->singleton(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class, fn () => new \LaravelAIEngine\Services\Agent\ConversationContextCompactor());
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentAliasCacheService::class, fn () => new \LaravelAIEngine\Services\Agent\IntentAliasCacheService());
        $app->singleton(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class, fn () => new \LaravelAIEngine\Services\Agent\MessageRoutingClassifier());
        $app->singleton(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class, fn ($app) => new \LaravelAIEngine\Services\Agent\RoutingContextResolver($app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentRunPayloadSchemaVersioner::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentRunEventStreamService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\ActiveRunContinuationStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\ExplicitModeStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\SelectionReferenceStage($app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\DeterministicCommandStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\DeterministicCommandStage($app->make(\LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\MessageClassificationStage($app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class), $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\Stages\AIRouterStage($app->make(\LaravelAIEngine\Services\Agent\IntentRouter::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\Stages\FallbackConversationalStage::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Routing\RoutingPipeline(array_map(
            static fn (string $stage): \LaravelAIEngine\Contracts\RoutingStageContract => $app->make($stage),
            array_values((array) config('ai-agent.routing_pipeline.stages', []))
        )));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry($app));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner($app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService($app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler($app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler::class, fn ($app) => new \LaravelAIEngine\Services\Agent\SubAgents\ConversationalSubAgentHandler($app->make(\LaravelAIEngine\Services\Agent\AgentConversationService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\GoalAgentService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\GoalAgentService($app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner::class), $app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class, function () {
            $registry = new \LaravelAIEngine\Services\Agent\Tools\ToolRegistry();
            $registry->discoverFromConfig();
            if ((bool) config('ai-agent.goal_agent.register_sub_agent_tool', true) && !$registry->has('run_sub_agent')) {
                $registry->register('run_sub_agent', new \LaravelAIEngine\Services\Agent\Tools\RunSubAgentTool());
            }
            return $registry;
        });
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentOrchestrationInspector::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentOrchestrationInspector($app->make(\LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class)));

        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder($app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorToolExecutionService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService($app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder($app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy($app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)));
        $app->singleton(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService::class, fn ($app) => new \LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)
        ));
        $app->alias(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService::class, \LaravelAIEngine\Services\DataCollector\AutonomousCollectorService::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\CollectorConfigResolver::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\CollectorConfigResolver($app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorSessionService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Collectors\AutonomousCollectorTurnProcessor(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorPromptBuilder::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorToolCallParser::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorToolExecutionService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorConfirmationService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorSummaryRenderer::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorInputSchemaBuilder::class),
            $app->make(\LaravelAIEngine\Services\Agent\Collectors\CollectorReroutePolicy::class),
            $app->make(\LaravelAIEngine\Services\Localization\LocaleResourceService::class)
        ));

        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ValidateFieldTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SearchOptionsTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\SuggestValueTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Tools\ExplainFieldTool::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\ProjectAbilityScanner::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ProjectAbilityScanner(
            $app->make(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentManifestDoctor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentManifestDoctor(
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)
        ));

        $app->singleton(\LaravelAIEngine\Services\RAG\RAGDecisionEngine::class, function ($app) {
            return new \LaravelAIEngine\Services\RAG\RAGDecisionEngine(
                $app->make(\LaravelAIEngine\Services\AIEngineService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGChatService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionStateService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGPlannerService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGToolExecutionService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGStructuredDataService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionPolicy::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGContextService::class),
                $app->make(\LaravelAIEngine\Services\RAG\RAGModelMetadataService::class),
                $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)
            );
        });
        $app->singleton(\LaravelAIEngine\Services\RAG\RAGExecutionRouter::class, fn ($app) => new \LaravelAIEngine\Services\RAG\RAGExecutionRouter(
            $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionEngine::class),
            $app->make(\LaravelAIEngine\Contracts\RAGPipelineContract::class)
        ));

        $app->singleton(\LaravelAIEngine\Services\Agent\ContextManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ContextManager($app->make(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\WorkflowDiscoveryService::class, fn () => new \LaravelAIEngine\Services\Agent\WorkflowDiscoveryService());
        $app->singleton(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class, fn () => new \LaravelAIEngine\Services\Agent\SelectedEntityContextService());
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionRegistry::class, function () {
            $registry = new \LaravelAIEngine\Services\Actions\ActionRegistry();
            $registry->registerBatch((array) config('ai-agent.actions', []));
            $registry->registerProviders((array) config('ai-agent.action_providers', []));

            return $registry;
        });
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionOrchestrator::class, fn ($app) => new \LaravelAIEngine\Services\Actions\ActionOrchestrator(
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            (array) config('ai-agent.action_relation_resolvers', []),
            $app->make(\LaravelAIEngine\Contracts\ConversationMemory::class),
            $app->make(\LaravelAIEngine\Contracts\ActionAuditLogger::class)
        ));
        $app->singleton(\LaravelAIEngine\Contracts\ActionWorkflowHandler::class, fn ($app) => new \LaravelAIEngine\Services\Actions\DefaultActionWorkflowHandler(
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionOrchestrator::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionDraftService::class, fn ($app) => new \LaravelAIEngine\Services\Actions\ActionDraftService(
            $app->make(\LaravelAIEngine\Contracts\ActionWorkflowHandler::class),
            $app->make(\LaravelAIEngine\Contracts\ConversationMemory::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentRouter::class, fn ($app) => new \LaravelAIEngine\Services\Agent\IntentRouter($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class), $app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class), $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentPlanner::class, fn () => new \LaravelAIEngine\Services\Agent\AgentPlanner());
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentResponseFinalizer($app->make(\LaravelAIEngine\Services\Agent\ContextManager::class), $app->make(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\NodeSessionManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\NodeSessionManager($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class), $app->make(\LaravelAIEngine\Services\Node\NodeRouterService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSelectionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSelectionService($app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentActionExecutionService($app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class), $app->make(\LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), null, $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class), $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentConversationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentConversationService($app->make(\LaravelAIEngine\Services\AIEngineService::class), $app->make(\LaravelAIEngine\Services\RAG\RAGExecutionRouter::class), $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class), null, $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentExecutionFacade::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentExecutionFacade($app->make(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentConversationService::class), $app->make(\LaravelAIEngine\Services\Agent\NodeSessionManager::class), $app->make(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher($app->make(\LaravelAIEngine\Services\Agent\AgentExecutionFacade::class), $app->make(\LaravelAIEngine\Services\Agent\GoalAgentService::class), $app->make(\LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class), $app->make(\LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor($app->make(\LaravelAIEngine\Services\Agent\ContextManager::class), $app->make(\LaravelAIEngine\Services\Agent\IntentRouter::class), $app->make(\LaravelAIEngine\Services\Agent\AgentPlanner::class), $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class), $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class), $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionFacade::class), $app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class), $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class), $app->make(\LaravelAIEngine\Services\Agent\DeterministicAgentHandlerRegistry::class), $app->make(\LaravelAIEngine\Services\Agent\GoalAgentService::class), $app->make(\LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher::class), $app->make(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime($app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphInterruptMapper::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper($app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphInterruptMapper::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphEventMapper::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime($app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class), $app->make(\LaravelAIEngine\Services\Agent\ContextManager::class), $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class), $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient::class), $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager($app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class), $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime::class), $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class), $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService($app->make(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class)));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator::class, fn () => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator());
        $app->alias(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class, \LaravelAIEngine\Contracts\AgentRuntimeContract::class);
    }
}
