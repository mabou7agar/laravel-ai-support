<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\Tools\SearchKnowledgeTool;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGDecisionStateService;
use LaravelAIEngine\Services\RAG\RAGModelMetadataService;
use LaravelAIEngine\Services\RAG\RAGPlannerService;
use LaravelAIEngine\Services\RAG\RAGStructuredDataService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Generated coverage for the RAG vector / search_knowledge surface.
 *
 * Targets:
 *  - RAGDecisionEngine route-mode bypass (semantic_retrieval / contextual_follow_up)
 *  - force_rag decision_source='forced' + reasoning payload to recordExecutionOutcome
 *  - vectorSearch model-scoped collection resolution (known + unknown model)
 *  - vectorSearch pipeline exception -> logged failure result + failure outcome
 *  - structured_query precedence over force_rag
 *  - SearchKnowledgeTool reroute closure + limit clamping
 *  - answerFromContext partial-selection guard
 *
 * All collaborators are mocked; no real LLM/network/DB calls are made.
 */
class RagVectorFlowTest extends UnitTestCase
{
    /**
     * Build an engine whose state service passes options through unchanged so the
     * routing branches under test are exercised verbatim.
     */
    private function passthroughStateService(): RAGDecisionStateService
    {
        $stateService = Mockery::mock(RAGDecisionStateService::class);
        $stateService->shouldReceive('hydrateOptionsWithLastEntityList')
            ->andReturnUsing(fn (string $sessionId, array $options): array => $options);

        return $stateService;
    }

    // -----------------------------------------------------------------
    // Scenario: route-mode semantic_retrieval success -> vector_search
    // -----------------------------------------------------------------
    public function test_route_mode_semantic_retrieval_runs_vector_search_with_heuristic_source_and_policy_limit(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $decisionService->shouldNotReceive('decide');

        $capturedDecision = null;
        $decisionService->shouldReceive('recordExecutionOutcome')
            ->once()
            ->andReturnUsing(function (array $decision) use (&$capturedDecision): void {
                $capturedDecision = $decision;
            });

        $ragPipeline->shouldReceive('process')
            ->once()
            ->with(
                'what is our refund policy',
                'sess-sem',
                [],
                [],
                Mockery::on(fn (array $options): bool => ($options['user_id'] ?? null) === 42),
                42
            )
            ->andReturn(AIResponse::success(
                'Refunds within 14 days',
                'openai',
                'gpt-4o-mini',
                ['rag_enabled' => true]
            ));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService
        );

