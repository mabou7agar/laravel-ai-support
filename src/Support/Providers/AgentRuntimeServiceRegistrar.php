<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentRuntimeServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Agent\ContextManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\ContextManager(
            $app->make(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class, fn () => new \LaravelAIEngine\Services\Agent\SelectedEntityContextService());
        $app->singleton(\LaravelAIEngine\Services\Agent\IntentRouter::class, fn ($app) => new \LaravelAIEngine\Services\Agent\IntentRouter(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class),
            $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class),
            $app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class),
            $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillRegistry::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillMatcher::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentPlanner::class, fn () => new \LaravelAIEngine\Services\Agent\AgentPlanner());
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentResponseFinalizer(
            $app->make(\LaravelAIEngine\Services\Agent\ContextManager::class),
            $app->make(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\NodeSessionManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\NodeSessionManager(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\Node\NodeRegistryService::class),
            $app->make(\LaravelAIEngine\Services\Node\NodeRouterService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentSelectionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentSelectionService(
            $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentActionExecutionService(
            $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class),
            null,
            $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Tools\ToolRegistry::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\AgentConversationService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\AgentConversationService(
            $app->make(\LaravelAIEngine\Services\AIEngineService::class),
            $app->make(\LaravelAIEngine\Services\RAG\RAGDecisionEngine::class),
            $app->make(\LaravelAIEngine\Services\Agent\SelectedEntityContextService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class),
            $app->make(\LaravelAIEngine\Contracts\RAGPipelineContract::class),
            null,
            $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class),
            $app->make(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever::class),
            $app->make(\LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher(
            $app->make(\LaravelAIEngine\Services\Agent\AgentActionExecutionService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentConversationService::class),
            $app->make(\LaravelAIEngine\Services\Agent\NodeSessionManager::class),
            $app->make(\LaravelAIEngine\Services\Agent\GoalAgentService::class),
            $app->make(\LaravelAIEngine\Services\ProviderTools\ProviderToolAuditService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor(
            $app->make(\LaravelAIEngine\Services\Agent\ContextManager::class),
            $app->make(\LaravelAIEngine\Services\Agent\IntentRouter::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentPlanner::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentResponseFinalizer::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentSelectionService::class),
            $app->make(\LaravelAIEngine\Services\Agent\NodeSessionManager::class),
            $app->make(\LaravelAIEngine\Services\Agent\MessageRoutingClassifier::class),
            $app->make(\LaravelAIEngine\Services\Agent\RoutingContextResolver::class),
            $app->make(\LaravelAIEngine\Services\Agent\GoalAgentService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher::class),
            $app->make(\LaravelAIEngine\Services\Agent\Routing\RoutingPipeline::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime(
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphInterruptMapper::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper(
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphInterruptMapper::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphEventMapper::class);
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime(
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class),
            $app->make(\LaravelAIEngine\Services\Agent\ContextManager::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class),
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRuntimeClient::class),
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphRunMapper::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager(
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime::class),
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\LangGraphAgentRuntime::class),
            $app->make(\LaravelAIEngine\Services\Agent\AgentExecutionPolicyService::class),
            $app->make(\LaravelAIEngine\Services\Scope\AIScopeOptionsService::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService::class, fn ($app) => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService(
            $app->make(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class)
        ));
        $app->singleton(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator::class, fn () => new \LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeConfigValidator());
        $app->alias(\LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager::class, \LaravelAIEngine\Contracts\AgentRuntimeContract::class);
    }
}
