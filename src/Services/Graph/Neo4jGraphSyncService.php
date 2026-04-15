<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\Vector\EmbeddingService;
use LaravelAIEngine\Services\Vectorization\SearchDocumentBuilder;

class Neo4jGraphSyncService
{
    protected bool $schemaEnsured = false;

    public function __construct(
        protected SearchDocumentBuilder $documentBuilder,
        protected EmbeddingService $embeddingService,
        protected Neo4jHttpTransport $transport,
        protected ?GraphKnowledgeBaseService $knowledgeBase = null,
        protected ?GraphVectorNamingService $vectorNaming = null,
        protected ?GraphBackendResolver $backendResolver = null
    )
    {
        if ($this->knowledgeBase === null && app()->bound(GraphKnowledgeBaseService::class)) {
            $this->knowledgeBase = app(GraphKnowledgeBaseService::class);
        }
        if ($this->vectorNaming === null && app()->bound(GraphVectorNamingService::class)) {
            $this->vectorNaming = app(GraphVectorNamingService::class);
        }
        if ($this->backendResolver === null && app()->bound(GraphBackendResolver::class)) {
            $this->backendResolver = app(GraphBackendResolver::class);
        }
    }

    public function enabled(): bool
    {
        return ($this->backendResolver?->graphEnabledRequested() ?? false)
            && ($this->backendResolver?->graphBackend() ?? 'neo4j') === 'neo4j'
            && ($this->backendResolver?->neo4jConfigured() ?? false);
    }

    public function prefersCentralReadModel(): bool
    {
        return $this->backendResolver?->graphReadPathActive() ?? false;
    }

    public function ensureSchema(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        if ($this->schemaEnsured) {
            return true;
        }

        $success = $this->commit($this->schemaStatements());
        if ($success) {
            $this->schemaEnsured = true;
        }

        return $success;
    }

    public function resetGraph(): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $success = $this->commit([[
            'statement' => 'MATCH (n) DETACH DELETE n',
            'parameters' => [],
        ]]);

        if ($success) {
            $this->schemaEnsured = false;
            $this->knowledgeBase?->bumpGraphVersion();
        }

