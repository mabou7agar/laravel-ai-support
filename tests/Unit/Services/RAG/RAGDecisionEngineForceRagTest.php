<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGPlannerService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGChatService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class RAGDecisionEngineForceRagTest extends UnitTestCase
{
    public function test_force_rag_bypasses_ai_tool_decision_and_executes_vector_search(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragService = Mockery::mock(RAGChatService::class);
        $stateService = Mockery::mock(RAGDecisionStateService::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $stateService->shouldReceive('hydrateOptionsWithLastEntityList')
            ->once()
            ->with('session-force', ['force_rag' => true, 'session_id' => 'session-force'])
            ->andReturn(['force_rag' => true, 'session_id' => 'session-force']);

        $decisionService->shouldNotReceive('decide');
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $ragService->shouldReceive('processMessage')
            ->once()
            ->with(
                'find Apollo changes',
                'session-force',
                [],
                [],
                Mockery::on(function (array $options): bool {
                    return ($options['force_rag'] ?? false) === true
                        && ($options['user_id'] ?? null) === 42
                        && ($options['rag_collections'] ?? null) === [];
                }),
                42
            )
            ->andReturn(AIResponse::success(
                'Apollo changed on Friday.',
                'openai',
                'gpt-4o-mini',
                ['rag_enabled' => true, 'sources' => [['id' => 1]]]
            ));

        $agent = new RAGDecisionEngine(
            ai: $ai,
            ragService: $ragService,
            stateService: $stateService,
            decisionService: $decisionService
        );

        $result = $agent->process('find Apollo changes', 'session-force', 42, [], [
            'force_rag' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('vector_search', $result['tool']);
        $this->assertSame('Apollo changed on Friday.', $result['response']);
        $this->assertTrue($result['metadata']['rag_enabled']);
        $this->assertCount(1, $result['metadata']['sources']);
    }

    public function test_preclassified_structured_query_bypasses_ai_tool_decision_and_executes_db_query(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $stateService = Mockery::mock(RAGDecisionStateService::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $structuredDataService = Mockery::mock(RAGStructuredDataService::class);
        $contextService = Mockery::mock(RAGContextService::class);

        $stateService->shouldReceive('hydrateOptionsWithLastEntityList')
            ->once()
            ->with('session-structured', [
                'preclassified_route_mode' => 'structured_query',
                'session_id' => 'session-structured',
            ])
            ->andReturn([
                'preclassified_route_mode' => 'structured_query',
                'session_id' => 'session-structured',
            ]);

        $contextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'models' => [
                    ['name' => 'task', 'class' => 'App\\Models\\Task'],
                ],
                'selected_entity' => null,
                'last_entity_list' => null,
            ]);

        $decisionService->shouldNotReceive('decide');
        $decisionService->shouldReceive('fallbackDecisionForMessage')
            ->once()
            ->andReturn([
                'tool' => 'db_query',
                'reasoning' => 'preclassified structured_query; bypassing AI tool selection; Structured data request inferred from message',
                'parameters' => [
                    'model' => 'task',
                    'limit' => 10,
                ],
                'decision_source' => 'heuristic',
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $structuredDataService->shouldReceive('query')
            ->once()
            ->withArgs(function (array $params, $userId, array $options, ...$rest): bool {
                return ($params['model'] ?? null) === 'task'
                    && $userId === 42
                    && ($options['preclassified_route_mode'] ?? null) === 'structured_query';
            })
            ->andReturn([
                'success' => true,
                'response' => '1. Review Apollo dependencies',
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

        $result = $agent->process('list all open tasks', 'session-structured', 42, [], [
            'preclassified_route_mode' => 'structured_query',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('db_query', $result['tool']);
        $this->assertSame('1. Review Apollo dependencies', $result['response']);
    }
}
