<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Live;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Services\Graph\Neo4jRetrievalService;
use LaravelAIEngine\Tests\TestCase;

class Neo4jLiveIntegrationTest extends TestCase
{
    public function test_graph_sync_and_vector_retrieval_work_against_live_neo4j(): void
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_NEO4J_LIVE_TESTS')) {
            $this->markTestSkipped('Set AI_ENGINE_RUN_NEO4J_LIVE_TESTS=true to enable live Neo4j integration tests.');
        }

        $url = trim((string) getenv('AI_ENGINE_NEO4J_URL'));
        $password = trim((string) getenv('AI_ENGINE_NEO4J_PASSWORD'));

        if ($url === '' || $password === '') {
            $this->markTestSkipped('Missing AI_ENGINE_NEO4J_URL or AI_ENGINE_NEO4J_PASSWORD for live Neo4j test.');
        }

        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.reads_prefer_central_graph', true);
        config()->set('ai-engine.graph.timeout', 20);
        config()->set('ai-engine.graph.neo4j.url', $url);
        config()->set('ai-engine.graph.neo4j.database', (string) (getenv('AI_ENGINE_NEO4J_DATABASE') ?: 'neo4j'));
        config()->set('ai-engine.graph.neo4j.username', (string) (getenv('AI_ENGINE_NEO4J_USERNAME') ?: 'neo4j'));
        config()->set('ai-engine.graph.neo4j.password', $password);
        config()->set('ai-engine.graph.neo4j.chunk_vector_index', 'chunk_embedding_ai_engine_3072');
        config()->set('ai-engine.graph.neo4j.chunk_vector_property', 'embedding_ai_engine_3072');
        config()->set('ai-engine.graph.neo4j.ensure_schema_on_sync', true);
        config()->set('ai-engine.graph.neo4j.vector_candidate_multiplier', 4);
        config()->set('ai-engine.graph.neo4j.use_query_api', true);
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 64);

        if (!$this->queryApiEndpointAvailable($url, config('ai-engine.graph.neo4j.database'), config('ai-engine.graph.neo4j.username'), $password)) {
            $this->markTestSkipped('Live Neo4j server does not expose the Query API endpoint required by the current graph implementation.');
        }

        $runId = uniqid('neo4j-live-', true);
        $canonicalUserId = 'user-' . $runId;
        $email = 'neo4j-live-' . preg_replace('/[^a-z0-9]/i', '', $runId) . '@example.com';

        $projectId = random_int(100000, 999999);
        $mailId = $projectId + 1;
        $taskId = $projectId + 2;

        $project = new Neo4jLiveProjectModel();
        $project->id = $projectId;
        $project->canonicalUserId = $canonicalUserId;
        $project->email = $email;
        $project->runId = $runId;

        $mail = new Neo4jLiveMailModel();
        $mail->id = $mailId;
        $mail->canonicalUserId = $canonicalUserId;
        $mail->email = $email;
        $mail->runId = $runId;
        $mail->projectId = $projectId;
        $mail->setRelation('project', $project);

        $task = new Neo4jLiveTaskModel();
        $task->id = $taskId;
        $task->canonicalUserId = $canonicalUserId;
        $task->email = $email;
        $task->runId = $runId;
        $task->projectId = $projectId;
        $task->setRelation('project', $project);

        $sync = $this->app->make(\LaravelAIEngine\Services\Graph\Neo4jGraphSyncService::class);
        $retrieval = $this->app->make(Neo4jRetrievalService::class);

        try {
            $this->assertTrue($sync->publish($project));
            $this->assertTrue($sync->publish($mail));
            $this->assertTrue($sync->publish($task));

            $results = $retrieval->retrieveRelevantContext(
                ['launch delay email notice'],
                [get_class($mail), get_class($project)],
                5,
                [
                    'selected_entity_context' => [
                        'entity_ref' => [
                            'canonical_user_id' => $canonicalUserId,
                            'user_email_normalized' => strtolower($email),
                        ],
                    ],
                ],
                null
            );

            $this->assertNotEmpty($results, 'Expected live Neo4j retrieval to return at least one result.');

            $mailResult = $results->first(fn ($item) => ($item->vector_metadata['entity_ref']['app_slug'] ?? null) === 'mail-live');
            $this->assertNotNull($mailResult, 'Expected the mail entity to be returned from live Neo4j retrieval.');
            $this->assertStringContainsString('Friday', $mailResult->matched_chunk_text ?? '');
            $this->assertSame($canonicalUserId, $mailResult->entity_ref['canonical_user_id'] ?? null);
            $this->assertSame('Launch Delay Notice ' . $runId, $mailResult->graph_object['title'] ?? null);
            $this->assertNotEmpty($mailResult->graph_neighbors ?? []);

            $projectResult = $results->first(fn ($item) => ($item->vector_metadata['entity_ref']['app_slug'] ?? null) === 'projects-live');
            $this->assertNotNull($projectResult, 'Expected the related project entity to be expanded from the graph edge.');
            if (($projectResult->vector_metadata['relation_expanded'] ?? false) === true) {
                $this->assertSame('BELONGS_TO', $projectResult->vector_metadata['relation_type'] ?? null);
                $this->assertSame(1, $projectResult->vector_metadata['path_length'] ?? null);
            } else {
                $this->assertNotEmpty($projectResult->graph_neighbors ?? []);
                $neighborKeys = array_column($projectResult->graph_neighbors, 'entity_key');
                $this->assertContains($mailResult->entity_key, $neighborKeys);
            }

            $taskResult = $results->first(fn ($item) => ($item->vector_metadata['entity_ref']['app_slug'] ?? null) === 'tasks-live');
            if ($taskResult !== null) {
                if (($taskResult->vector_metadata['relation_expanded'] ?? false) === true) {
                    $this->assertSame(2, $taskResult->vector_metadata['path_length'] ?? null);
                    $this->assertSame(['BELONGS_TO', 'BELONGS_TO'], $taskResult->vector_metadata['relation_path'] ?? null);
                } else {
                    $this->assertNotEmpty($taskResult->graph_neighbors ?? []);
                    $neighborKeys = array_column($taskResult->graph_neighbors, 'entity_key');
                    $this->assertContains($projectResult->entity_key, $neighborKeys);
                }
            } else {
                $projectNeighborKeys = array_column($projectResult->graph_neighbors ?? [], 'entity_key');
                $this->assertTrue(
                    collect($projectNeighborKeys)->contains(
                        fn ($entityKey) => is_string($entityKey) && str_contains($entityKey, 'Task')
                    ),
                    'Expected the related task to appear directly or through project neighbors.'
                );
            }
        } finally {
            $sync->delete($task);
            $sync->delete($mail);
            $sync->delete($project);
        }
    }

    private function readBoolEnv(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function queryApiEndpointAvailable(string $baseUrl, string $database, string $username, string $password): bool
    {
        $url = rtrim($baseUrl, '/') . '/db/' . trim($database, '/') . '/query/v2';

        try {
            $response = Http::timeout(10)
                ->withBasicAuth($username, $password)
                ->post($url, [
                    'statement' => 'RETURN 1 AS ok',
                    'parameters' => (object) [],
                ]);
        } catch (\Throwable) {
            return false;
        }

        if (!in_array($response->status(), [200, 202], true)) {
            return false;
        }

        return is_array($response->json('data'));
    }
}

