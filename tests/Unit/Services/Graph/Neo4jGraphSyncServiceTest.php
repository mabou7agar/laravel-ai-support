<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Services\Graph\Neo4jHttpTransport;
use LaravelAIEngine\Services\Graph\Neo4jGraphSyncService;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;
use LaravelAIEngine\Tests\TestCase;

class Neo4jGraphSyncServiceTest extends TestCase
{
    public function test_build_entity_payload_supports_project_scope_without_workspace(): void
    {
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);

        $service = new Neo4jGraphSyncService(
            $this->app->make(SearchDocumentBuilder::class),
            $this->app->make(EmbeddingService::class),
            $this->app->make(Neo4jHttpTransport::class)
        );

        $model = new class extends Model {
            protected $table = 'projects';
            public $id = 321;
            public $project_id = 55;
            public $project_name = 'Apollo';
            public $user_id = 9;
            public $email = 'User@Example.com';
            public $title = 'Launch plan';

            public function toSearchDocument(): array
            {
                return [
                    'content' => 'Launch tasks and milestones',
                    'source_node' => 'projects',
                    'app_slug' => 'projects',
                    'metadata' => [
                        'project_id' => 55,
                        'project_name' => 'Apollo',
                    ],
                    'access_scope' => [
                        'canonical_user_id' => 'user-9',
                        'user_email_normalized' => 'user@example.com',
                    ],
                ];
            }
        };

        $payload = $service->buildEntityPayload($model);

        $this->assertSame('project', $payload['scope']['scope_type']);
        $this->assertSame('55', $payload['scope']['scope_id']);
        $this->assertSame('Apollo', $payload['scope']['scope_label']);
        $this->assertSame('user-9', $payload['canonical_user_id']);
        $this->assertSame('user@example.com', $payload['user_email_normalized']);
    }

    public function test_publish_ensures_vector_schema_and_uses_canonical_user_merge(): void
    {
        config()->set('ai-engine.graph.enabled', true);
        config()->set('ai-engine.graph.backend', 'neo4j');
        config()->set('ai-engine.graph.neo4j.url', 'http://neo4j.test');
        config()->set('ai-engine.vector.testing.use_fake_embeddings', true);
        config()->set('ai-engine.vector.embedding_dimensions', 8);

        $service = new Neo4jGraphSyncService(
            $this->app->make(SearchDocumentBuilder::class),
            $this->app->make(EmbeddingService::class),
            $this->app->make(Neo4jHttpTransport::class)
        );

        $capturedRequests = [];
        Http::fake(function ($request) use (&$capturedRequests) {
            $capturedRequests[] = $request->data();

            return Http::response([
                'data' => [
                    'fields' => [],
                    'values' => [],
                ],
                'transaction' => [
                    'id' => 'tx-test',
                ],
                'bookmarks' => ['bookmark-test'],
            ], 202);
        });

        $model = new class extends Model {
            protected $table = 'emails';
            public $id = 44;

            public function toSearchDocument(): array
            {
                return [
                    'title' => 'Shipment update',
                    'content' => 'Shipment update body',
                    'chunks' => [
                        ['content' => 'Shipment delayed by two days', 'index' => 0],
                    ],
                    'source_node' => 'mail',
                    'app_slug' => 'mail',
                    'scope_type' => 'workspace',
                    'scope_id' => 'ops',
                    'scope_label' => 'Operations',
                    'access_scope' => [
                        'canonical_user_id' => 'user-44',
                        'user_email_normalized' => 'shipment@example.com',
                        'user_name' => 'Shipment User',
                    ],
                ];
            }
        };

        $this->assertTrue($service->publish($model));
        $statements = collect($capturedRequests)
            ->pluck('statement')
            ->filter(fn ($statement) => is_string($statement) && $statement !== '')
            ->values();

        $this->assertGreaterThanOrEqual(4, $capturedRequests);
        $this->assertTrue($statements->contains(fn (string $statement) => str_contains(
            $statement,
            'CREATE VECTOR INDEX chunk_embedding_index IF NOT EXISTS',
        )));
        $this->assertTrue($statements->contains(fn (string $statement) => str_contains(
            $statement,
            'MERGE (u:User {canonical_user_id: $canonical_user_id})',
        )));
        $this->assertTrue($statements->contains(fn (string $statement) => str_contains(
            $statement,
            'legacy:User {user_email_normalized: $user_email_normalized}',
        )));
        $this->assertTrue($statements->contains(fn (string $statement) => str_contains(
            $statement,
            'MATCH (s:Scope {scope_key: $scope_key})',
        )));
        $this->assertTrue($statements->contains(fn (string $statement) => str_contains(
            $statement,
            'MERGE (u)-[:CAN_ACCESS]->(s)',
        )));

        $chunkRequest = collect($capturedRequests)
            ->first(fn (array $request): bool => str_contains((string) ($request['statement'] ?? ''), 'CREATE (c:Chunk)'));

        $this->assertIsArray($chunkRequest);
        $this->assertCount(8, $chunkRequest['parameters']['chunk_props']['embedding'] ?? []);
    }

    public function test_build_entity_payload_extracts_real_relation_targets_from_vector_relationships(): void
    {
        config()->set('ai-engine.graph.extract_relations_from_vector_relationships', true);

        $service = new Neo4jGraphSyncService(
            $this->app->make(SearchDocumentBuilder::class),
            $this->app->make(EmbeddingService::class),
            $this->app->make(Neo4jHttpTransport::class)
        );

        $project = new GraphSyncProjectModel();
        $project->id = 77;

        $mail = new GraphSyncMailModel();
        $mail->id = 501;
        $mail->project_id = 77;
        $mail->setRelation('project', $project);

        $payload = $service->buildEntityPayload($mail);

        $this->assertCount(1, $payload['relations']);
        $this->assertSame('IN_PROJECT', $payload['relations'][0]['type']);
        $this->assertSame('project', $payload['relations'][0]['name']);
        $this->assertSame(
            'projects:' . GraphSyncProjectModel::class . ':77',
            $payload['relations'][0]['target_entity_key']
        );
    }
}

class GraphSyncProjectModel extends Model
{
    public $timestamps = false;
    protected $table = 'projects';
    public $id;

    public function getVectorContent(): string
    {
        return 'Apollo project';
    }

    public function getVectorMetadata(): array
    {
        return [
            'source_node' => 'projects',
            'app_slug' => 'projects',
        ];
    }
}

class GraphSyncMailModel extends Model
{
    public $timestamps = false;
    protected $table = 'mails';
    protected array $vectorRelationships = ['project'];
    public $id;
    public $project_id;

    public function getVectorContent(): string
    {
        return 'Delay mail';
    }

    public function getVectorMetadata(): array
    {
        return [
            'source_node' => 'mail',
            'app_slug' => 'mail',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(GraphSyncProjectModel::class, 'project_id');
    }
}