        $result = $engine->process('what is our refund policy', 'sess-sem', 42, [], [
            'preclassified_route_mode' => 'semantic_retrieval',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('vector_search', $result['tool']);
        $this->assertSame('Refunds within 14 days', $result['response']);

        // Heuristic source + reasoning + policy limit pinned on the recorded decision.
        $this->assertSame('heuristic', $capturedDecision['decision_source']);
        $this->assertSame('preclassified semantic_retrieval; bypassing AI tool selection', $capturedDecision['reasoning']);
        $this->assertSame(10, $capturedDecision['parameters']['limit']);
    }

    // -----------------------------------------------------------------
    // Scenario: contextual_follow_up route mode -> vector_search,
    // forwarding non-empty conversation history (4th positional arg)
    // -----------------------------------------------------------------
    public function test_contextual_follow_up_route_mode_bypasses_to_vector_search_and_forwards_history(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $history = [
            ['role' => 'user', 'content' => 'what is our refund policy'],
            ['role' => 'assistant', 'content' => 'Refunds within 14 days'],
        ];

        $decisionService->shouldNotReceive('decide');

        $capturedDecision = null;
        $decisionService->shouldReceive('recordExecutionOutcome')
            ->once()
            ->andReturnUsing(function (array $decision) use (&$capturedDecision): void {
                $capturedDecision = $decision;
            });

        $ragPipeline->shouldReceive('process')
            ->once()
            ->with(
                'and what about it for enterprise plans',
                'sess-fu',
                [],
                $history, // non-empty conversation history forwarded as 4th positional arg
                Mockery::type('array'),
                7
            )
            ->andReturn(AIResponse::success('Enterprise refunds within 30 days', 'openai', 'gpt-4o-mini', []));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService
        );

        $result = $engine->process('and what about it for enterprise plans', 'sess-fu', 7, $history, [
            'preclassified_route_mode' => 'contextual_follow_up',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('vector_search', $result['tool']);
        $this->assertSame('heuristic', $capturedDecision['decision_source']);
        $this->assertStringContainsString('preclassified contextual_follow_up', $capturedDecision['reasoning']);
    }

    // -----------------------------------------------------------------
    // Scenario: force_rag records decision_source='forced' + reasoning
    // -----------------------------------------------------------------
    public function test_force_rag_records_forced_decision_source_and_reasoning(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $decisionService->shouldNotReceive('decide');

        $capturedDecision = null;
        $decisionService->shouldReceive('recordExecutionOutcome')
            ->once()
            ->andReturnUsing(function (array $decision) use (&$capturedDecision): void {
                $capturedDecision = $decision;
            });

        $ragPipeline->shouldReceive('process')
            ->once()
            ->andReturn(AIResponse::success('Onboarding doc summary', 'openai', 'gpt-4o-mini', []));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService
        );

        $result = $engine->process('summarize the onboarding doc', 'sess-forced', 42, [], [
            'force_rag' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('forced', $capturedDecision['decision_source']);
        $this->assertSame('force_rag enabled; bypassing AI tool selection', $capturedDecision['reasoning']);
    }

    // -----------------------------------------------------------------
    // Scenario: model-scoped collection filtering, known model wins
    // over caller-supplied rag_collections (AI-decision path supplies
    // params['model'] which the bypass path never sets).
    // -----------------------------------------------------------------
    public function test_vector_search_known_model_overrides_caller_rag_collections(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);
        $modelMetadata = Mockery::mock(RAGModelMetadataService::class);

        $contextService->shouldReceive('build')->once()->andReturn(['models' => []]);

        // AI decides vector_search with an explicit model param.
        $decisionService->shouldReceive('decide')
            ->once()
            ->andReturn([
                'tool' => 'vector_search',
                'reasoning' => 'ai chose vector search',
                'parameters' => ['query' => 'invoice questions', 'model' => 'invoice'],
                'decision_source' => 'ai',
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $modelMetadata->shouldReceive('findModelClass')
            ->once()
            ->with('invoice', Mockery::type('array'))
            ->andReturn('App\\Models\\Invoice');

        $ragPipeline->shouldReceive('process')
            ->once()
            ->with(
                'invoice questions',
                'sess-model',
                ['App\\Models\\Invoice'], // collections overridden by the resolved model class
                Mockery::type('array'),
                Mockery::on(fn (array $o): bool => ($o['rag_collections'] ?? null) === ['App\\Models\\Invoice']),
                42
            )
            ->andReturn(AIResponse::success('Invoice answer', 'openai', 'gpt-4o-mini', []));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService,
            contextService: $contextService,
            modelMetadata: $modelMetadata
        );

        $result = $engine->process('invoice questions', 'sess-model', 42, [], [
            'rag_collections' => ['App\\Models\\Doc'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('vector_search', $result['tool']);
    }

    // -----------------------------------------------------------------
    // Scenario: unknown model falls back to caller rag_collections
    // -----------------------------------------------------------------
    public function test_vector_search_unknown_model_falls_back_to_caller_rag_collections(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);
        $modelMetadata = Mockery::mock(RAGModelMetadataService::class);

        $contextService->shouldReceive('build')->once()->andReturn(['models' => []]);

        $decisionService->shouldReceive('decide')
            ->once()
            ->andReturn([
                'tool' => 'vector_search',
                'reasoning' => 'ai chose vector search',
                'parameters' => ['query' => 'widget docs', 'model' => 'nonexistent_widget'],
                'decision_source' => 'ai',
            ]);
        $decisionService->shouldReceive('recordExecutionOutcome')->once();

        $modelMetadata->shouldReceive('findModelClass')
            ->once()
            ->with('nonexistent_widget', Mockery::type('array'))
            ->andReturnNull();

        $ragPipeline->shouldReceive('process')
            ->once()
            ->with(
                'widget docs',
                'sess-unknown',
                ['App\\Models\\Article'], // original rag_collections preserved (no override)
                Mockery::type('array'),
                Mockery::on(fn (array $o): bool => ($o['rag_collections'] ?? null) === ['App\\Models\\Article']),
                42
            )
            ->andReturn(AIResponse::success('Article answer', 'openai', 'gpt-4o-mini', []));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService,
            contextService: $contextService,
            modelMetadata: $modelMetadata
        );

        $result = $engine->process('widget docs', 'sess-unknown', 42, [], [
            'rag_collections' => ['App\\Models\\Article'],
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('vector_search', $result['tool']);
    }

    // -----------------------------------------------------------------
    // Scenario: pipeline exception -> logged failure + failure outcome
    // -----------------------------------------------------------------
    public function test_vector_search_pipeline_exception_returns_logged_failure_and_records_failure_outcome(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);

        $decisionService->shouldNotReceive('decide');

        $capturedResult = null;
        $decisionService->shouldReceive('recordExecutionOutcome')
            ->once()
            ->andReturnUsing(function (array $decision, array $result) use (&$capturedResult): void {
                $capturedResult = $result;
            });

        $ragPipeline->shouldReceive('process')
            ->once()
            ->andThrow(new \RuntimeException('qdrant timeout'));

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService
        );

        $result = $engine->process('about Apollo', 'sess-boom', 42, [], [
            'force_rag' => true,
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame('qdrant timeout', $result['error']);

        // The failing result is what RAG decision feedback sees.
        $this->assertFalse($capturedResult['success']);
        $this->assertSame('qdrant timeout', $capturedResult['error']);
    }

    // -----------------------------------------------------------------
    // Scenario: structured_query precedence over force_rag
    // -----------------------------------------------------------------
    public function test_structured_query_route_mode_wins_over_force_rag(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragPipeline = Mockery::mock(RAGPipelineContract::class);
        $decisionService = Mockery::mock(RAGPlannerService::class);
        $contextService = Mockery::mock(RAGContextService::class);
        $structuredDataService = Mockery::mock(RAGStructuredDataService::class);

        // RAG pipeline must never be touched: structured_query short-circuits first.
        $ragPipeline->shouldNotReceive('process');
        $decisionService->shouldNotReceive('decide');

        $contextService->shouldReceive('build')->once()->andReturn(['models' => []]);

        $decisionService->shouldReceive('fallbackDecisionForMessage')
            ->once()
            ->andReturn([
                'tool' => 'db_count',
                'reasoning' => 'preclassified structured_query; bypassing AI tool selection',
                'parameters' => ['model' => 'task'],
                'decision_source' => 'heuristic',
            ]);

        $capturedDecision = null;
        $decisionService->shouldReceive('recordExecutionOutcome')
            ->once()
            ->andReturnUsing(function (array $decision) use (&$capturedDecision): void {
                $capturedDecision = $decision;
            });

        $structuredDataService->shouldReceive('count')
            ->once()
            ->andReturn(['success' => true, 'response' => '3 open tasks', 'tool' => 'db_count', 'count' => 3]);

        $engine = new RAGDecisionEngine(
            ai: $ai,
            ragPipeline: $ragPipeline,
            stateService: $this->passthroughStateService(),
            decisionService: $decisionService,
            structuredDataService: $structuredDataService,
            contextService: $contextService
        );

        $result = $engine->process('how many open tasks', 'sess-prec', 42, [], [
            'preclassified_route_mode' => 'structured_query',
            'force_rag' => true,
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('db_count', $result['tool']);
        $this->assertSame('heuristic', $capturedDecision['decision_source']);
    }

    // -----------------------------------------------------------------
    // Scenario: answerFromContext partial-selection guard.
    // Both cases: selectedId<=0 OR blank type -> no db fallback, generic failure.
    // -----------------------------------------------------------------
    public function test_answer_from_context_partial_selection_does_not_fall_back_to_db_query(): void
    {
        foreach ([
            ['entity_type' => 'invoice', 'entity_id' => 0],   // id<=0
            ['entity_type' => '', 'entity_id' => 55],          // blank type
        ] as $selectedEntity) {
            $ai = Mockery::mock(AIEngineService::class);
            $decisionService = Mockery::mock(RAGPlannerService::class);
            $contextService = Mockery::mock(RAGContextService::class);
            $structuredDataService = Mockery::mock(RAGStructuredDataService::class);

            $contextService->shouldReceive('build')->once()->andReturn(['models' => []]);

            // AI picks answer_from_context but provides NO 'answer' param.
            $decisionService->shouldReceive('decide')
                ->once()
                ->andReturn([
                    'tool' => 'answer_from_context',
                    'reasoning' => 'answer from context',
                    'parameters' => [],
                    'decision_source' => 'ai',
                ]);
            $decisionService->shouldReceive('recordExecutionOutcome')->once();

            // The DB fallback must never fire for a partial selection.
            $structuredDataService->shouldNotReceive('query');

            $engine = new RAGDecisionEngine(
                ai: $ai,
                stateService: $this->passthroughStateService(),
                decisionService: $decisionService,
                structuredDataService: $structuredDataService,
                contextService: $contextService
            );

            $result = $engine->process('what about it', 'sess-afc', 42, [], [
                'selected_entity' => $selectedEntity,
            ]);

            $this->assertFalse($result['success']);
            $this->assertSame('No answer provided in parameters', $result['error']);
        }
    }

    // =================================================================
    // SearchKnowledgeTool
    // =================================================================

    // -----------------------------------------------------------------
    // Scenario: reroute closure returns benign conversational fallback
    // -----------------------------------------------------------------
    public function test_search_knowledge_reroute_closure_returns_conversational_fallback(): void
    {
        $context = new UnifiedActionContext('sess-reroute', 7);

        $capturedReroute = null;
        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->andReturnUsing(function ($query, $ctx, $options, $reroute) use (&$capturedReroute, $context) {
                $capturedReroute = $reroute;

                return AgentResponse::conversational('grounded answer', $context);
            });

        $tool = new SearchKnowledgeTool($conversation);
        $result = $tool->execute(['query' => 'delete my last invoice'], $context);

        $this->assertTrue($result->success);
        $this->assertIsCallable($capturedReroute);

        // Invoke the captured reroute callback exactly how executeSearchRAG would.
        $rerouted = ($capturedReroute)('do the delete', 'sess-reroute', 7, []);

        $this->assertInstanceOf(AgentResponse::class, $rerouted);
        $this->assertSame(
            'The knowledge base did not hold a direct answer; this request may need an action tool instead of retrieval.',
            $rerouted->message
        );
        $this->assertSame($context, $rerouted->context);
    }

    // -----------------------------------------------------------------
    // Scenario: limit clamping edge cases
    // -----------------------------------------------------------------
    public function test_search_knowledge_limit_clamping_edge_cases(): void
    {
        $cases = [
            ['limit' => 0, 'expected' => 1, 'present' => true],
            ['limit' => -5, 'expected' => 1, 'present' => true],
            ['limit' => 9999, 'expected' => 50, 'present' => true],
            ['limit' => 'abc', 'expected' => null, 'present' => false], // non-numeric ignored
            ['limit' => '12', 'expected' => 12, 'present' => true],      // numeric string accepted
        ];

        foreach ($cases as $case) {
            $context = new UnifiedActionContext('sess-clamp', 7);

            $conversation = Mockery::mock(AgentConversationService::class);
            $conversation->shouldReceive('executeSearchRAG')
                ->once()
                ->with(
                    'x',
                    $context,
                    Mockery::on(function (array $options) use ($case): bool {
                        if ($case['present'] === false) {
                            return !array_key_exists('limit', $options);
                        }

                        return ($options['limit'] ?? null) === $case['expected'];
                    }),
                    Mockery::type('callable')
                )
                ->andReturn(AgentResponse::conversational('ok', $context));

            $tool = new SearchKnowledgeTool($conversation);
            $result = $tool->execute(['query' => 'x', 'limit' => $case['limit']], $context);

            $this->assertTrue($result->success, 'limit case ' . var_export($case['limit'], true));
        }
    }
}
