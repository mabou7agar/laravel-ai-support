<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Services\RAG\AutonomousRAGContextService;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionService;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionPromptService;
use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\AutonomousRAGExecutionService;
use LaravelAIEngine\Services\RAG\AutonomousRAGAggregateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGModelMetadataService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Services\RAG\AutonomousRAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousRAGStateServiceResolutionTest extends UnitTestCase
{
    public function test_autonomous_rag_state_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AutonomousRAGStateService::class, $this->app->make(AutonomousRAGStateService::class));
    }

    public function test_autonomous_rag_agent_resolves_with_state_service_dependency(): void
    {
        $this->assertInstanceOf(AutonomousRAGAgent::class, $this->app->make(AutonomousRAGAgent::class));
    }

    public function test_autonomous_rag_decision_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AutonomousRAGDecisionService::class, $this->app->make(AutonomousRAGDecisionService::class));
    }

    public function test_autonomous_rag_execution_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AutonomousRAGExecutionService::class, $this->app->make(AutonomousRAGExecutionService::class));
    }

    public function test_autonomous_rag_policy_resolves_from_container(): void
    {
        $this->assertInstanceOf(AutonomousRAGPolicy::class, $this->app->make(AutonomousRAGPolicy::class));
    }

    public function test_autonomous_rag_structured_data_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            AutonomousRAGStructuredDataService::class,
            $this->app->make(AutonomousRAGStructuredDataService::class)
        );
    }

    public function test_autonomous_rag_context_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(AutonomousRAGContextService::class, $this->app->make(AutonomousRAGContextService::class));
    }

    public function test_autonomous_rag_model_metadata_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            AutonomousRAGModelMetadataService::class,
            $this->app->make(AutonomousRAGModelMetadataService::class)
        );
    }

    public function test_autonomous_rag_aggregate_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            AutonomousRAGAggregateService::class,
            $this->app->make(AutonomousRAGAggregateService::class)
        );
    }

    public function test_autonomous_rag_decision_prompt_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            AutonomousRAGDecisionPromptService::class,
            $this->app->make(AutonomousRAGDecisionPromptService::class)
        );
    }

    public function test_autonomous_rag_decision_feedback_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            AutonomousRAGDecisionFeedbackService::class,
            $this->app->make(AutonomousRAGDecisionFeedbackService::class)
        );
    }
}
