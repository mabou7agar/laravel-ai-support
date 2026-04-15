<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Tests\UnitTestCase;

class Neo4jRetrievalServiceTest extends UnitTestCase
{
    public function test_retrieve_relevant_context_uses_vector_query_and_returns_chunk_hits_with_neighbors(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $requests[] = $request->data();

            $statement = $request->data()['statement'] ?? '';

            if (str_contains($statement, 'db.index.vector.queryNodes')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c', 'score'],
                        'values' => [[
                            [
                                'entity_key' => 'mail:App\\Models\\Email:11',
                                'model_id' => 11,
                                'model_class' => 'App\\Models\\Email',
                                'title' => 'Launch Update',
                                'rag_summary' => 'Status update for launch',
                                'rag_detail' => 'Launch Update full detail',
                                'source_node' => 'mail',
                                'app_slug' => 'mail',
                                'object_json' => json_encode([
                                    'id' => 11,
                                    'title' => 'Launch Update',
                                    'summary' => 'Status update for launch',
                                ]),
                            ], [
                                'slug' => 'mail',
                                'name' => 'Mail',
                            ], null, [
                                'chunk_index' => 0,
                                'content' => 'launch status is delayed',
                                'content_preview' => 'launch status is delayed',
                            ], 0.97],
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
                                'mail:App\\Models\\Email:11',
                                0.97,
                                1,
                                ['BELONGS_TO'],
                                [
                                    'entity_key' => 'projects:App\\Models\\Project:7',
                                    'model_id' => 7,
                                    'model_class' => 'App\\Models\\Project',
                                    'title' => 'Apollo',
                                    'rag_summary' => 'Apollo project timeline',
                                    'rag_detail' => 'Apollo full detail',
                                    'source_node' => 'projects',
                                    'app_slug' => 'projects',
                                    'object_json' => json_encode([
                                        'id' => 7,
                                        'title' => 'Apollo',
                                        'summary' => 'Apollo project timeline',
                                    ]),
                                ],
                                ['slug' => 'projects', 'name' => 'Projects'],
                                ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                                [[
                                    'chunk_index' => 0,
                                    'content' => 'apollo timeline slipped to friday',
                                    'content_preview' => 'apollo timeline slipped to friday',
                                ]],
                            ],
                            [
                                'mail:App\\Models\\Email:11',
                                0.97,
                                2,
                                ['BELONGS_TO', 'BELONGS_TO'],
                                [
                                    'entity_key' => 'tasks:App\\Models\\Task:19',
                                    'model_id' => 19,
                                    'model_class' => 'App\\Models\\Task',
                                    'title' => 'Prep release notes',
                                    'rag_summary' => 'Release checklist task',
                                    'rag_detail' => 'Release checklist task detail',
                                    'source_node' => 'tasks',
                                    'app_slug' => 'tasks',
                                    'object_json' => json_encode([
                                        'id' => 19,
                                        'title' => 'Prep release notes',
                                        'summary' => 'Release checklist task',
                                    ]),
                                ],
                                ['slug' => 'tasks', 'name' => 'Tasks'],
                                ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                                [[
                                    'chunk_index' => 0,
                                    'content' => 'prepare release notes and ship checklist',
                                    'content_preview' => 'prepare release notes and ship checklist',
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
                        ['mail:App\\Models\\Email:11', [[
                            'relation_type' => 'BELONGS_TO',
                            'model_id' => 7,
                            'model_class' => 'App\\Models\\Project',
                            'title' => 'Apollo',
                            'rag_summary' => 'Apollo project timeline',
                            'entity_key' => 'projects:App\\Models\\Project:7',
                        ]]],
                        ['projects:App\\Models\\Project:7', [[
                            'relation_type' => 'HAS_RELATED',
                            'model_id' => 11,
                            'model_class' => 'App\\Models\\Email',
                            'title' => 'Launch Update',
                            'rag_summary' => 'Status update for launch',
                            'entity_key' => 'mail:App\\Models\\Email:11',
                        ]]],
                    ],
                ],
                'bookmarks' => ['bookmark-2'],
            ], 202);
        });

        $results = (new Neo4jRetrievalService())->retrieveRelevantContext(
            ['what changed on friday and who is it related to?'],
            ['App\\Models\\Email', 'App\\Models\\Project'],
            5,
            [],
            null
        );

        $this->assertCount(3, $results);
        $mail = $results->firstWhere('id', 11);
        $project = $results->firstWhere('id', 7);
        $task = $results->firstWhere('id', 19);
        $this->assertNotNull($mail);
        $this->assertGreaterThan(0.45, $mail->vector_score);
        $this->assertSame(0.97, $mail->vector_metadata['raw_vector_score']);
        $this->assertStringContainsString('launch status is delayed', $mail->matched_chunk_text);
        $this->assertSame('mail', $mail->vector_metadata['entity_ref']['app_slug']);
        $this->assertCount(1, $mail->graph_neighbors);
        $this->assertStringContainsString('Apollo', $mail->matched_chunk_text);
        $this->assertNotNull($project);
        $this->assertTrue($project->vector_metadata['graph_planned'] ?? false);
        $this->assertTrue($project->vector_metadata['relation_expanded'] ?? false);
        $this->assertSame('BELONGS_TO', $project->vector_metadata['relation_type'] ?? null);
        $this->assertSame(['BELONGS_TO'], $project->vector_metadata['relation_path'] ?? null);
        $this->assertSame('projects', $project->vector_metadata['entity_ref']['app_slug']);
        $this->assertSame('timeline', $project->vector_metadata['planner_query_kind'] ?? null);
        $this->assertNotEmpty($project->vector_metadata['planner_score_breakdown'] ?? []);
        $this->assertNotEmpty($project->vector_metadata['cypher_plan_signature'] ?? null);
        $this->assertStringContainsString('template=', (string) ($project->vector_metadata['cypher_plan_explanation'] ?? ''));
        $this->assertNotNull($task);
        $this->assertTrue($task->vector_metadata['graph_planned'] ?? false);
        $this->assertTrue($task->vector_metadata['relation_expanded'] ?? false);
        $this->assertSame(2, $task->vector_metadata['path_length'] ?? null);
        $this->assertSame(['BELONGS_TO', 'BELONGS_TO'], $task->vector_metadata['relation_path'] ?? null);
        $this->assertStringContainsString('[BELONGS_TO -> BELONGS_TO]', $task->matched_chunk_text);
        $this->assertIsArray($task->vector_metadata['planner_filters'] ?? null);
        $this->assertStringContainsString(
            'db.index.vector.queryNodes',
            $requests[0]['statement'] ?? ''
        );
        $this->assertCount(8, $requests[0]['parameters']['embedding'] ?? []);
        $this->assertTrue(collect($requests)->contains(function (array $request): bool {
            return str_contains((string) ($request['statement'] ?? ''), '[:CAN_ACCESS]->(:Scope)<-[:BELONGS_TO]-(e)');
        }));
    }

    public function test_retrieve_relevant_context_discovers_existing_vector_index_when_configured_name_is_missing(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'missing_chunk_index');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $payload = $request->data();
            $requests[] = $payload;
            $statement = $payload['statement'] ?? '';

            if (str_contains($statement, 'SHOW VECTOR INDEXES')) {
                return Http::response([
                    'data' => [
                        'fields' => ['name', 'options'],
                        'values' => [[
                            'chunk_embedding_live_test',
                            ['indexConfig' => ['vector.dimensions' => 8]],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-show-indexes'],
                ], 202);
            }

            if (str_contains($statement, 'db.index.vector.queryNodes')) {
                $indexName = $payload['parameters']['index_name'] ?? null;

                if ($indexName === 'missing_chunk_index') {
                    return Http::response([
                        'data' => [
                            'fields' => ['e', 'a', 's', 'c', 'score'],
                            'values' => [],
                        ],
                        'errors' => [[
                            'code' => 'Neo.ClientError.Procedure.ProcedureCallFailed',
                            'message' => 'Failed to invoke procedure `db.index.vector.queryNodes`: Caused by: java.lang.IllegalArgumentException: There is no such vector schema index: missing_chunk_index',
                        ]],
                    ], 202);
                }

                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c', 'score'],
                        'values' => [[
                            [
                                'entity_key' => 'mail:App\\Models\\Email:12',
                                'model_id' => 12,
                                'model_class' => 'App\\Models\\Email',
                                'title' => 'Friday change',
                                'rag_summary' => 'Mail explaining the Friday change',
                                'rag_detail' => 'Friday change detail',
                                'source_node' => 'mail',
                                'app_slug' => 'mail',
                                'object_json' => json_encode([
                                    'id' => 12,
                                    'title' => 'Friday change',
                                    'summary' => 'Mail explaining the Friday change',
                                ]),
                            ], [
                                'slug' => 'mail',
                                'name' => 'Mail',
                            ], null, [
                                'chunk_index' => 0,
                                'content' => 'the change was moved to friday',
                                'content_preview' => 'the change was moved to friday',
                            ], 0.91],
                        ],
                    ],
                    'bookmarks' => ['bookmark-vector'],
                ], 202);
            }

            if (str_contains($statement, 'UNWIND $hits AS hit')) {
                return Http::response([
                    'data' => [
                        'fields' => ['source_entity_key', 'source_score', 'path_length', 'relation_path', 'e', 'a', 's', 'chunks'],
                        'values' => [],
                    ],
                    'bookmarks' => ['bookmark-expand'],
                ], 202);
            }

            return Http::response([
                'data' => [
                    'fields' => ['entity_key', 'neighbors'],
                    'values' => [],
                ],
                'bookmarks' => ['bookmark-neighbors'],
            ], 202);
        });

        $results = (new Neo4jRetrievalService())->retrieveRelevantContext(
            ['what changed on friday'],
            ['App\\Models\\Email'],
            3,
            [],
            null
        );

        $this->assertCount(1, $results);
        $this->assertSame(12, $results->first()->id);
        $this->assertTrue(collect($requests)->contains(fn (array $request): bool => str_contains(
            (string) ($request['statement'] ?? ''),
            'SHOW VECTOR INDEXES'
        )));
        $this->assertTrue(collect($requests)->contains(fn (array $request): bool => ($request['parameters']['index_name'] ?? null) === 'chunk_embedding_live_test'));
    }

    public function test_retrieve_relevant_context_ignores_incompatible_discovered_vector_indexes(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'missing_chunk_index');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $payload = $request->data();
            $requests[] = $payload;
            $statement = $payload['statement'] ?? '';

            if (str_contains($statement, 'SHOW VECTOR INDEXES')) {
                return Http::response([
                    'data' => [
                        'fields' => ['name', 'options'],
                        'values' => [[
                            'chunk_embedding_live_test',
                            ['indexConfig' => ['vector.dimensions' => 64]],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-show-indexes'],
                ], 202);
            }

            if (str_contains($statement, 'db.index.vector.queryNodes')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c', 'score'],
                        'values' => [],
                    ],
                    'errors' => [[
                        'code' => 'Neo.ClientError.Procedure.ProcedureCallFailed',
                        'message' => 'Failed to invoke procedure `db.index.vector.queryNodes`: Caused by: java.lang.IllegalArgumentException: There is no such vector schema index: missing_chunk_index',
                    ]],
                ], 202);
            }

            if (str_contains($statement, 'MATCH (e:Entity)-[:HAS_CHUNK]->(c:Chunk)')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c'],
                        'values' => [[
                            [
                                'entity_key' => 'mail:App\\Models\\Email:14',
                                'model_id' => 14,
                                'model_class' => 'App\\Models\\Email',
                                'title' => 'Fallback mail',
                                'rag_summary' => 'Fallback text search row',
                                'rag_detail' => 'Fallback text search row detail',
                                'source_node' => 'mail',
                                'app_slug' => 'mail',
                                'object_json' => json_encode(['id' => 14, 'title' => 'Fallback mail']),
                            ],
                            ['slug' => 'mail', 'name' => 'Mail'],
                            null,
                            ['chunk_index' => 0, 'content' => 'fallback row', 'content_preview' => 'fallback row'],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-text-fallback'],
                ], 202);
            }

            if (str_contains($statement, 'UNWIND $hits AS hit')) {
                return Http::response([
                    'data' => [
                        'fields' => ['source_entity_key', 'source_score', 'path_length', 'relation_path', 'e', 'a', 's', 'chunks'],
                        'values' => [],
                    ],
                    'bookmarks' => ['bookmark-expand'],
                ], 202);
            }

            return Http::response([
                'data' => [
                    'fields' => ['entity_key', 'neighbors'],
                    'values' => [],
                ],
                'bookmarks' => ['bookmark-neighbors'],
            ], 202);
        });

        $results = (new Neo4jRetrievalService())->retrieveRelevantContext(
            ['what changed on friday'],
            ['App\\Models\\Email'],
            3,
            [],
            null
        );

        $this->assertCount(1, $results);
        $this->assertSame(14, $results->first()->id);
        $retriedWrongIndex = collect($requests)->contains(
            fn (array $request): bool => ($request['parameters']['index_name'] ?? null) === 'chunk_embedding_live_test'
        );
        $this->assertFalse($retriedWrongIndex);
    }

    public function test_retrieve_relevant_context_uses_last_entity_list_seeds_for_contextual_graph_planning(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $payload = $request->data();
            $requests[] = $payload;
            $statement = $payload['statement'] ?? '';

            if (str_contains($statement, 'db.index.vector.queryNodes')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'c', 'score'],
                        'values' => [],
                    ],
                    'bookmarks' => ['bookmark-empty-vector'],
                ], 202);
            }

            if (str_contains($statement, 'UNWIND $entity_keys AS entity_key')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'chunks', 'entity_key'],
                        'values' => [[
                            [
                                'entity_key' => 'projects:App\\Models\\Project:7',
                                'model_id' => 7,
                                'model_class' => 'App\\Models\\Project',
                                'title' => 'Apollo',
                                'rag_summary' => 'Apollo project timeline',
                                'rag_detail' => 'Apollo full detail',
                                'source_node' => 'projects',
                                'app_slug' => 'projects',
                                'object_json' => json_encode([
                                    'id' => 7,
                                    'title' => 'Apollo',
                                    'summary' => 'Apollo project timeline',
                                ]),
                            ],
                            ['slug' => 'projects', 'name' => 'Projects'],
                            ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                            [[
                                'chunk_index' => 0,
                                'content' => 'apollo timeline slipped to friday',
                                'content_preview' => 'apollo timeline slipped to friday',
                            ]],
                            'projects:App\\Models\\Project:7',
                        ]],
                    ],
                    'bookmarks' => ['bookmark-list-seed'],
                ], 202);
            }

            if (str_contains($statement, 'UNWIND $hits AS hit')) {
                return Http::response([
                    'data' => [
                        'fields' => ['source_entity_key', 'source_score', 'seed_type', 'path_length', 'relation_path', 'e', 'a', 's', 'chunks'],
                        'values' => [[
                            'projects:App\\Models\\Project:7',
                            0.84,
                            'last_entity_list',
                            1,
                            ['HAS_TASK'],
                            [
                                'entity_key' => 'tasks:App\\Models\\Task:19',
                                'model_id' => 19,
                                'model_class' => 'App\\Models\\Task',
                                'title' => 'Prep release notes',
                                'rag_summary' => 'Release checklist task',
                                'rag_detail' => 'Release checklist task detail',
                                'source_node' => 'tasks',
                                'app_slug' => 'tasks',
                                'object_json' => json_encode([
                                    'id' => 19,
                                    'title' => 'Prep release notes',
                                    'summary' => 'Release checklist task',
                                ]),
                            ],
                            ['slug' => 'tasks', 'name' => 'Tasks'],
                            ['scope_type' => 'project', 'scope_id' => '7', 'scope_label' => 'Apollo'],
                            [[
                                'chunk_index' => 0,
                                'content' => 'prepare release notes before launch',
                                'content_preview' => 'prepare release notes before launch',
                            ]],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-plan'],
                ], 202);
            }

            return Http::response([
                'data' => [
                    'fields' => ['entity_key', 'neighbors'],
                    'values' => [],
                ],
                'bookmarks' => ['bookmark-neighbors'],
            ], 202);
        });

        $results = (new Neo4jRetrievalService())->retrieveRelevantContext(
            ['who is it related to?'],
            ['App\\Models\\Project', 'App\\Models\\Task'],
            5,
            [
                'last_entity_list' => [
                    'entity_type' => 'project',
                    'entity_refs' => [[
                        'entity_key' => 'projects:App\\Models\\Project:7',
                        'model_id' => 7,
                        'model_class' => 'App\\Models\\Project',
                    ]],
                ],
            ],
            null
        );

        $this->assertCount(2, $results);
        $project = $results->firstWhere('id', 7);
        $task = $results->firstWhere('id', 19);

        $this->assertNotNull($project);
        $this->assertSame('last_entity_list', $project->vector_metadata['planner_seed'] ?? null);
        $this->assertNotNull($task);
        $this->assertTrue($task->vector_metadata['graph_planned'] ?? false);
        $this->assertSame('semantic_graph_planner', $task->vector_metadata['planner_strategy'] ?? null);
        $this->assertSame(['HAS_TASK'], $task->vector_metadata['relation_path'] ?? null);
        $this->assertSame(1, $task->vector_metadata['path_length'] ?? null);
        $this->assertTrue(collect($requests)->contains(function (array $request): bool {
            return str_contains((string) ($request['statement'] ?? ''), 'UNWIND $entity_keys AS entity_key');
        }));
        $this->assertTrue(collect($requests)->contains(function (array $request): bool {
            return str_contains((string) ($request['statement'] ?? ''), 'UNWIND $hits AS hit');
        }));
    }

    public function test_retrieve_relevant_context_uses_ownership_planner_template(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $payload = $request->data();
            $requests[] = $payload;
            $statement = $payload['statement'] ?? '';

            if (str_contains($statement, 'MATCH (e:Entity {entity_key: $entity_key})-[:SOURCE_APP]->(a:App)')) {
                return Http::response([
                    'data' => [
                        'fields' => ['e', 'a', 's', 'chunks'],
                        'values' => [[
                            [
                                'entity_key' => 'projects:App\\Models\\Project:7',
                                'model_id' => 7,
                                'model_class' => 'App\\Models\\Project',
                                'title' => 'Apollo',
                                'rag_summary' => 'Apollo project timeline',
                                'rag_detail' => 'Apollo full detail',
                                'source_node' => 'projects',
                                'app_slug' => 'projects',
                                'object_json' => json_encode(['id' => 7, 'title' => 'Apollo', 'summary' => 'Apollo project timeline']),
                            ],
                            ['slug' => 'projects', 'name' => 'Projects'],
                            null,
                            [[
                                'chunk_index' => 0,
                                'content' => 'apollo project',
                                'content_preview' => 'apollo project',
                            ]],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-selected'],
                ], 202);
            }

            if (str_contains($statement, 'toLower(n.model_type) IN $preferred_model_types')) {
                return Http::response([
                    'data' => [
                        'fields' => ['source_entity_key', 'source_score', 'seed_type', 'path_length', 'relation_path', 'e', 'a', 's', 'chunks'],
                        'values' => [[
                            'projects:App\\Models\\Project:7',
                            0.82,
                            'selected_entity',
                            1,
                            ['BELONGS_TO'],
                            [
                                'entity_key' => 'users:App\\Models\\User:1',
                                'model_id' => 1,
                                'model_class' => 'App\\Models\\User',
                                'model_type' => 'User',
                                'title' => 'Graph Owner',
                                'rag_summary' => 'Project owner',
                                'rag_detail' => 'Project owner detail',
                                'source_node' => 'users',
                                'app_slug' => 'users',
                                'object_json' => json_encode(['id' => 1, 'title' => 'Graph Owner', 'summary' => 'Project owner']),
                            ],
                            ['slug' => 'users', 'name' => 'Users'],
                            null,
                            [[
                                'chunk_index' => 0,
                                'content' => 'graph owner',
                                'content_preview' => 'graph owner',
                            ]],
                        ]],
                    ],
                    'bookmarks' => ['bookmark-ownership'],
                ], 202);
            }

            return Http::response([
                'data' => ['fields' => ['entity_key', 'neighbors'], 'values' => []],
                'bookmarks' => ['bookmark-neighbors'],
            ], 202);
        });

        $results = (new Neo4jRetrievalService())->retrieveRelevantContext(
            ['who owns Apollo project?'],
            ['App\\Models\\Project', 'App\\Models\\User'],
            5,
            [
                'selected_entity_context' => [
                    'entity_ref' => [
                        'entity_key' => 'projects:App\\Models\\Project:7',
                        'model_id' => 7,
                        'model_class' => 'App\\Models\\Project',
                    ],
                ],
            ],
            null
        );

        $owner = $results->firstWhere('id', 1);
        $this->assertNotNull($owner);
        $this->assertSame('ownership', $owner->vector_metadata['planner_query_kind'] ?? null);
        $this->assertSame('selected_entity', $owner->vector_metadata['planner_seed'] ?? null);
        $this->assertTrue(collect($requests)->contains(function (array $request): bool {
            return str_contains((string) ($request['statement'] ?? ''), 'toLower(n.model_type) IN $preferred_model_types');
        }));
        $this->assertTrue(collect($requests)->contains(function (array $request): bool {
            return in_array('user', (array) ($request['parameters']['preferred_model_types'] ?? []), true);
        }));
    }
}
