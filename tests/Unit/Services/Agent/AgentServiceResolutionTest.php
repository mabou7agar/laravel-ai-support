<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\AgentManifestDoctor;
use LaravelAIEngine\Services\Agent\AgentOrchestrationInspector;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeManager;
use LaravelAIEngine\Services\Agent\AgentSkillExecutionPlanner;
use LaravelAIEngine\Services\Agent\AgentSkillMatcher;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Services\Agent\SubAgents\ToolCallingSubAgentHandler;
use LaravelAIEngine\Services\RAG\RAGPipeline;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentServiceResolutionTest extends UnitTestCase
{
    public function test_intent_router_resolves_from_container(): void
    {
        $this->assertInstanceOf(IntentRouter::class, $this->app->make(IntentRouter::class));
    }

    public function test_agent_processor_resolves_with_intent_router_dependency(): void
    {
        $this->assertInstanceOf(LaravelAgentProcessor::class, $this->app->make(LaravelAgentProcessor::class));
    }

    public function test_agent_runtime_contract_resolves_to_runtime_manager(): void
    {
        $this->assertInstanceOf(AgentRuntimeManager::class, $this->app->make(AgentRuntimeContract::class));
    }

    public function test_rag_pipeline_contract_resolves_to_v2_pipeline(): void
    {
        $this->assertInstanceOf(RAGPipeline::class, $this->app->make(RAGPipelineContract::class));
    }

    public function test_agent_planner_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentPlanner::class, $this->app->make(AgentPlanner::class));
    }

    public function test_agent_response_finalizer_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentResponseFinalizer::class, $this->app->make(AgentResponseFinalizer::class));
    }

    public function test_selected_entity_context_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(SelectedEntityContextService::class, $this->app->make(SelectedEntityContextService::class));
    }

    public function test_node_session_manager_resolves_from_container(): void
    {
        $this->assertInstanceOf(NodeSessionManager::class, $this->app->make(NodeSessionManager::class));
    }

    public function test_agent_selection_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentSelectionService::class, $this->app->make(AgentSelectionService::class));
    }

    public function test_agent_action_execution_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentActionExecutionService::class, $this->app->make(AgentActionExecutionService::class));
    }

    public function test_agent_conversation_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentConversationService::class, $this->app->make(AgentConversationService::class));
    }

    public function test_agent_execution_facade_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentExecutionFacade::class, $this->app->make(AgentExecutionFacade::class));
    }

    public function test_agent_execution_dispatcher_resolves_from_container(): void
    {
        $this->assertInstanceOf(AgentExecutionDispatcher::class, $this->app->make(AgentExecutionDispatcher::class));
    }

    public function test_goal_agent_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(SubAgentRegistry::class, $this->app->make(SubAgentRegistry::class));
        $this->assertInstanceOf(SubAgentPlanner::class, $this->app->make(SubAgentPlanner::class));
        $this->assertInstanceOf(SubAgentExecutionService::class, $this->app->make(SubAgentExecutionService::class));
        $this->assertInstanceOf(ToolCallingSubAgentHandler::class, $this->app->make(ToolCallingSubAgentHandler::class));
        $this->assertInstanceOf(GoalAgentService::class, $this->app->make(GoalAgentService::class));
    }

    public function test_skill_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(AgentSkillRegistry::class, $this->app->make(AgentSkillRegistry::class));
        $this->assertInstanceOf(AgentSkillMatcher::class, $this->app->make(AgentSkillMatcher::class));
        $this->assertInstanceOf(AgentSkillExecutionPlanner::class, $this->app->make(AgentSkillExecutionPlanner::class));
        $this->assertInstanceOf(AgentManifestDoctor::class, $this->app->make(AgentManifestDoctor::class));
        $this->assertInstanceOf(AgentOrchestrationInspector::class, $this->app->make(AgentOrchestrationInspector::class));
    }
}
