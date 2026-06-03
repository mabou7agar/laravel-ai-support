<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGPlannerService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RagPathAnswerFromContextTest extends UnitTestCase
{
    private function ragPathStateMock(string $sessionId, array $inOptions): RAGDecisionStateService
    {
        $stateService = Mockery::mock(RAGDecisionStateService::class);
        $stateService->shouldReceive('hydrateOptionsWithLastEntityList')
            ->once()
            ->andReturnUsing(function (string $sid, array $opts) use ($inOptions) {
                return array_merge($inOptions, $opts);
            });

        return $stateService;
    }

    public function test_answer_from_context_fast_path_returns_answer_directly(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = $this->ragPathStateMock('session-fast', []);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);

        $contextService->shouldReceive('build')->once()->andReturn([
            'models' => [],
            'selected_entity' => null,
            'last_entity_list' => null,
        ]);

        $decisionService->shouldReceive('decide')
            ->once()
            ->andReturn([
                'tool' => 'answer_from_context',
                'reasoning' => 'answerable from history',
                'parameters' => ['answer' => 'Apollo ships on Friday.'],
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $agent = new RAGDecisionEngine(
            ai: $ai,
            stateService: $stateService,
            decisionService: $decisionService,
            contextService: $contextService
        );

        $result = $agent->process('when does Apollo ship?', 'session-fast', 7, []);

        $this->assertTrue($result['success']);
        $this->assertSame('answer_from_context', $result['tool']);
        $this->assertSame('Apollo ships on Friday.', $result['response']);
        $this->assertTrue($result['fast_path']);
    }

    public function test_answer_from_context_falls_back_to_selected_entity_db_query(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = $this->ragPathStateMock('session-entity', [
            'selected_entity' => ['entity_id' => 99, 'entity_type' => 'Task'],
        ]);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);
        $structuredDataService = Mockery::mock(RAGStructuredDataService::class);

        $contextService->shouldReceive('build')->once()->andReturn([
            'models' => [],
            'selected_entity' => ['entity_id' => 99, 'entity_type' => 'Task'],
            'last_entity_list' => null,
        ]);

        // No 'answer' parameter -> triggers selected-entity fallback.
        $decisionService->shouldReceive('decide')
            ->once()
            ->andReturn([
                'tool' => 'answer_from_context',
                'reasoning' => 'no direct answer',
                'parameters' => [],
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $structuredDataService->shouldReceive('query')
            ->once()
            ->withArgs(function (array $params, $userId, array $options, ...$rest): bool {
                return ($params['model'] ?? null) === 'task'
                    && ($params['filters']['id'] ?? null) === 99
                    && ($params['limit'] ?? null) === 1
                    && $userId === 7;
            })
            ->andReturn([
                'success' => true,
                'response' => 'Task #99 details',
                'tool' => 'db_query',
                'count' => 1,
            ]);

        $agent = new RAGDecisionEngine(
            ai: $ai,
            stateService: $stateService,
            decisionService: $decisionService,
            structuredDataService: $structuredDataService,
            contextService: $contextService
        );

        $result = $agent->process('show it', 'session-entity', 7, []);

        $this->assertTrue($result['success']);
        $this->assertSame('db_query', $result['tool']);
        $this->assertSame('Task #99 details', $result['response']);
    }

    public function test_answer_from_context_without_answer_or_entity_returns_failure(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = $this->ragPathStateMock('session-fail', []);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);

        $contextService->shouldReceive('build')->once()->andReturn([
            'models' => [],
            'selected_entity' => null,
            'last_entity_list' => null,
        ]);

        $decisionService->shouldReceive('decide')
            ->once()
            ->andReturn([
                'tool' => 'answer_from_context',
                'reasoning' => 'nothing usable',
                'parameters' => [],
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $agent = new RAGDecisionEngine(
            ai: $ai,
            stateService: $stateService,
            decisionService: $decisionService,
            contextService: $contextService
        );

        $result = $agent->process('huh?', 'session-fail', 7, []);

        $this->assertFalse($result['success']);
        $this->assertSame('No answer provided in parameters', $result['error']);
    }

    public function test_semantic_retrieval_with_null_pipeline_returns_service_unavailable(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = $this->ragPathStateMock('session-no-pipeline', [
            'preclassified_route_mode' => 'semantic_retrieval',
        ]);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        // Bypass path: no AI tool decision should be requested.
        $decisionService->shouldNotReceive('decide');
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $agent = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: null,
            stateService: $stateService,
            decisionService: $decisionService
        );

        $result = $agent->process('find similar tickets', 'session-no-pipeline', 7, [], [
            'preclassified_route_mode' => 'semantic_retrieval',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RAG service not available', $result['error']);
    }

    public function test_force_rag_with_null_pipeline_returns_service_unavailable(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = $this->ragPathStateMock('session-force-null', [
            'force_rag' => true,
        ]);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $decisionService->shouldNotReceive('decide');
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $agent = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: null,
            stateService: $stateService,
            decisionService: $decisionService
        );

        $result = $agent->process('find similar tickets', 'session-force-null', 7, [], [
            'force_rag' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('RAG service not available', $result['error']);
    }
}
