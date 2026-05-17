<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Contracts\RAGRetrieverContract;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\RAGCitation;
use LaravelAIEngine\DTOs\RAGSource;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGCollectionResolver;
use LaravelAIEngine\Services\RAG\RAGContextBuilder;
use LaravelAIEngine\Services\RAG\RAGPipeline;
use LaravelAIEngine\Services\RAG\RAGPromptBuilder;
use LaravelAIEngine\Services\RAG\RAGQueryAnalyzer;
use LaravelAIEngine\Services\RAG\RAGResponseGenerator;
use LaravelAIEngine\Services\RAG\RAGRetriever;
use LaravelAIEngine\Services\RAG\Retrievers\VectorRAGRetriever;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class TestRAGRetriever implements RAGRetrieverContract
{
    public function name(): string
    {
        return 'vector';
    }

    public function retrieve(array $queries, array $collections, array $options = [], int|string|null $userId = null): array
    {
        return [new RAGSource(
            type: 'vector',
            content: 'Invoice 10 status is paid.',
            id: '10',
            title: 'Invoice 10',
            score: 0.99,
            citations: [RAGCitation::fromArray([
                'type' => 'vector',
                'title' => 'Invoice 10',
                'url' => 'invoice://10',
            ])]
        )];
    }
}

class RAGPipelineTest extends UnitTestCase
{
    public function test_resolves_collections_retrieves_sources_and_returns_citations(): void
    {
        Event::fake([AgentRunStreamed::class]);

        $pipeline = new RAGPipeline(
            new RAGQueryAnalyzer(),
            new RAGCollectionResolver(),
            new RAGRetriever([new TestRAGRetriever()]),
            new RAGContextBuilder(),
            new RAGPromptBuilder(),
            new RAGResponseGenerator()
        );

        $response = $pipeline->answer('Where is invoice 10?', [
            'rag_collections' => [['class' => 'App\\Models\\Invoice']],
            'response' => 'Invoice 10 is paid.',
        ], 7);

        $this->assertTrue($response->success);
        $this->assertSame('Invoice 10 is paid.', $response->message);
        $this->assertSame(['App\\Models\\Invoice'], $response->metadata['rag_collections']);
        $this->assertSame(1, $response->metadata['rag_result_count']);
        $this->assertSame(['vector'], $response->metadata['rag_source_types']);
        $this->assertSame('invoice://10', $response->metadata['citations'][0]['url']);

        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $e): bool => $e->event['name'] === 'rag.started');
        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $e): bool => $e->event['name'] === 'rag.sources_found'
            && ($e->event['payload']['result_count'] ?? null) === 1);
        Event::assertDispatched(AgentRunStreamed::class, fn (AgentRunStreamed $e): bool => $e->event['name'] === 'rag.completed');
    }

    public function test_normalizes_vector_graph_hybrid_sql_aggregate_and_provider_file_search_sources(): void
    {
        $vector = RAGSource::fromMixed((object) [
            'id' => 10,
            'content' => 'Vector chunk',
            'vector_score' => 0.9,
            'vector_metadata' => ['title' => 'Invoice'],
        ], 'vector');

        $graph = RAGSource::fromMixed(['content' => 'Graph fact', 'sources' => [['type' => 'graph', 'title' => 'Neo4j']]], 'graph');
        $hybrid = RAGSource::fromMixed(['content' => 'Hybrid fact', 'metadata' => ['hybrid_sources' => ['vector', 'graph']]], 'hybrid');
        $sql = RAGSource::fromMixed(['summary' => '10 invoices', 'citation_title' => 'SQL aggregate'], 'sql_aggregate');
        $provider = RAGSource::providerFileSearch(['file_id' => 'file_1', 'filename' => 'report.pdf', 'text' => 'Provider quote']);

        $this->assertSame('vector', $vector->type);
        $this->assertSame('Invoice', $vector->title);
        $this->assertSame('graph', $graph->citations[0]->type);
        $this->assertSame('hybrid', $hybrid->type);
        $this->assertSame('sql_aggregate', $sql->type);
        $this->assertSame('provider_file_search', $provider->type);
        $this->assertSame('file_1', $provider->citations[0]->sourceId);
    }

    public function test_normalizes_arrayable_vector_search_result_with_matched_chunk_text_taking_precedence(): void
    {
        $source = RAGSource::fromMixed(new class implements Arrayable {
            public function toArray(): array
            {
                return [
                    'id' => 1,
                    'title' => 'Apollo Handoff Notes',
                    'content' => 'Stored model content should be secondary.',
                    'vector_score' => 0.82,
                    'metadata' => ['fixture' => 'orchestration-demo'],
                    'vector_metadata' => [
                        'model_id' => 1,
                        'title' => 'Apollo Handoff Notes',
                        'chunk_text' => 'Apollo needs invoice, task, artifact, and customer context.',
                    ],
                    'matched_chunk_text' => 'Apollo matched chunk text.',
                ];
            }
        }, 'vector');

        $this->assertSame('Apollo matched chunk text.', $source->content);
        $this->assertSame('1', $source->id);
        $this->assertSame('Apollo Handoff Notes', $source->title);
        $this->assertSame('orchestration-demo', $source->metadata['fixture']);
        $this->assertSame('Apollo needs invoice, task, artifact, and customer context.', $source->metadata['chunk_text']);
        $this->assertEqualsWithDelta(0.82, $source->score, 0.000001);
        $this->assertSame('Apollo Handoff Notes', $source->citations[0]->title);
        $this->assertSame('1', $source->citations[0]->sourceId);
    }

    public function test_passes_agent_scope_filters_through_to_the_vector_search_driver(): void
    {
        config()->set('vector-access-control.enable_tenant_scope', true);
        config()->set('vector-access-control.enable_workspace_scope', true);

        $vector = Mockery::mock(VectorSearchService::class);
        $vector->shouldReceive('search')
            ->once()
            ->with(
                'App\\Models\\Document',
                'scoped query',
                5,
                0.3,
                ['tenant_id' => 'tenant-1', 'workspace_id' => 'workspace-1'],
                7
            )
            ->andReturn(collect([
                ['id' => 1, 'content' => 'Scoped document', 'metadata' => ['title' => 'Doc']],
            ]));

        $sources = (new VectorRAGRetriever($vector))->retrieve(
            ['scoped query'],
            ['App\\Models\\Document'],
            ['tenant_id' => 'tenant-1', 'workspace_id' => 'workspace-1'],
            7
        );

        $this->assertSame('Scoped document', $sources[0]->content);
    }

    public function test_synthesizes_an_answer_via_the_ai_engine_when_generate_answer_is_enabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success('INV-LIVE-2003 is the blocker.'));

        $response = (new RAGResponseGenerator($ai))->generate(
            'Answer using context',
            ['sources' => [], 'citations' => []],
            ['generate_answer' => true, 'engine' => 'openai', 'model' => 'gpt-4o-mini', 'user_id' => 9]
        );

        $this->assertSame('INV-LIVE-2003 is the blocker.', $response->message);
        $this->assertTrue($response->metadata['rag_answer_generated']);
    }
}