        return $success;
    }

    public function publish(object $model): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $payload = $this->buildEntityPayload($model);
        $payload['chunks'] = $this->attachChunkEmbeddings($payload['chunks']);

        if ($this->shouldEnsureSchemaOnSync()) {
            if (!$this->ensureSchema()) {
                return false;
            }
        }

        $statements = [[
            'statement' => <<<'CYPHER'
MERGE (e:Entity {entity_key: $entity_key})
SET e += $entity_props
WITH e
MERGE (a:App {slug: $app_slug})
SET a.name = coalesce($app_name, a.name, $app_slug)
MERGE (e)-[:SOURCE_APP]->(a)
CYPHER,
            'parameters' => [
                'entity_key' => $payload['entity_key'],
                'entity_props' => $payload['entity_props'],
                'app_slug' => $payload['app_slug'],
                'app_name' => $payload['app_name'],
            ],
        ]];

        $statements = array_merge($statements, $this->userAccessStatements($payload));

        if (!empty($payload['scope'])) {
            $statements[] = [
                'statement' => <<<'CYPHER'
MERGE (s:Scope {scope_key: $scope_key})
SET s += $scope_props
WITH s
MATCH (e:Entity {entity_key: $entity_key})
MERGE (e)-[:BELONGS_TO]->(s)
CYPHER,
                'parameters' => [
                    'entity_key' => $payload['entity_key'],
                    'scope_key' => $payload['scope']['scope_key'],
                    'scope_props' => $payload['scope'],
                ],
            ];

            $statements = array_merge($statements, $this->userScopeStatements($payload));
        }

        $statements[] = [
            'statement' => <<<'CYPHER'
MATCH (e:Entity {entity_key: $entity_key})-[r:HAS_CHUNK]->(c:Chunk)
DELETE r, c
CYPHER,
            'parameters' => [
                'entity_key' => $payload['entity_key'],
            ],
        ];

        foreach ($payload['chunks'] as $chunk) {
            $statements[] = [
                'statement' => <<<'CYPHER'
MATCH (e:Entity {entity_key: $entity_key})
CREATE (c:Chunk)
SET c += $chunk_props
MERGE (e)-[:HAS_CHUNK]->(c)
CYPHER,
                'parameters' => [
                    'entity_key' => $payload['entity_key'],
                    'chunk_props' => $chunk,
                ],
            ];
        }

        foreach ($payload['relations'] as $relation) {
            if (empty($relation['target_entity_key'])) {
                continue;
            }

            $relationType = preg_replace('/[^A-Z0-9_]/', '_', strtoupper((string) ($relation['type'] ?? 'RELATED_TO')));
            if ($relationType === '') {
                $relationType = 'RELATED_TO';
            }

            $statements[] = [
                'statement' => "MATCH (e:Entity {entity_key: \$entity_key}) MERGE (t:Entity {entity_key: \$target_entity_key}) MERGE (e)-[r:{$relationType}]->(t) SET r += \$relation_props",
                'parameters' => [
                    'entity_key' => $payload['entity_key'],
                    'target_entity_key' => $relation['target_entity_key'],
                    'relation_props' => $relation,
                ],
            ];
        }

        $success = $this->commit($statements);
        if ($success) {
            $this->knowledgeBase?->bumpGraphVersion();
            $this->knowledgeBase?->bumpAccessVersion($this->accessScopeContext($payload));
        }

        return $success;
    }

    public function delete(object $model): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $payload = $this->buildEntityPayload($model);
        $entityKey = $payload['entity_key'];

        $success = $this->commit([[
            'statement' => <<<'CYPHER'
MATCH (e:Entity {entity_key: $entity_key})
OPTIONAL MATCH (e)-[r]-()
OPTIONAL MATCH (e)-[:HAS_CHUNK]->(c:Chunk)
DETACH DELETE e, c
CYPHER,
            'parameters' => [
                'entity_key' => $entityKey,
            ],
        ]]);
        if ($success) {
            $this->knowledgeBase?->bumpGraphVersion();
            $this->knowledgeBase?->bumpAccessVersion($this->accessScopeContext($payload));
        }

        return $success;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildEntityPayload(object $model): array
    {
        $document = $this->documentBuilder->build($model);
        $entityKey = $this->entityKey($document->sourceNode, $document->modelClass, $document->modelId);
        $accessScope = $document->accessScope;

        $chunks = [];
        foreach ($document->normalizedChunks() as $chunk) {
            $chunkText = trim((string) ($chunk['content'] ?? ''));
            if ($chunkText === '') {
                continue;
            }

            $chunkIndex = is_numeric($chunk['index'] ?? null) ? (int) $chunk['index'] : count($chunks);
            $chunks[] = [
                'chunk_key' => $entityKey . '#chunk:' . $chunkIndex,
                'chunk_index' => $chunkIndex,
                'content' => $chunkText,
                'content_preview' => Str::limit($chunkText, 240, '...'),
            ];
        }

        $relations = [];
        foreach ($document->relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $targetKey = $relation['target_entity_key'] ?? null;
            if (!$targetKey && !empty($relation['model_class']) && array_key_exists('model_id', $relation)) {
                $targetKey = $this->entityKey(
                    $relation['source_node'] ?? $document->sourceNode,
                    (string) $relation['model_class'],
                    $relation['model_id']
                );
            }

            $relations[] = array_filter([
                'type' => $relation['type'] ?? 'RELATED_TO',
                'name' => $relation['name'] ?? null,
                'target_entity_key' => $targetKey,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        $scope = null;
        if ($document->scopeType !== null && $document->scopeId !== null) {
            $scope = array_filter([
                'scope_key' => $document->scopeType . ':' . $document->scopeId,
                'scope_type' => $document->scopeType,
                'scope_id' => (string) $document->scopeId,
                'scope_label' => $document->scopeLabel,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return [
            'entity_key' => $entityKey,
            'app_slug' => $document->appSlug ?? 'app',
            'app_name' => config('app.name'),
            'canonical_user_id' => $accessScope['canonical_user_id'] ?? null,
            'user_email_normalized' => $accessScope['user_email_normalized'] ?? null,
            'user_display_name' => $accessScope['user_name'] ?? null,
            'scope' => $scope,
            'entity_props' => array_filter([
                'entity_key' => $entityKey,
                'model_id' => (string) $document->modelId,
                'model_class' => $document->modelClass,
                'model_type' => class_basename($document->modelClass),
                'source_node' => $document->sourceNode,
                'app_slug' => $document->appSlug,
                'title' => $document->title,
                'rag_summary' => $document->ragSummary,
                'rag_detail' => $document->ragDetail,
                'content_preview' => Str::limit($document->content, 320, '...'),
                'object_json' => json_encode($document->object, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'access_scope_json' => json_encode($document->accessScope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => $document->metadata['updated_at'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'chunks' => $chunks,
            'relations' => $relations,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunks
     * @return array<int, array<string, mixed>>
     */
    protected function attachChunkEmbeddings(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        try {
            $embeddings = $this->embeddingService->embedBatch(array_map(
                static fn (array $chunk): string => (string) ($chunk['content'] ?? ''),
                $chunks
            ));

            foreach ($chunks as $index => $chunk) {
                $embedding = $embeddings[$index] ?? null;
                if (!is_array($embedding) || $embedding === []) {
                    continue;
                }

                $chunks[$index][$this->vectorPropertyName()] = array_map('floatval', $embedding);
            }
        } catch (\Throwable $e) {
            Log::warning('Neo4j graph sync embedding generation failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $chunks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function schemaStatements(): array
    {
        $dimensions = max(1, $this->embeddingService->getDimensions());
        $similarity = $this->vectorSimilarityFunction();
        $indexName = $this->vectorIndexName();
        $vectorProperty = $this->vectorPropertyName();

        return [[
            'statement' => 'CREATE CONSTRAINT entity_key_unique IF NOT EXISTS FOR (e:Entity) REQUIRE e.entity_key IS UNIQUE',
            'parameters' => [],
        ], [
            'statement' => <<<CYPHER
CREATE VECTOR INDEX {$indexName} IF NOT EXISTS
FOR (c:Chunk) ON (c.{$vectorProperty})
OPTIONS {indexConfig: {`vector.dimensions`: {$dimensions}, `vector.similarity_function`: '{$similarity}'}}
CYPHER,
            'parameters' => [],
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    protected function userAccessStatements(array $payload): array
    {
        if (!empty($payload['canonical_user_id'])) {
            $statements = [[
                'statement' => <<<'CYPHER'
MERGE (u:User {canonical_user_id: $canonical_user_id})
SET u.user_email_normalized = coalesce($user_email_normalized, u.user_email_normalized)
SET u.display_name = coalesce($user_display_name, u.display_name)
WITH u
MATCH (e:Entity {entity_key: $entity_key})
MERGE (u)-[:CAN_ACCESS]->(e)
CYPHER,
                'parameters' => [
                    'entity_key' => $payload['entity_key'],
                    'canonical_user_id' => $payload['canonical_user_id'],
                    'user_email_normalized' => $payload['user_email_normalized'],
                    'user_display_name' => $payload['user_display_name'],
                ],
            ]];

            if (!empty($payload['user_email_normalized'])) {
                $statements[] = [
                    'statement' => <<<'CYPHER'
MATCH (u:User {canonical_user_id: $canonical_user_id})
OPTIONAL MATCH (legacy:User {user_email_normalized: $user_email_normalized})
WHERE legacy.canonical_user_id IS NULL AND legacy <> u
OPTIONAL MATCH (legacy)-[:CAN_ACCESS]->(accessible:Entity)
OPTIONAL MATCH (legacy)-[:CAN_ACCESS]->(legacy_scope:Scope)
WITH u, legacy, collect(DISTINCT accessible) AS accessible_entities, collect(DISTINCT legacy_scope) AS accessible_scopes
FOREACH (entity IN accessible_entities | MERGE (u)-[:CAN_ACCESS]->(entity))
FOREACH (scope IN accessible_scopes | MERGE (u)-[:CAN_ACCESS]->(scope))
FOREACH (_ IN CASE WHEN legacy IS NULL THEN [] ELSE [1] END | DETACH DELETE legacy)
CYPHER,
                    'parameters' => [
                        'canonical_user_id' => $payload['canonical_user_id'],
                        'user_email_normalized' => $payload['user_email_normalized'],
                    ],
                ];
            }

            return $statements;
        }

        if (!empty($payload['user_email_normalized'])) {
            return [[
                'statement' => <<<'CYPHER'
MERGE (u:User {user_email_normalized: $user_email_normalized})
SET u.display_name = coalesce($user_display_name, u.display_name)
WITH u
MATCH (e:Entity {entity_key: $entity_key})
MERGE (u)-[:CAN_ACCESS]->(e)
CYPHER,
                'parameters' => [
                    'entity_key' => $payload['entity_key'],
                    'user_email_normalized' => $payload['user_email_normalized'],
                    'user_display_name' => $payload['user_display_name'],
                ],
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    protected function userScopeStatements(array $payload): array
    {
        if (empty($payload['scope']['scope_key'])) {
            return [];
        }

        if (!empty($payload['canonical_user_id'])) {
            return [[
                'statement' => <<<'CYPHER'
MATCH (u:User {canonical_user_id: $canonical_user_id})
MATCH (s:Scope {scope_key: $scope_key})
MERGE (u)-[:CAN_ACCESS]->(s)
CYPHER,
                'parameters' => [
                    'canonical_user_id' => $payload['canonical_user_id'],
                    'scope_key' => $payload['scope']['scope_key'],
                ],
            ]];
        }

        if (!empty($payload['user_email_normalized'])) {
            return [[
                'statement' => <<<'CYPHER'
MATCH (u:User {user_email_normalized: $user_email_normalized})
MATCH (s:Scope {scope_key: $scope_key})
MERGE (u)-[:CAN_ACCESS]->(s)
CYPHER,
                'parameters' => [
                    'user_email_normalized' => $payload['user_email_normalized'],
                    'scope_key' => $payload['scope']['scope_key'],
                ],
            ]];
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $statements
     */
    protected function commit(array $statements): bool
    {
        $result = $this->transport->executeTransaction($statements);
        if (!($result['success'] ?? false)) {
            Log::warning('Neo4j graph sync transaction failed', [
                'error' => $result['error'] ?? 'unknown error',
                'statement_count' => count($statements),
            ]);
        }

        return (bool) ($result['success'] ?? false);
    }

    protected function entityKey(?string $sourceNode, string $modelClass, string|int|null $modelId): string
    {
        return implode(':', [
            $sourceNode ?: 'local',
            $modelClass,
            (string) $modelId,
        ]);
    }

    protected function vectorIndexName(): string
    {
        return $this->vectorNaming?->indexName() ?? 'chunk_embedding_index';
    }

    protected function vectorSimilarityFunction(): string
    {
        $configured = Str::lower((string) config('ai-engine.graph.neo4j.vector_similarity', 'cosine'));

        return in_array($configured, ['cosine', 'euclidean'], true) ? $configured : 'cosine';
    }

    protected function vectorPropertyName(): string
    {
        return $this->vectorNaming?->propertyName() ?? 'embedding';
    }

    protected function shouldEnsureSchemaOnSync(): bool
    {
        return (bool) config('ai-engine.graph.neo4j.ensure_schema_on_sync', true);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    protected function accessScopeContext(array $payload): array
    {
        return array_filter([
            'canonical_user_id' => $payload['canonical_user_id'] ?? null,
            'user_email_normalized' => $payload['user_email_normalized'] ?? null,
            'app_slug' => $payload['app_slug'] ?? null,
            'scope_type' => $payload['scope']['scope_type'] ?? null,
            'scope_id' => $payload['scope']['scope_id'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

}
