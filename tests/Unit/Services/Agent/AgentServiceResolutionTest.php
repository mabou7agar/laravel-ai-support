<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\GoalAgentService;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentExecutionService;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentPlanner;
use LaravelAIEngine\Services\Agent\SubAgents\SubAgentRegistry;
use LaravelAIEngine\Tests\UnitTestCase;

class AgentServiceResolutionTest extends UnitTestCase
{
    public function test_intent_router_resolves_from_container(): void
    {
        $this->assertInstanceOf(IntentRouter::class, $this->app->make(IntentRouter::class));
    }

    public function test_agent_orchestrator_resolves_with_intent_router_dependency(): void
    {
        $this->assertInstanceOf(AgentOrchestrator::class, $this->app->make(AgentOrchestrator::class));
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

    public function test_goal_agent_services_resolve_from_container(): void
    {
        $this->assertInstanceOf(SubAgentRegistry::class, $this->app->make(SubAgentRegistry::class));
        $this->assertInstanceOf(SubAgentPlanner::class, $this->app->make(SubAgentPlanner::class));
        $this->assertInstanceOf(SubAgentExecutionService::class, $this->app->make(SubAgentExecutionService::class));
        $this->assertInstanceOf(GoalAgentService::class, $this->app->make(GoalAgentService::class));
    }
}
