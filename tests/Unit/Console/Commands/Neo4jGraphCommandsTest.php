<?php

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseBuilderService;
use LaravelAIEngine\Services\Graph\GraphBenchmarkHistoryService;
use LaravelAIEngine\Services\Graph\GraphDriftDetectionService;
use LaravelAIEngine\Services\Graph\GraphQueryPlanner;
use LaravelAIEngine\Services\Graph\GraphRankingFeedbackService;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class Neo4jGraphCommandsTest extends UnitTestCase
{
    public function test_stats_command_renders_graph_statistics(): void
    {
        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')->times(3)->andReturn(
            ['success' => true, 'rows' => [['label' => 'Entity', 'total' => 4]], 'error' => null],
            ['success' => true, 'rows' => [['relation' => 'HAS_CHUNK', 'total' => 8]], 'error' => null],
            ['success' => true, 'rows' => [['name' => 'chunk_embedding_index', 'type' => 'VECTOR', 'entityType' => 'NODE', 'labelsOrTypes' => ['Chunk'], 'properties' => ['embedding'], 'state' => 'ONLINE']], 'error' => null],
        );
        $this->app->instance(Neo4jHttpTransport::class, $transport);

        $this->artisan('ai-engine:neo4j-stats')
            ->expectsOutput('Node labels')
            ->expectsOutput('Relations')
            ->expectsOutput('Indexes')
            ->assertSuccessful();
    }

    public function test_backend_status_command_reports_effective_backend_configuration(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.graph.neo4j.database', 'neo4j');
        config()->set('ai-engine.graph.neo4j.username', 'neo4j');
        config()->set('ai-engine.graph.neo4j.password', 'secret');
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', 'http://qdrant.test:6333');

        $this->artisan('ai-engine:backend-status')
            ->expectsOutput('Interpretation:')
            ->expectsOutput('- Reads currently prefer: neo4j_graph')
            ->expectsOutput('- Graph path is enabled.')
            ->expectsOutput('- Vector default driver is qdrant.')
            ->assertSuccessful();
    }

    public function test_backend_status_command_reports_fallback_reason_when_neo4j_is_not_fully_configured(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.graph.neo4j.database', 'neo4j');
        config()->set('ai-engine.graph.neo4j.username', '');
        config()->set('ai-engine.graph.neo4j.password', '');
        config()->set('ai-engine.vector.default_driver', 'qdrant');
        config()->set('ai-engine.vector.drivers.qdrant.host', 'http://qdrant.test:6333');

        $this->artisan('ai-engine:backend-status')
            ->expectsOutput('Interpretation:')
            ->expectsOutput('- Reads currently prefer: vector_qdrant')
            ->expectsOutput('- Graph path is enabled.')
            ->expectsOutput('- Neo4j fallback reason: neo4j_not_configured.')
            ->expectsOutput('- Vector default driver is qdrant.')
            ->assertSuccessful();
    }

    public function test_diagnose_command_reports_clean_graph(): void
    {
        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')->times(4)->andReturn(
            ['success' => true, 'rows' => [['total' => 0]], 'error' => null],
            ['success' => true, 'rows' => [['total' => 0]], 'error' => null],
            ['success' => true, 'rows' => [['total' => 0]], 'error' => null],
            ['success' => true, 'rows' => [['total' => 0]], 'error' => null],
        );
        $this->app->instance(Neo4jHttpTransport::class, $transport);

        $this->artisan('ai-engine:neo4j-diagnose')
            ->expectsOutput('No graph integrity issues detected.')
            ->assertSuccessful();
    }

    public function test_repair_command_reports_dry_run_counts(): void
    {
        $transport = Mockery::mock(Neo4jHttpTransport::class);
        $transport->shouldReceive('executeStatement')->times(3)->andReturn(
            ['success' => true, 'rows' => [['total' => 2]], 'error' => null],
            ['success' => true, 'rows' => [['total' => 0]], 'error' => null],
            ['success' => true, 'rows' => [['total' => 1]], 'error' => null],
        );
        $this->app->instance(Neo4jHttpTransport::class, $transport);

        $this->artisan('ai-engine:neo4j-repair')
            ->expectsOutput('Dry-run only. Use --apply to execute repairs.')
            ->assertSuccessful();
    }

    public function test_kb_warm_command_warms_from_profiles_and_results_when_scope_is_provided(): void
    {
        $kb = Mockery::mock(GraphKnowledgeBaseService::class);
        $kb->shouldReceive('listQueryProfiles')->once()->andReturn([
            [
                'query' => 'who owns apollo?',
                'collections' => ['App\\Models\\Project'],
                'signals' => ['selected_entity_key' => null],
            ],
        ]);
        $kb->shouldReceive('rememberPlan')
            ->once()
            ->withArgs(function (string $query, array $collections, array $scope, array $signals, callable $resolver): bool {
                $this->assertSame('who owns apollo?', $query);
                $this->assertSame(['App\\Models\\Project'], $collections);
                $this->assertSame('7', $scope['canonical_user_id'] ?? null);
                $this->assertSame(['selected_entity_key' => null], $signals);
                $plan = $resolver();
                $this->assertSame('ownership', $plan['query_kind'] ?? null);

                return true;
            })
            ->andReturn(['strategy' => 'graph_traversal', 'query_kind' => 'ownership']);

        $planner = Mockery::mock(GraphQueryPlanner::class);
        $planner->shouldReceive('plan')->once()->andReturn([
            'strategy' => 'graph_traversal',
            'query_kind' => 'ownership',
        ]);

        $retrieval = Mockery::mock(Neo4jRetrievalService::class);
        $retrieval->shouldReceive('retrieveRelevantContext')
            ->once()
            ->withArgs(function (array $queries, array $collections, int $maxResults, array $options, $userId): bool {
                $this->assertSame(['who owns apollo?'], $queries);
                $this->assertSame(['App\\Models\\Project'], $collections);
                $this->assertSame(5, $maxResults);
                $this->assertSame('7', $options['access_scope']['canonical_user_id'] ?? null);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn(collect());

        $this->app->instance(GraphKnowledgeBaseService::class, $kb);
        $this->app->instance(GraphQueryPlanner::class, $planner);
        $this->app->instance(Neo4jRetrievalService::class, $retrieval);

        $this->artisan('ai-engine:neo4j-kb-warm --from-profiles --canonical-user-id=7')
            ->expectsOutput('Warmed 1 graph plan cache entry.')
            ->expectsOutput('Warmed 1 scoped retrieval cache entry.')
            ->assertSuccessful();
    }

    public function test_kb_build_command_runs_background_build_tasks(): void
    {
        $builder = Mockery::mock(GraphKnowledgeBaseBuilderService::class);
        $builder->shouldReceive('warmFromProfiles')
            ->once()
            ->withArgs(function (array $scope, int $limit, int $maxResults, bool $planOnly, ?int $userId): bool {
                $this->assertSame('7', $scope['canonical_user_id'] ?? null);
                $this->assertSame(10, $limit);
                $this->assertSame(4, $maxResults);
                $this->assertFalse($planOnly);
                $this->assertNull($userId);

                return true;
            })
            ->andReturn(['plans' => 3, 'results' => 2]);
        $builder->shouldReceive('buildEntitySnapshots')
            ->once()
            ->withArgs(function (array $scope, int $limit): bool {
                $this->assertSame('7', $scope['canonical_user_id'] ?? null);
                $this->assertSame(12, $limit);

                return true;
            })
            ->andReturn(['snapshots' => 5]);

        $this->app->instance(GraphKnowledgeBaseBuilderService::class, $builder);

        $this->artisan('ai-engine:neo4j-kb-build --canonical-user-id=7 --profiles-limit=10 --entity-limit=12 --max-results=4')
            ->expectsTable(['Artifact', 'Count'], [
                ['plans_warmed', 3],
                ['results_warmed', 2],
                ['entity_snapshots', 5],
            ])
            ->expectsOutput('Graph knowledge-base build completed.')
            ->assertSuccessful();
    }

    public function test_benchmark_command_reports_planner_and_retrieval_metrics(): void
    {
        $planner = Mockery::mock(GraphQueryPlanner::class);
        $planner->shouldReceive('plan')
            ->times(2)
            ->andReturn([
                'strategy' => 'semantic_graph_planner',
                'query_kind' => 'relationship',
                'cypher_template' => 'relationship_neighborhood',
            ]);

        $first = (object) [
            'vector_metadata' => [],
        ];
        $second = (object) [
            'vector_metadata' => ['graph_kb_cache_hit' => true],
        ];

        $retrieval = Mockery::mock(Neo4jRetrievalService::class);
        $retrieval->shouldReceive('retrieveRelevantContext')
            ->times(2)
            ->andReturn(collect([$first]), collect([$second]));

        $this->app->instance(GraphQueryPlanner::class, $planner);
        $this->app->instance(Neo4jRetrievalService::class, $retrieval);

        $this->artisan('ai-engine:neo4j-benchmark', [
            'query' => 'who replied to apollo?',
            '--iterations' => 2,
            '--max-results' => 3,
        ])
            ->expectsOutput('Neo4j graph benchmark completed.')
            ->assertSuccessful();
    }

    public function test_chat_benchmark_command_reports_chat_metrics(): void
    {
        $chat = Mockery::mock(ChatService::class);
        $chat->shouldReceive('processMessage')
            ->times(2)
            ->andReturn(
                AIResponse::success(
                    content: 'Apollo changed on Friday.',
                    metadata: [
                        'route_mode' => 'semantic_retrieval',
                        'tool_used' => 'vector_search',
                        'planner_query_kind' => 'timeline',
                        'sources' => [['id' => 1]],
                    ]
                ),
                AIResponse::success(
                    content: 'Apollo changed on Friday.',
                    metadata: [
                        'route_mode' => 'semantic_retrieval',
                        'tool_used' => 'vector_search',
                        'planner_query_kind' => 'timeline',
                        'sources' => [['id' => 1], ['id' => 2]],
                    ]
                )
            );

        $this->app->instance(ChatService::class, $chat);

        $this->artisan('ai-engine:chat-benchmark', [
            'message' => 'what changed on friday for apollo?',
            '--iterations' => 2,
            '--engine' => 'openai',
            '--model' => 'gpt-4o-mini',
        ])
            ->expectsOutput('Chat benchmark completed.')
            ->assertSuccessful();
    }

    public function test_index_benchmark_command_reports_publish_metrics(): void
    {
        Schema::create('graph_benchmark_records', function ($table): void {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->timestamps();
        });

        GraphBenchmarkRecord::query()->create(['title' => 'Apollo']);
        GraphBenchmarkRecord::query()->create(['title' => 'Friday']);

        $sync = Mockery::mock(Neo4jGraphSyncService::class);
        $sync->shouldReceive('ensureSchema')->once()->andReturn(true);
        $sync->shouldReceive('buildEntityPayload')->times(2)->andReturn(
            ['chunks' => [['content' => 'A']], 'relations' => [['type' => 'HAS_PROJECT']]],
            ['chunks' => [['content' => 'B'], ['content' => 'C']], 'relations' => []],
        );
        $sync->shouldReceive('publish')->times(2)->andReturn(true);
        $this->app->instance(Neo4jGraphSyncService::class, $sync);

        $this->artisan('ai-engine:neo4j-index-benchmark', [
            'model' => GraphBenchmarkRecord::class,
            '--iterations' => 1,
            '--limit' => 2,
        ])
            ->expectsOutput('Neo4j index benchmark completed.')
            ->assertSuccessful();
    }

    public function test_benchmark_history_command_renders_recorded_entries(): void
    {
        $history = app(GraphBenchmarkHistoryService::class);
        $history->record('retrieval', [
            'query' => 'who owns apollo?',
            'avg_ms' => 44.2,
            'details' => 'strategy=ownership',
        ]);

        $exitCode = Artisan::call('ai-engine:benchmark-history', [
            '--type' => 'retrieval',
            '--limit' => 5,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('retrieval', $output);
        $this->assertStringContainsString('who owns apollo?', $output);
        $this->assertStringContainsString('44.2', $output);
    }

    public function test_drift_command_renders_report_and_repair_summary(): void
    {
        $drift = Mockery::mock(GraphDriftDetectionService::class);
        $drift->shouldReceive('scan')->once()->andReturn([
            'models' => [[
                'model' => 'App\\Models\\Project',
                'local_total' => 2,
                'graph_total' => 3,
                'missing_in_graph' => 1,
                'stale_in_graph' => 1,
            ]],
            'totals' => [
                'local_entities' => 2,
                'graph_entities' => 3,
                'missing_in_graph' => 1,
                'stale_in_graph' => 1,
            ],
        ]);
        $drift->shouldReceive('repair')->once()->andReturn([
            'published' => 1,
            'pruned' => 1,
        ]);
        $this->app->instance(GraphDriftDetectionService::class, $drift);

        $this->artisan('ai-engine:neo4j-drift', ['--repair' => true, '--prune' => true])
            ->expectsOutput('Repair completed. Published 1 missing entities and pruned 1 stale entities.')
            ->assertSuccessful();
    }

    public function test_load_benchmark_command_reports_metrics(): void
    {
        $retrieval = Mockery::mock(Neo4jRetrievalService::class);
        $retrieval->shouldReceive('retrieveRelevantContext')->times(4)->andReturn(collect());
        $this->app->instance(Neo4jRetrievalService::class, $retrieval);

        $this->artisan('ai-engine:neo4j-load-benchmark', [
            '--mode' => 'retrieval',
            '--iterations' => 4,
            '--concurrency' => 1,
            '--query' => ['who owns apollo?'],
        ])
            ->expectsOutput('Neo4j load benchmark completed.')
            ->assertSuccessful();
    }

    public function test_load_benchmark_command_supports_profile_presets(): void
    {
        $retrieval = Mockery::mock(Neo4jRetrievalService::class);
        $retrieval->shouldReceive('retrieveRelevantContext')->times(5)->andReturn(collect());
        $this->app->instance(Neo4jRetrievalService::class, $retrieval);

        $this->artisan('ai-engine:neo4j-load-benchmark', [
            '--profile' => 'smoke',
            '--concurrency' => 1,
        ])
            ->expectsOutput('Neo4j load benchmark completed.')
            ->assertSuccessful();
    }

    public function test_graph_ranking_feedback_command_renders_state(): void
    {
        $feedback = app(GraphRankingFeedbackService::class);
        $feedback->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);

        $this->artisan('ai-engine:graph-ranking-feedback', [
            'query_kind' => 'relationship',
        ])
            ->expectsOutputToContain('relationship')
            ->assertSuccessful();
    }
}

class GraphBenchmarkRecord extends Model
{
    protected $table = 'graph_benchmark_records';

    protected $guarded = [];

    public $timestamps = true;
}