class Neo4jLiveProjectModel extends Model
{
    public $timestamps = false;
    public int $id;
    public string $canonicalUserId;
    public string $email;
    public string $runId;

    public function toSearchDocument(): array
    {
        return [
            'title' => 'Apollo Launch Project ' . $this->runId,
            'content' => 'Apollo project roadmap, dependencies, and staffing decisions.',
            'rag_summary' => 'Project Apollo roadmap and dependency tracking.',
            'rag_detail' => 'Project Apollo tracks dependencies, owners, and implementation workstreams.',
            'chunks' => [
                ['content' => 'Apollo project roadmap covers dependencies and implementation workstreams.', 'index' => 0],
            ],
            'source_node' => 'projects-live',
            'app_slug' => 'projects-live',
            'scope_type' => 'project',
            'scope_id' => (string) $this->id,
            'scope_label' => 'Apollo',
            'object' => [
                'id' => $this->id,
                'title' => 'Apollo Launch Project ' . $this->runId,
                'summary' => 'Project Apollo timeline with launch milestone updates.',
            ],
            'access_scope' => [
                'canonical_user_id' => $this->canonicalUserId,
                'user_email_normalized' => strtolower($this->email),
                'user_name' => 'Neo4j Live User',
            ],
        ];
    }

    public function getSourceNode(): string
    {
        return 'projects-live';
    }
}

class Neo4jLiveMailModel extends Model
{
    public $timestamps = false;
    public int $id;
    public string $canonicalUserId;
    public string $email;
    public string $runId;
    public int $projectId;
    protected array $vectorRelationships = ['project'];

    public function toSearchDocument(): array
    {
        return [
            'title' => 'Launch Delay Notice ' . $this->runId,
            'content' => 'Email confirming the launch is delayed until Friday because Apollo milestone slipped.',
            'rag_summary' => 'Mail about a launch delay linked to Apollo.',
            'rag_detail' => 'The launch is delayed until Friday because the Apollo project milestone slipped.',
            'chunks' => [
                ['content' => 'The launch is delayed until Friday because Apollo slipped.', 'index' => 0],
            ],
            'source_node' => 'mail-live',
            'app_slug' => 'mail-live',
            'object' => [
                'id' => $this->id,
                'title' => 'Launch Delay Notice ' . $this->runId,
                'summary' => 'Mail about a launch delay linked to Apollo.',
            ],
            'access_scope' => [
                'canonical_user_id' => $this->canonicalUserId,
                'user_email_normalized' => strtolower($this->email),
                'user_name' => 'Neo4j Live User',
            ],
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Neo4jLiveProjectModel::class, 'projectId');
    }

    public function getSourceNode(): string
    {
        return 'mail-live';
    }
}

class Neo4jLiveTaskModel extends Model
{
    public $timestamps = false;
    public int $id;
    public string $canonicalUserId;
    public string $email;
    public string $runId;
    public int $projectId;
    protected array $vectorRelationships = ['project'];

    public function toSearchDocument(): array
    {
        return [
            'title' => 'Apollo Dependency Task ' . $this->runId,
            'content' => 'Task to review Apollo vendor dependencies and integration notes.',
            'rag_summary' => 'Task for Apollo dependency review.',
            'rag_detail' => 'Review Apollo vendor dependencies and integration notes after project changes.',
            'chunks' => [
                ['content' => 'Review Apollo vendor dependencies and integration notes.', 'index' => 0],
            ],
            'source_node' => 'tasks-live',
            'app_slug' => 'tasks-live',
            'scope_type' => 'project',
            'scope_id' => (string) $this->projectId,
            'scope_label' => 'Apollo',
            'object' => [
                'id' => $this->id,
                'title' => 'Apollo Dependency Task ' . $this->runId,
                'summary' => 'Task for Apollo dependency review.',
            ],
            'access_scope' => [
                'canonical_user_id' => $this->canonicalUserId,
                'user_email_normalized' => strtolower($this->email),
                'user_name' => 'Neo4j Live User',
            ],
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Neo4jLiveProjectModel::class, 'projectId');
    }

    public function getSourceNode(): string
    {
        return 'tasks-live';
    }
}
