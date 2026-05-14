<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGPlannerService;
use LaravelAIEngine\Services\RAG\RAGDecisionPromptService;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\RAGExecutionRouter;
use LaravelAIEngine\Services\RAG\RAGToolExecutionService;
use LaravelAIEngine\Services\RAG\RAGAggregateService;
use LaravelAIEngine\Services\RAG\RAGModelMetadataService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;

class RAGServiceResolutionTest extends UnitTestCase
{
    public function test_rag_decision_state_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGDecisionStateService::class, $this->app->make(RAGDecisionStateService::class));
    }

    public function test_rag_decision_engine_resolves_with_state_service_dependency(): void
    {
        $this->assertInstanceOf(RAGDecisionEngine::class, $this->app->make(RAGDecisionEngine::class));
    }

    public function test_rag_execution_router_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGExecutionRouter::class, $this->app->make(RAGExecutionRouter::class));
    }

    public function test_rag_planner_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGPlannerService::class, $this->app->make(RAGPlannerService::class));
    }

    public function test_rag_tool_execution_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGToolExecutionService::class, $this->app->make(RAGToolExecutionService::class));
    }

    public function test_rag_decision_policy_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGDecisionPolicy::class, $this->app->make(RAGDecisionPolicy::class));
    }

    public function test_rag_structured_data_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            RAGStructuredDataService::class,
            $this->app->make(RAGStructuredDataService::class)
        );
    }

    public function test_rag_context_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(RAGContextService::class, $this->app->make(RAGContextService::class));
    }

    public function test_rag_model_metadata_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            RAGModelMetadataService::class,
            $this->app->make(RAGModelMetadataService::class)
        );
    }

    public function test_rag_aggregate_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            RAGAggregateService::class,
            $this->app->make(RAGAggregateService::class)
        );
    }

    public function test_rag_decision_prompt_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            RAGDecisionPromptService::class,
            $this->app->make(RAGDecisionPromptService::class)
        );
    }

    public function test_rag_decision_feedback_service_resolves_from_container(): void
    {
        $this->assertInstanceOf(
            RAGDecisionFeedbackService::class,
            $this->app->make(RAGDecisionFeedbackService::class)
        );
    }
}
