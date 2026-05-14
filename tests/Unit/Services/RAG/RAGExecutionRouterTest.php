<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGExecutionRouter;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RAGExecutionRouterTest extends UnitTestCase
{
    public function test_semantic_retrieval_uses_pipeline(): void
    {
        $decisionEngine = Mockery::mock(RAGDecisionEngine::class);
        $decisionEngine->shouldNotReceive('process');
        $pipeline = Mockery::mock(RAGPipelineContract::class);
        $context = new UnifiedActionContext('session-router-pipeline', 12);
        $context->conversationHistory = [['role' => 'user', 'content' => 'previous']];

        $pipeline->shouldReceive('answer')
            ->once()
            ->withArgs(function (string $message, array $options, int|string|null $userId): bool {
                return $message === 'find project launch notes'
                    && ($options['session_id'] ?? null) === 'session-router-pipeline'
                    && ($options['conversation_history'][0]['content'] ?? null) === 'previous'
                    && ($options['preclassified_route_mode'] ?? null) === 'semantic_retrieval'
                    && $userId === 12;
            })
            ->andReturn(AgentResponse::success('Pipeline answer.'));

        $result = (new RAGExecutionRouter($decisionEngine, $pipeline))->execute(
            'find project launch notes',
            $context,
            ['preclassified_route_mode' => 'semantic_retrieval']
        );

        $this->assertTrue($result->usesPipeline());
        $this->assertFalse($result->usesDecisionEngine());
        $this->assertSame('Pipeline answer.', $result->response?->message);
    }

    public function test_structured_query_uses_decision_engine(): void
    {
        $decisionEngine = Mockery::mock(RAGDecisionEngine::class);
        $pipeline = Mockery::mock(RAGPipelineContract::class);
        $pipeline->shouldNotReceive('answer');
        $context = new UnifiedActionContext('session-router-structured', 7);
        $context->conversationHistory = [['role' => 'user', 'content' => 'list invoices']];

        $decisionEngine->shouldReceive('process')
            ->once()
            ->with('list invoices', 'session-router-structured', 7, $context->conversationHistory, [
                'preclassified_route_mode' => 'structured_query',
            ])
            ->andReturn(['success' => true, 'response' => 'Structured answer.']);

        $result = (new RAGExecutionRouter($decisionEngine, $pipeline))->execute(
            'list invoices',
            $context,
            ['preclassified_route_mode' => 'structured_query']
        );

        $this->assertTrue($result->usesDecisionEngine());
        $this->assertSame('Structured answer.', $result->decisionResult['response']);
    }

    public function test_selected_entity_uses_decision_engine(): void
    {
        $decisionEngine = Mockery::mock(RAGDecisionEngine::class);
        $pipeline = Mockery::mock(RAGPipelineContract::class);
        $pipeline->shouldNotReceive('answer');
        $context = new UnifiedActionContext('session-router-selected', 'user-1');

        $decisionEngine->shouldReceive('process')
            ->once()
            ->andReturn(['success' => true, 'response' => 'Selected entity answer.']);

        $result = (new RAGExecutionRouter($decisionEngine, $pipeline))->execute(
            'show more about this',
            $context,
            ['selected_entity' => ['type' => 'project', 'id' => 5]]
        );

        $this->assertTrue($result->usesDecisionEngine());
        $this->assertSame('Selected entity answer.', $result->decisionResult['response']);
    }

    public function test_pipeline_route_returns_failure_when_pipeline_is_missing(): void
    {
        $decisionEngine = Mockery::mock(RAGDecisionEngine::class);
        $decisionEngine->shouldNotReceive('process');

        $result = (new RAGExecutionRouter($decisionEngine))->execute(
            'find notes',
            new UnifiedActionContext('session-missing-pipeline', 1),
            ['preclassified_route_mode' => 'semantic_retrieval']
        );

        $this->assertTrue($result->usesPipeline());
        $this->assertFalse($result->response?->success);
        $this->assertSame('RAG pipeline is not available.', $result->response?->message);
    }
}
