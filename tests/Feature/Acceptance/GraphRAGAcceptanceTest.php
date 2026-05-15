<?php

namespace LaravelAIEngine\Tests\Feature\Acceptance;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGCollectionResolver;
use LaravelAIEngine\Services\RAG\RAGContextBuilder;
use LaravelAIEngine\Services\RAG\RAGPipeline;
use LaravelAIEngine\Services\RAG\RAGPromptBuilder;
use LaravelAIEngine\Services\RAG\RAGQueryAnalyzer;
use LaravelAIEngine\Services\RAG\RAGResponseGenerator;
use LaravelAIEngine\Services\RAG\RAGRetriever;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class GraphRAGAcceptanceTest extends UnitTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('ai-engine.user_model', User::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->json('entity_credits')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function test_graph_mode_process_message_returns_cross_app_sources_with_entity_ref_and_object(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $user = new User();
        $user->fill([
            'name' => 'Graph User',
            'email' => 'graph-user@example.com',
            'password' => null,
            'entity_credits' => json_encode([]),
        ]);
        $user->save();

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();
            $statement = $request->data()['statement'] ?? '';

            if (str_contains($statement, 'db.index.vector.queryNodes')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c', 'score'],
                        'values' => [
                            [[
                                'entity_key' => 'mail:App\\Models\\Mail:21',
                                'model_id' => 21,
                                'model_class' => 'App\\Models\\Mail',
                                'title' => 'Launch delay notice',
                                'rag_summary' => 'Mail about a launch delay',
                                'rag_detail' => 'Launch delay notice detail',
                                'source_node' => 'mail',
                                'app_slug' => 'mail',
                                'object_json' => json_encode([
                                    'id' => 21,
                                    'title' => 'Launch delay notice',
                                    'summary' => 'Mail about a launch delay',
                                ]),
                            ], ['slug' => 'mail', 'name' => 'Mail'], null, [
                                'chunk_index' => 0,
                                'content' => 'the launch is delayed until friday',
                                'content_preview' => 'the launch is delayed until friday',
                            ], 0.98],
                        ],
                    ],
                    'bookmarks' => ['bookmark-1'],
                ], 202);
            }

            if (str_contains($statement, 'UNWIND $hits AS hit')) {
                return Http::response([
                    'data' => [
                        'fields' => ['source_entity_key', 'source_score', 'path_length', 'relation_path', 'e', 'a', 's', 'chunks'],
                        'values' => [
                            [
                                'mail:App\\Models\\Mail:21',
                                0.98,
                                1,
                                ['BELONGS_TO'],
                                [
                                    'entity_key' => 'projects:App\\Models\\Project:7',
                                    'model_id' => 7,
                                    'model_class' => 'App\\Models\\Project',
                                    'title' => 'Apollo',
                                    'rag_summary' => 'Project Apollo timeline',
                                    'rag_detail' => 'Project Apollo timeline detail',
                                    'source_node' => 'projects',
                                    'app_slug' => 'projects',
                                    'object_json' => json_encode([
                                        'id' => 7,
                                        'title' => 'Apollo',
                                        'summary' => 'Project Apollo timeline',
                                    ]),
                                ],
                                ['slug' => 'projects', 'name' => 'Projects'],
                                ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                                [[
                                    'chunk_index' => 0,
                                    'content' => 'apollo milestone moved to friday',
                                    'content_preview' => 'apollo milestone moved to friday',
                                ]],
                            ],
                            [
                                'mail:App\\Models\\Mail:21',
                                0.98,
                                2,
                                ['BELONGS_TO', 'BELONGS_TO'],
                                [
                                    'entity_key' => 'tasks:App\\Models\\Task:81',
                                    'model_id' => 81,
                                    'model_class' => 'App\\Models\\Task',
                                    'title' => 'Prep release notes',
                                    'rag_summary' => 'Task linked to Apollo launch',
                                    'rag_detail' => 'Task linked to Apollo launch detail',
                                    'source_node' => 'tasks',
                                    'app_slug' => 'tasks',
                                    'object_json' => json_encode([
                                        'id' => 81,
                                        'title' => 'Prep release notes',
                                        'summary' => 'Task linked to Apollo launch',
                                    ]),
                                ],
                                ['slug' => 'tasks', 'name' => 'Tasks'],
                                ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                                [[
                                    'chunk_index' => 0,
                                    'content' => 'prepare release notes before the friday launch',
                                    'content_preview' => 'prepare release notes before the friday launch',
                                ]],
                            ],
                        ],
                    ],
                    'bookmarks' => ['bookmark-expand'],
                ], 202);
            }

            return Http::response([
                'data' => [
                    'fields' => ['entity_key', 'neighbors'],
                    'values' => [
                        ['mail:App\\Models\\Mail:21', [[
                            'relation_type' => 'BELONGS_TO',
                            'model_id' => 7,
                            'model_class' => 'App\\Models\\Project',
                            'title' => 'Apollo',
                            'rag_summary' => 'Project Apollo timeline',
                            'entity_key' => 'projects:App\\Models\\Project:7',
                        ]]],
                        ['projects:App\\Models\\Project:7', [[
                            'relation_type' => 'RELATED_TO',
                            'model_id' => 21,
                            'model_class' => 'App\\Models\\Mail',
                            'title' => 'Launch delay notice',
                            'rag_summary' => 'Mail about a launch delay',
                            'entity_key' => 'mail:App\\Models\\Mail:21',
                        ]]],
                    ],
                ],
                'bookmarks' => ['bookmark-2'],
            ], 202);
        });

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success(
                "The launch was delayed until Friday. [Source 0]",
                'openai',
                'gpt-4o-mini'
            ));

        $service = new RAGPipeline(
            new RAGQueryAnalyzer(),
            new RAGCollectionResolver(),
            $this->app->make(RAGRetriever::class),
            new RAGContextBuilder(),
            new RAGPromptBuilder(),
            new RAGResponseGenerator($ai)
        );

        $response = $service->process(
            'what changed on friday and who is it related to?',
            'graph-acceptance-session',
            ['App\\Models\\Mail', 'App\\Models\\Project'],
            [['role' => 'user', 'content' => 'what changed on friday and who is it related to?']],
            [
                'intent_analysis' => [
                    'intent' => 'retrieval',
                    'context_enhancement' => 'Need cross-app graph context',
                ],
                'model' => 'gpt-4o-mini',
                'generate_answer' => true,
            ],
            $user->id
        );

        $this->assertTrue($response->isSuccessful());
        $this->assertStringContainsString('delayed until Friday', $response->getContent());
        $sources = $response->getMetadata()['sources'] ?? [];
        $this->assertNotEmpty($sources);
        $this->assertContains('graph', array_column($sources, 'type'));
        $sourcesById = collect($sources)->keyBy(fn (array $source) => $source['metadata']['entity_ref']['model_id'] ?? $source['metadata']['model_id'] ?? $source['id'] ?? null);
        $mailSource = $sourcesById->get(21);
        $projectSource = $sourcesById->get(7);
        $taskSource = $sourcesById->get(81);
        $this->assertSame('mail', $mailSource['metadata']['app_slug'] ?? null);
        $this->assertSame(21, $mailSource['metadata']['entity_ref']['model_id'] ?? null);
        $this->assertSame('Launch delay notice', $mailSource['metadata']['object']['title'] ?? null);
        $this->assertSame('projects', $projectSource['metadata']['app_slug'] ?? null);
        $this->assertSame(7, $projectSource['metadata']['entity_ref']['model_id'] ?? null);
        $this->assertSame('Apollo', $projectSource['metadata']['object']['title'] ?? null);
        $this->assertSame('tasks', $taskSource['metadata']['app_slug'] ?? null);
        $this->assertSame(81, $taskSource['metadata']['entity_ref']['model_id'] ?? null);
        $this->assertSame('Prep release notes', $taskSource['metadata']['object']['title'] ?? null);
        $this->assertTrue((bool) ($projectSource['metadata']['graph_planned'] ?? false));
        $this->assertSame(['BELONGS_TO'], $projectSource['metadata']['relation_path'] ?? null);
        $this->assertSame(1, $projectSource['metadata']['path_length'] ?? null);
        $this->assertStringContainsString(
            'db.index.vector.queryNodes',
            $requests[0]['statement'] ?? ''
        );
        $this->assertTrue(collect($requests)->contains(fn (array $request): bool => str_contains(
            (string) ($request['statement'] ?? ''),
            'UNWIND $hits AS hit'
        )));
    }
}
