<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LaravelAIEngine\Services\Vector\EmbeddingService;

class Neo4jRetrievalService
{
    protected ?string $resolvedVectorIndexName = null;

    public function __construct(
        protected ?EmbeddingService $embeddingService = null,
        protected ?Neo4jHttpTransport $transport = null,
        protected ?GraphQueryPlanner $planner = null,
        protected ?GraphKnowledgeBaseService $knowledgeBase = null,
        protected ?GraphCypherPlanCompiler $cypherPlanCompiler = null,
        protected ?GraphVectorNamingService $vectorNaming = null,
        protected ?GraphRankingFeedbackService $rankingFeedback = null,
        protected ?GraphBackendResolver $backendResolver = null
    )
    {
        if ($this->embeddingService === null && app()->bound(EmbeddingService::class)) {
            $this->embeddingService = app(EmbeddingService::class);
        }

        if ($this->transport === null && app()->bound(Neo4jHttpTransport::class)) {
            $this->transport = app(Neo4jHttpTransport::class);
        }

        if ($this->planner === null && app()->bound(GraphQueryPlanner::class)) {
            $this->planner = app(GraphQueryPlanner::class);
        }

        if ($this->knowledgeBase === null && app()->bound(GraphKnowledgeBaseService::class)) {
            $this->knowledgeBase = app(GraphKnowledgeBaseService::class);
        }

        if ($this->cypherPlanCompiler === null && app()->bound(GraphCypherPlanCompiler::class)) {
            $this->cypherPlanCompiler = app(GraphCypherPlanCompiler::class);
        }

        if ($this->vectorNaming === null && app()->bound(GraphVectorNamingService::class)) {
            $this->vectorNaming = app(GraphVectorNamingService::class);
        }
        if ($this->rankingFeedback === null && app()->bound(GraphRankingFeedbackService::class)) {
            $this->rankingFeedback = app(GraphRankingFeedbackService::class);
        }
        if ($this->backendResolver === null && app()->bound(GraphBackendResolver::class)) {
            $this->backendResolver = app(GraphBackendResolver::class);
        }
    }

    public function enabled(): bool
    {
        return $this->backendResolver?->graphReadPathActive() ?? false;
    }

    public function retrieveRelevantContext(
        array $searchQueries,
        array $collections,
        int $maxResults,
        array $options = [],
        $userId = null
    ): Collection {
        if (!$this->enabled()) {
            return collect();
        }

        $userScope = $this->resolveUserScope($userId, $options);
        $searchText = trim(implode(' ', array_filter(array_map('strval', $searchQueries))));
        $cacheSignals = $this->cacheSignals($options);
        $cachedResults = $this->knowledgeBase?->getCachedResults($searchText, $collections, $userScope, $cacheSignals);
        if ($cachedResults instanceof Collection) {
            $cachedResults = $cachedResults
                ->sortByDesc('vector_score')
                ->take($maxResults)
                ->values();
            $this->recordRankingFeedback([
                'query_kind' => 'cached',
            ], $cachedResults);

            return $cachedResults;
        }
        $plan = $this->graphPlan($searchText, $collections, $userScope, $options, $maxResults);
        $seedResults = $this->collectSeedResults($searchQueries, $collections, $userScope, $options, $plan, $maxResults);

        if ($seedResults->isEmpty()) {
            return collect();
        }

        $results = $seedResults;

        if ($plan['traversal_enabled']) {
            $planned = $this->planGraphCandidates(
                $seedResults,
                $collections,
                $userScope,
                $plan['candidate_limit'],
                $searchText,
                $plan
            );
            $results = $this->mergePlannedResults($seedResults, $planned);
        }

        if ($plan['attach_neighbor_context']) {
            $results = $this->attachNeighborContext($results, (int) config('ai-engine.graph.neighbor_limit', 3), $userScope);
        }

        $results = $results
            ->sortByDesc('vector_score')
            ->take($maxResults)
            ->values();
        $this->knowledgeBase?->cacheResults($searchText, $collections, $userScope, $cacheSignals, $results);
        $this->recordRankingFeedback($plan, $results);

        return $results;
    }

    /**
     * @return array{strategy:string,relationship_query:bool,contextual_follow_up:bool,query_kind:string,use_selected_entity_seed:bool,use_visible_list_seeds:bool,use_semantic_seeds:bool,traversal_enabled:bool,prefer_planner_ranking:bool,attach_neighbor_context:bool,max_hops:int,seed_limit:int,candidate_limit:int,relation_types:array<int,string>,preferred_model_types:array<int,string>,lexical_focus_terms:array<int,string>,vector_weight:float,lexical_weight:float,selected_seed_boost:float,relationship_bonus:float}
     */
    protected function graphPlan(string $query, array $collections, array $userScope, array $options, int $maxResults): array
    {
        if ($this->planner !== null) {
            return $this->knowledgeBase?->rememberPlan(
                $query,
                $collections,
                $userScope,
                [
                    'selected_entity' => !empty($options['selected_entity'] ?? $options['selected_entity_context'] ?? []),
                    'last_entity_list' => !empty($options['last_entity_list'] ?? []),
                ],
                fn (): array => $this->planner->plan($query, $collections, $options, $maxResults)
            ) ?? $this->planner->plan($query, $collections, $options, $maxResults);
        }

        return [
            'strategy' => 'semantic_only',
            'relationship_query' => false,
            'contextual_follow_up' => false,
            'query_kind' => 'generic',
            'use_selected_entity_seed' => false,
            'use_visible_list_seeds' => false,
            'use_semantic_seeds' => true,
            'traversal_enabled' => false,
            'prefer_planner_ranking' => false,
            'attach_neighbor_context' => true,
            'max_hops' => max(1, (int) config('ai-engine.graph.max_traversal_hops', 2)),
            'seed_limit' => $maxResults,
            'candidate_limit' => $maxResults,
            'relation_types' => ['BELONGS_TO', 'HAS_RELATED', 'RELATED_TO'],
            'preferred_model_types' => [],
            'lexical_focus_terms' => [],
            'vector_weight' => 0.6,
            'lexical_weight' => 0.4,
            'selected_seed_boost' => 0.05,
            'relationship_bonus' => 0.05,
        ];
    }

    protected function collectSeedResults(
        array $searchQueries,
        array $collections,
        array $userScope,
        array $options,
        array $plan,
        int $maxResults
    ): Collection {
        $results = collect();

        if ($plan['use_selected_entity_seed']) {
            $selectedEntity = $options['selected_entity'] ?? $options['selected_entity_context'] ?? null;
            if (is_array($selectedEntity) && (!empty($selectedEntity['entity_ref']) || !empty($selectedEntity['entity_id']))) {
                $selected = $this->fetchSelectedEntity($selectedEntity, $collections, $userScope, $options);
                if ($selected !== null) {
                    $selected->vector_metadata = is_array($selected->vector_metadata ?? null) ? $selected->vector_metadata : [];
                    $selected->vector_metadata['planner_seed'] = 'selected_entity';
                    $results->push($selected);
                }
            }
        }

        if ($plan['use_visible_list_seeds']) {
            $listSeeds = $this->fetchListedEntities(
                (array) ($options['last_entity_list'] ?? []),
                $collections,
                $userScope,
                (int) ($plan['seed_limit'] ?? $maxResults)
            );
            if ($listSeeds->isNotEmpty()) {
                $results = $results->merge($listSeeds);
            }
        }

        if ($plan['use_semantic_seeds']) {
            $semanticLimit = max($maxResults, (int) ($plan['seed_limit'] ?? $maxResults));
            foreach ($searchQueries as $searchQuery) {
                $chunkHits = $this->searchChunks((string) $searchQuery, $collections, $semanticLimit, $userScope);
                if ($chunkHits->isNotEmpty()) {
                    $results = $results->merge($chunkHits);
                }
            }
        }

        return $results
            ->filter(fn ($item) => !empty($item->id))
            ->unique(fn ($item) => (string) ($item->entity_key ?? $item->id))
            ->values();
    }

    protected function fetchSelectedEntity(array $selectedEntity, array $collections, array $userScope, array $options): ?object
    {
        $entityRef = is_array($selectedEntity['entity_ref'] ?? null) ? $selectedEntity['entity_ref'] : null;
        $entityKey = $entityRef['entity_key'] ?? null;

        if (!$entityKey) {
            $modelClass = $entityRef['model_class'] ?? ($selectedEntity['model_class'] ?? null);
            $modelId = $entityRef['model_id'] ?? ($selectedEntity['entity_id'] ?? null);
            $sourceNode = $entityRef['source_node'] ?? ($selectedEntity['source_node'] ?? null) ?? config('ai-engine.nodes.local.slug');

            if ($modelClass && $modelId) {
                $entityKey = implode(':', [$sourceNode ?: 'local', $modelClass, (string) $modelId]);
            }
        }

        if (!$entityKey) {
            return null;
        }

        $payload = $this->commit([[
            'statement' => <<<CYPHER
MATCH (e:Entity {entity_key: \$entity_key})-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (e)-[:BELONGS_TO]->(s:Scope)
OPTIONAL MATCH (e)-[:HAS_CHUNK]->(c:Chunk)
WHERE (\$collections_empty = true OR e.model_class IN \$collections)
  AND {$this->accessPredicate('e')}
WITH e, a, s, c
ORDER BY c.chunk_index ASC
RETURN e{.*} AS e, a{.*} AS a, s{.*} AS s, collect(c{.*}) AS chunks
LIMIT 1
CYPHER,
            'parameters' => [
                'entity_key' => $entityKey,
                'collections' => array_values($collections),
                'collections_empty' => $collections === [],
                'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
                'user_email_normalized' => $userScope['user_email_normalized'] ?? null,
            ],
        ]]);

        $row = $payload[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return $this->mapRowToResult($row, query: null, userScope: $userScope, prioritizeFirstChunk: true);
    }

    protected function fetchListedEntities(array $lastEntityList, array $collections, array $userScope, int $limit): Collection
    {
        $entityRefs = array_values(array_filter(
            (array) ($lastEntityList['entity_refs'] ?? []),
            static fn ($entityRef): bool => is_array($entityRef) && !empty($entityRef['entity_key'])
        ));

        if ($entityRefs === []) {
            return collect();
        }

        $entityKeys = array_values(array_slice(array_unique(array_map(
            static fn (array $entityRef): string => (string) $entityRef['entity_key'],
            $entityRefs
        )), 0, max(1, $limit)));

        $payload = $this->commit([[
            'statement' => <<<CYPHER
UNWIND \$entity_keys AS entity_key
MATCH (e:Entity {entity_key: entity_key})-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (e)-[:BELONGS_TO]->(s:Scope)
OPTIONAL MATCH (e)-[:HAS_CHUNK]->(c:Chunk)
WHERE (\$collections_empty = true OR e.model_class IN \$collections)
  AND {$this->accessPredicate('e')}
WITH e, a, s, c, entity_key
ORDER BY c.chunk_index ASC
RETURN e{.*} AS e, a{.*} AS a, s{.*} AS s, collect(c{.*}) AS chunks, entity_key
LIMIT \$limit
CYPHER,
            'parameters' => [
                'entity_keys' => $entityKeys,
                'collections' => array_values($collections),
                'collections_empty' => $collections === [],
                'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
                'user_email_normalized' => $userScope['user_email_normalized'] ?? null,
                'limit' => max(1, $limit),
            ],
        ]]);

        return collect($payload)
            ->map(function (array $row) use ($userScope) {
                $result = $this->mapRowToResult($row, query: null, userScope: $userScope, prioritizeFirstChunk: true);
                if ($result !== null) {
                    $result->vector_metadata = is_array($result->vector_metadata ?? null) ? $result->vector_metadata : [];
                    $result->vector_metadata['planner_seed'] = 'last_entity_list';
                }

                return $result;
            })
            ->filter()
            ->values();
    }

    protected function searchChunks(string $query, array $collections, int $limit, array $userScope): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $vectorPayload = $this->searchChunksByVector($query, $collections, $limit, $userScope);
        if ($vectorPayload === []) {
            $vectorPayload = $this->searchChunksByText($query, $collections, $limit, $userScope);
        }

        return collect($vectorPayload)
            ->map(fn (array $row) => $this->mapRowToResult($row, query: $query, userScope: $userScope))
            ->filter()
            ->sortByDesc('vector_score')
            ->unique(fn ($item) => (string) ($item->entity_key ?? $item->id) . ':' . (string) ($item->matched_chunk_index ?? 0))
            ->values();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function searchChunksByVector(string $query, array $collections, int $limit, array $userScope): array
    {
        if ($this->embeddingService === null || $this->transport === null) {
            return [];
        }

        try {
            $embedding = array_map('floatval', $this->embeddingService->embed($query));
        } catch (\Throwable $e) {
            Log::warning('Neo4j vector retrieval embedding generation failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $statement = [[
            'statement' => <<<CYPHER
CALL db.index.vector.queryNodes(\$index_name, \$candidate_limit, \$embedding)
YIELD node, score
MATCH (e:Entity)-[:HAS_CHUNK]->(node)
MATCH (e)-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (e)-[:BELONGS_TO]->(s:Scope)
WHERE (\$collections_empty = true OR e.model_class IN \$collections)
  AND {$this->accessPredicate('e')}
RETURN e{.*} AS e, a{.*} AS a, s{.*} AS s, node{.*} AS c, score
ORDER BY score DESC
LIMIT \$limit
CYPHER,
            'parameters' => [
                'index_name' => $this->vectorIndexName(),
                'candidate_limit' => $this->candidateLimit($limit),
                'embedding' => $embedding,
                'collections' => array_values($collections),
                'collections_empty' => $collections === [],
                'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
                'user_email_normalized' => $userScope['user_email_normalized'] ?? null,
                'limit' => $limit,
            ],
        ]];

        $result = $this->transport->executeStatement($statement[0]);
        if (!$result['success'] && $this->isMissingVectorIndexError($result['error'] ?? null)) {
            $resolvedIndex = $this->discoverExistingVectorIndexName();
            if ($resolvedIndex !== null && $resolvedIndex !== $statement[0]['parameters']['index_name']) {
                $statement[0]['parameters']['index_name'] = $resolvedIndex;
                $result = $this->transport->executeStatement($statement[0]);
            }
        }
        if (!$result['success'] && $this->isVectorDimensionMismatchError($result['error'] ?? null)) {
            $this->resolvedVectorIndexName = null;

            Log::notice('Neo4j vector retrieval fell back because no compatible vector index matched embedding dimensions', [
                'configured_index' => $statement[0]['parameters']['index_name'] ?? null,
                'expected_dimensions' => $this->expectedEmbeddingDimensions(),
                'error' => $result['error'] ?? null,
            ]);
        }

        return $result['success'] ? $result['rows'] : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function searchChunksByText(string $query, array $collections, int $limit, array $userScope): array
    {
        $terms = $this->expandQueryTerms($query);
        $rows = [];

        foreach ($terms as $term) {
            $payload = $this->commit([[
                'statement' => <<<CYPHER
MATCH (e:Entity)-[:HAS_CHUNK]->(c:Chunk)
MATCH (e)-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (e)-[:BELONGS_TO]->(s:Scope)
WHERE (\$collections_empty = true OR e.model_class IN \$collections)
  AND {$this->accessPredicate('e')}
  AND (
    toLower(c.content) CONTAINS toLower(\$term)
    OR toLower(coalesce(e.title, '')) CONTAINS toLower(\$term)
    OR toLower(coalesce(e.rag_summary, '')) CONTAINS toLower(\$term)
  )
RETURN e{.*} AS e, a{.*} AS a, s{.*} AS s, c{.*} AS c
LIMIT \$limit
CYPHER,
                'parameters' => [
                    'collections' => array_values($collections),
                    'collections_empty' => $collections === [],
                    'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
                    'user_email_normalized' => $userScope['user_email_normalized'] ?? null,
                    'term' => $term,
                    'limit' => $limit,
                ],
            ]]);

            foreach ($payload as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    protected function planGraphCandidates(
        Collection $seedResults,
        array $collections,
        array $userScope,
        int $limit,
        string $query,
        array $plan
    ): Collection {
        if ($seedResults->isEmpty()) {
            return collect();
        }

        $seedFloor = max(0.1, min(0.95, (float) config('ai-engine.graph.planner_seed_score_floor', 0.65)));
        $hits = $seedResults
            ->filter(fn ($item) => !empty($item->entity_key))
            ->map(fn ($item) => [
                'entity_key' => (string) $item->entity_key,
                'score' => max($seedFloor, (float) ($item->vector_score ?? 0.0)),
                'seed_type' => (string) (($item->vector_metadata['planner_seed'] ?? 'semantic')),
            ])
            ->unique('entity_key')
            ->values()
            ->all();

        if ($hits === []) {
            return collect();
        }

        $maxHops = max(1, (int) ($plan['max_hops'] ?? config('ai-engine.graph.max_traversal_hops', 2)));
        $traversal = $this->plannerTraversalTemplate($plan, $query, $maxHops);
        $plan['cypher_plan_signature'] = $traversal['signature'] ?? null;
        $plan['cypher_plan_explanation'] = $traversal['explanation'] ?? null;
        $plan['planner_filters'] = $traversal['filters'] ?? [];
        $payload = $this->commit([[
            'statement' => $traversal['statement'],
            'parameters' => array_merge([
                'hits' => $hits,
                'collections' => array_values($collections),
                'collections_empty' => $collections === [],
                'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
                'user_email_normalized' => $userScope['user_email_normalized'] ?? null,
                'limit' => $limit,
                'include_self' => false,
                'relation_types' => array_values($plan['relation_types'] ?? []),
                'preferred_model_types' => array_values(array_map('strtolower', $plan['preferred_model_types'] ?? [])),
            ], $traversal['parameters']),
        ]]);

        $hopDecay = max(0.1, min(1.0, (float) config('ai-engine.graph.relation_hop_decay', 0.9)));

        return collect($payload)
            ->map(function (array $row) use ($userScope, $hopDecay, $plan, $query) {
                $result = $this->mapRowToResult($row, query: $query, userScope: $userScope, prioritizeFirstChunk: true);
                if ($result === null) {
                    return null;
                }

                $sourceScore = is_numeric($row['source_score'] ?? null) ? (float) $row['source_score'] : 0.7;
                $lexicalScore = (float) ($result->vector_metadata['lexical_score'] ?? 0.5);
                $pathLength = max(0, (int) ($row['path_length'] ?? 0));
                $seedType = (string) ($row['seed_type'] ?? 'semantic');
                $relationPath = array_values(array_filter(
                    is_array($row['relation_path'] ?? null) ? $row['relation_path'] : [],
                    static fn ($type): bool => is_string($type) && $type !== ''
                ));

                $pathMultiplier = $pathLength === 0 ? 1.0 : ($hopDecay ** max(0, $pathLength - 1));
                $baseScore = ($sourceScore * (float) ($plan['vector_weight'] ?? 0.6))
                    + ($lexicalScore * (float) ($plan['lexical_weight'] ?? 0.4));
                if (!empty($plan['relationship_query'])) {
                    $baseScore += (float) ($plan['relationship_bonus'] ?? 0.05);
                }
                if ($seedType === 'selected_entity') {
                    $baseScore += (float) ($plan['selected_seed_boost'] ?? 0.05);
                }
                $baseScore += $this->queryKindScoreBonus($plan, $result, $relationPath);

                $plannerScore = round(max(0.12, min(0.99, $baseScore * $pathMultiplier)), 4);
                $result->vector_score = $plannerScore;
                $result->vector_metadata['graph_planned'] = true;
                $result->vector_metadata['planner_strategy'] = $plan['strategy'] ?? 'semantic_graph_planner';
                $result->vector_metadata['planner_query_kind'] = $plan['query_kind'] ?? 'generic';
                $result->vector_metadata['planner_score'] = $plannerScore;
                if ((bool) config('ai-engine.graph.planner_score_breakdown', true)) {
                    $result->vector_metadata['planner_score_breakdown'] = [
                        'source_score' => round($sourceScore, 4),
                        'lexical_score' => round($lexicalScore, 4),
                        'vector_component' => round($sourceScore * (float) ($plan['vector_weight'] ?? 0.6), 4),
                        'lexical_component' => round($lexicalScore * (float) ($plan['lexical_weight'] ?? 0.4), 4),
                        'path_multiplier' => round($pathMultiplier, 4),
                        'selected_seed_boost' => $seedType === 'selected_entity' ? round((float) ($plan['selected_seed_boost'] ?? 0.05), 4) : 0.0,
                        'relationship_bonus' => !empty($plan['relationship_query']) ? round((float) ($plan['relationship_bonus'] ?? 0.05), 4) : 0.0,
                        'query_kind_bonus' => round($this->queryKindScoreBonus($plan, $result, $relationPath), 4),
                    ];
                }
                $result->vector_metadata['planner_seed'] = $seedType;
                $result->vector_metadata['relation_expanded'] = $pathLength > 0;
                $result->vector_metadata['relation_type'] = $relationPath[0] ?? 'SELF';
                $result->vector_metadata['relation_path'] = $relationPath;
                $result->vector_metadata['path_length'] = $pathLength;
                $result->vector_metadata['source_entity_key'] = $row['source_entity_key'] ?? null;
                $result->vector_metadata['cypher_plan_signature'] = $row['cypher_plan_signature'] ?? ($plan['cypher_plan_signature'] ?? null);
                $result->vector_metadata['cypher_plan_explanation'] = $row['cypher_plan_explanation'] ?? ($plan['cypher_plan_explanation'] ?? null);
                $result->vector_metadata['planner_filters'] = $row['planner_filters'] ?? ($plan['planner_filters'] ?? []);

                if ($pathLength > 0 && !empty($result->matched_chunk_text)) {
                    $pathLabel = $relationPath !== [] ? implode(' -> ', $relationPath) : 'RELATED_TO';
                    if (!str_starts_with($result->matched_chunk_text, '[' . $pathLabel . ']')) {
                        $result->matched_chunk_text = '[' . $pathLabel . "]\n" . $result->matched_chunk_text;
                    }
                }

                return $result;
            })
            ->filter()
            ->sortBy([
                ['vector_score', 'desc'],
                [static fn ($item) => (int) ($item->vector_metadata['path_length'] ?? 99), 'asc'],
            ])
            ->values();
    }

    protected function mergePlannedResults(Collection $seedResults, Collection $planned): Collection
    {
        $combined = [];

        foreach ($seedResults->merge($planned) as $item) {
            $key = (string) ($item->entity_key ?? $item->id ?? '');
            if ($key === '') {
                continue;
            }

            if (!isset($combined[$key])) {
                $combined[$key] = $item;
                continue;
            }

            if ((float) ($item->vector_score ?? 0.0) > (float) ($combined[$key]->vector_score ?? 0.0)) {
                $combined[$key] = $item;
                continue;
            }

            $existingMeta = is_array($combined[$key]->vector_metadata ?? null) ? $combined[$key]->vector_metadata : [];
            $incomingMeta = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];
            if (($incomingMeta['graph_planned'] ?? false) === true) {
                $combined[$key]->vector_metadata = array_merge($existingMeta, $incomingMeta);

                $incomingPathLength = (int) ($incomingMeta['path_length'] ?? PHP_INT_MAX);
                $existingPathLength = (int) ($existingMeta['path_length'] ?? PHP_INT_MAX);
                if ($incomingPathLength < $existingPathLength && !empty($incomingMeta['relation_path'])) {
                    if (!empty($incomingMeta['chunk_text']) && empty($combined[$key]->vector_metadata['related_chunk_text'])) {
                        $combined[$key]->vector_metadata['related_chunk_text'] = $incomingMeta['chunk_text'];
                    }
                    if (!empty($incomingMeta['object']) && empty($combined[$key]->vector_metadata['related_object'])) {
                        $combined[$key]->vector_metadata['related_object'] = $incomingMeta['object'];
                    }
                }
            }
        }

        return collect(array_values($combined))
            ->sortByDesc('vector_score')
            ->values();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function cacheSignals(array $options): array
    {
        $selected = is_array($options['selected_entity'] ?? $options['selected_entity_context'] ?? null)
            ? ($options['selected_entity'] ?? $options['selected_entity_context'])
            : [];
        $selectedRef = is_array($selected['entity_ref'] ?? null) ? $selected['entity_ref'] : [];
        $lastEntityRefs = array_values(array_filter(
            (array) (($options['last_entity_list']['entity_refs'] ?? [])),
            static fn ($ref): bool => is_array($ref) && !empty($ref['entity_key'])
        ));

        return [
            'selected_entity_key' => $selectedRef['entity_key'] ?? null,
            'last_entity_keys' => array_values(array_map(
                static fn (array $ref): string => (string) $ref['entity_key'],
                $lastEntityRefs
            )),
        ];
    }

    /**
     * @return array{statement:string,parameters:array<string,mixed>}
     */
    protected function plannerTraversalTemplate(array $plan, string $query, int $maxHops): array
    {
        if ($this->cypherPlanCompiler !== null) {
            return $this->cypherPlanCompiler->compileTraversal($plan, $query, $maxHops, $this->accessPredicate('n'));
        }

        return [
            'statement' => <<<'CYPHER'
UNWIND $hits AS hit
MATCH path = (start:Entity {entity_key: hit.entity_key})-[*0..1]-(n:Entity)
MATCH (n)-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (n)-[:BELONGS_TO]->(s:Scope)
OPTIONAL MATCH (n)-[:HAS_CHUNK]->(c:Chunk)
WHERE all(rel IN relationships(path) WHERE type(rel) <> 'HAS_CHUNK' AND type(rel) <> 'SOURCE_APP' AND type(rel) <> 'CAN_ACCESS')
  AND ($include_self = true OR length(path) > 0)
  AND ($collections_empty = true OR n.model_class IN $collections)
WITH hit,
     path,
     n,
     a,
     s,
     c,
     CASE WHEN length(path) = 0 THEN ['SELF'] ELSE [rel IN relationships(path) | type(rel)] END AS relation_path
ORDER BY c.chunk_index ASC
WITH hit,
     length(path) AS path_length,
     relation_path,
     n,
     a,
     s,
     collect(c{.*}) AS chunks
RETURN hit.entity_key AS source_entity_key,
       hit.score AS source_score,
       hit.seed_type AS seed_type,
       path_length,
       relation_path,
       n{.*} AS e,
       a{.*} AS a,
       s{.*} AS s,
       chunks
LIMIT $limit
CYPHER,
            'parameters' => [],
            'signature' => 'fallback',
            'explanation' => 'fallback traversal template',
            'filters' => [],
        ];
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<int, string> $relationPath
     */
    protected function queryKindScoreBonus(array $plan, object $result, array $relationPath): float
    {
        $bonus = 0.0;
        $queryKind = (string) ($plan['query_kind'] ?? 'generic');
        $preferredModelTypes = array_map('strtolower', (array) ($plan['preferred_model_types'] ?? []));
        $resultModelType = strtolower((string) ($result->vector_metadata['model_type'] ?? $result->model_type ?? ''));

        if ($preferredModelTypes !== [] && in_array($resultModelType, $preferredModelTypes, true)) {
            $bonus += 0.05;
        }

        $focusTerms = array_map('strtolower', (array) ($plan['lexical_focus_terms'] ?? []));
        $content = strtolower(trim(implode(' ', array_filter([
            (string) ($result->title ?? ''),
            (string) ($result->content ?? ''),
            (string) ($result->matched_chunk_text ?? ''),
            (string) ($result->vector_metadata['chunk_text'] ?? ''),
        ]))));
        foreach ($focusTerms as $term) {
            if ($term !== '' && str_contains($content, $term)) {
                $bonus += 0.015;
            }
        }

        $normalizedPath = array_map('strtoupper', $relationPath);
        if ($queryKind === 'ownership' && count(array_intersect($normalizedPath, ['OWNED_BY', 'CREATED_BY', 'ASSIGNED_TO', 'MANAGED_BY', 'REPORTED_BY', 'BELONGS_TO'])) > 0) {
            $bonus += 0.06;
        }
        if ($queryKind === 'dependency' && count(array_intersect($normalizedPath, ['DEPENDS_ON', 'BLOCKED_BY', 'HAS_RELATED', 'RELATED_TO', 'HAS_TASK', 'HAS_PROJECT', 'HAS_MAIL', 'HAS_TICKET', 'HAS_ISSUE'])) > 0) {
            $bonus += 0.06;
        }
        if ($queryKind === 'communication' && count(array_intersect($normalizedPath, ['SENT_BY', 'SENT_TO', 'REPLIED_TO', 'MENTIONS', 'HAS_ATTACHMENT', 'IN_THREAD', 'IN_CHANNEL', 'HAS_MESSAGE', 'HAS_COMMENT', 'HAS_MAIL'])) > 0) {
            $bonus += 0.06;
        }
        if ($queryKind === 'timeline' && count(array_intersect($normalizedPath, ['BELONGS_TO', 'IN_PROJECT', 'IN_WORKSPACE', 'IN_MILESTONE', 'IN_SPRINT', 'HAS_RELATED', 'RELATED_TO', 'HAS_TASK', 'HAS_MAIL', 'HAS_PROJECT', 'OWNED_BY', 'CREATED_BY', 'ASSIGNED_TO', 'MANAGED_BY', 'REPORTED_BY', 'HAS_USER', 'HAS_MESSAGE', 'HAS_COMMENT'])) > 0) {
            $bonus += 0.03;
        }

        return $bonus;
    }

    protected function attachNeighborContext(Collection $results, int $neighborLimit, array $userScope = []): Collection
    {
        if ($results->isEmpty() || $neighborLimit <= 0) {
            return $results;
        }

        $entityKeys = $results->pluck('entity_key')->filter()->values()->all();
        if ($entityKeys === []) {
            return $results;
        }

        $neighborsByKey = [];
        $missingEntityKeys = [];
        $snapshotHitKeys = [];

        foreach ($entityKeys as $entityKey) {
            $snapshot = $this->knowledgeBase?->getEntitySnapshot((string) $entityKey, $userScope);
            if (is_array($snapshot) && isset($snapshot['neighbors']) && is_array($snapshot['neighbors'])) {
                $neighborsByKey[(string) $entityKey] = $snapshot['neighbors'];
                $snapshotHitKeys[(string) $entityKey] = true;
                continue;
            }

            $missingEntityKeys[] = (string) $entityKey;
        }

        if ($missingEntityKeys !== []) {
            $payload = $this->commit([[
                'statement' => <<<'CYPHER'
UNWIND $entity_keys AS entity_key
MATCH (e:Entity {entity_key: entity_key})
OPTIONAL MATCH (e)-[r]-(n:Entity)
RETURN entity_key,
       collect({
         relation_type: type(r),
         model_id: n.model_id,
         model_class: n.model_class,
         title: n.title,
         rag_summary: n.rag_summary,
         entity_key: n.entity_key
       })[0..$neighbor_limit] AS neighbors
CYPHER,
                'parameters' => [
                    'entity_keys' => $missingEntityKeys,
                    'neighbor_limit' => $neighborLimit,
                ],
            ]]);

            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $key = data_get($row, 'entity_key');
                if (!is_string($key) || $key === '') {
                    continue;
                }

                $neighbors = array_values(array_filter(
                    data_get($row, 'neighbors', []),
                    static fn ($neighbor): bool => is_array($neighbor) && !empty($neighbor['entity_key'])
                ));
                $neighborsByKey[$key] = $neighbors;
                $this->knowledgeBase?->cacheEntitySnapshot($key, $userScope, [
                    'neighbors' => $neighbors,
                    'neighbor_limit' => $neighborLimit,
                ]);
            }
        }

        return $results->map(function ($item) use ($neighborsByKey, $snapshotHitKeys) {
            $neighbors = $neighborsByKey[$item->entity_key ?? ''] ?? [];
            $item->graph_neighbors = $neighbors;
            $item->vector_metadata = is_array($item->vector_metadata ?? null) ? $item->vector_metadata : [];
            $item->vector_metadata['graph_neighbors'] = $neighbors;
            $item->vector_metadata['graph_snapshot_cache_hit'] = isset($snapshotHitKeys[$item->entity_key ?? '']);

            if ($neighbors !== []) {
                $neighborText = collect($neighbors)
                    ->map(function (array $neighbor): string {
                        $title = $neighbor['title'] ?? class_basename((string) ($neighbor['model_class'] ?? 'Entity'));
                        $summary = trim((string) ($neighbor['rag_summary'] ?? ''));
                        $relation = $neighbor['relation_type'] ?? 'RELATED_TO';

                        return $summary !== ''
                            ? "{$relation}: {$title} - {$summary}"
                            : "{$relation}: {$title}";
                    })
                    ->implode("\n");

                if (!empty($item->matched_chunk_text)) {
                    $item->matched_chunk_text .= "\n\nRelated context:\n" . $neighborText;
                } elseif (!empty($item->content)) {
                    $item->content .= "\n\nRelated context:\n" . $neighborText;
                }
            }

            return $item;
        });
    }

    protected function mapRowToResult(?array $row, ?string $query, array $userScope, bool $prioritizeFirstChunk = false): ?object
    {
        if (!$row) {
            return null;
        }

        $entity = data_get($row, 'e', []);
        $app = data_get($row, 'a', []);
        $scope = data_get($row, 's', []);
        $chunk = data_get($row, 'c', []);
        $chunks = data_get($row, 'chunks', []);

        if (!is_array($entity) || $entity === []) {
            return null;
        }

        if ($prioritizeFirstChunk && $chunk === [] && is_array($chunks) && $chunks !== []) {
            $chunk = $chunks[0];
        }

        $object = [];
        $objectJson = $entity['object_json'] ?? null;
        if (is_string($objectJson) && $objectJson !== '') {
            $decoded = json_decode($objectJson, true);
            if (is_array($decoded)) {
                $object = $decoded;
            }
        }

        $chunkText = trim((string) ($chunk['content'] ?? ''));
        $title = $entity['title'] ?? ($object['title'] ?? null);
        $summary = $entity['rag_summary'] ?? ($object['summary'] ?? null);
        $detail = $entity['rag_detail'] ?? null;
        $entityKey = (string) ($entity['entity_key'] ?? '');
        $modelClass = (string) ($entity['model_class'] ?? '');
        $modelId = $entity['model_id'] ?? ($object['id'] ?? null);
        $lexicalScore = $this->calculateScore($query, $chunkText, $title, $summary);
        $score = $this->blendScore(
            is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
            $lexicalScore
        );

        $result = new \stdClass();
        $result->id = $modelId;
        $result->entity_key = $entityKey;
        $result->model_type = class_basename($modelClass);
        $result->title = $title;
        $result->name = $object['name'] ?? null;
        $result->subject = $object['subject'] ?? null;
        $result->content = $chunkText !== '' ? $chunkText : (string) ($detail ?: $summary ?: '');
        $result->matched_chunk_text = $chunkText !== '' ? $chunkText : null;
        $result->matched_chunk_index = $chunk['chunk_index'] ?? null;
        $result->vector_score = $score;
        $result->source_node = $entity['source_node'] ?? null;
        $result->source_node_name = $app['name'] ?? null;
        $result->vector_metadata = [
            'model_class' => $modelClass,
            'model_id' => $modelId,
            'model_type' => class_basename($modelClass),
            'entity_ref' => array_filter([
                'entity_key' => $entityKey,
                'model_id' => $modelId,
                'model_class' => $modelClass,
                'model_type' => class_basename($modelClass),
                'source_node' => $entity['source_node'] ?? null,
                'app_slug' => $entity['app_slug'] ?? ($app['slug'] ?? null),
                'scope_type' => $scope['scope_type'] ?? null,
                'scope_id' => $scope['scope_id'] ?? null,
                'scope_label' => $scope['scope_label'] ?? null,
                'canonical_user_id' => $userScope['canonical_user_id'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'object' => $object,
            'chunk_text' => $chunkText,
            'chunk_index' => $chunk['chunk_index'] ?? null,
            'chunk_preview' => $chunk['content_preview'] ?? Str::limit($chunkText, 200, '...'),
            'lexical_score' => $lexicalScore,
            'raw_vector_score' => is_numeric($row['score'] ?? null) ? (float) $row['score'] : null,
            'app_slug' => $entity['app_slug'] ?? ($app['slug'] ?? null),
            'source_node' => $entity['source_node'] ?? null,
            'scope_type' => $scope['scope_type'] ?? null,
            'scope_id' => $scope['scope_id'] ?? null,
            'scope_label' => $scope['scope_label'] ?? null,
        ];
        $result->graph_object = $object;
        $result->entity_ref = $result->vector_metadata['entity_ref'];

        return $result;
    }

    protected function calculateScore(?string $query, ?string $chunkText, ?string $title, ?string $summary): float
    {
        $query = Str::lower(trim((string) $query));
        if ($query === '') {
            return 1.0;
        }

        $haystack = Str::lower(trim(implode(' ', array_filter([$title, $summary, $chunkText]))));
        if ($haystack === '') {
            return 0.5;
        }

        if (str_contains($haystack, $query)) {
            return 0.98;
        }

        preg_match_all('/[a-z0-9]+/i', $query, $queryMatches);
        $queryTerms = array_values(array_filter(
            $queryMatches[0] ?? [],
            static fn (string $term): bool => !in_array($term, [
                'a', 'an', 'the', 'is', 'are', 'was', 'were', 'on', 'in', 'at', 'to', 'for',
                'of', 'and', 'or', 'me', 'show', 'tell', 'what', 'which', 'latest', 'status',
            ], true)
        ));
        if ($queryTerms === []) {
            return 0.5;
        }

        $hits = 0;
        foreach ($queryTerms as $term) {
            if (str_contains($haystack, $term)) {
                $hits++;
            }
        }

        $ratio = $hits / count($queryTerms);

        return round(max(0.2, min(0.95, 0.2 + (0.75 * $ratio))), 4);
    }

    protected function blendScore(?float $vectorScore, float $lexicalScore): float
    {
        if ($vectorScore === null) {
            return $lexicalScore;
        }

        $vectorScore = max(0.0, min(1.0, $vectorScore));
        $lexicalScore = max(0.0, min(1.0, $lexicalScore));

        if ((bool) config('ai-engine.vector.testing.use_fake_embeddings', false)) {
            return round(max($lexicalScore, ($vectorScore * 0.4) + ($lexicalScore * 0.6)), 4);
        }

        return round(($vectorScore * 0.7) + ($lexicalScore * 0.3), 4);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveUserScope($userId, array $options): array
    {
        $selectedEntity = $options['selected_entity'] ?? $options['selected_entity_context'] ?? [];
        $selectedRef = is_array($selectedEntity['entity_ref'] ?? null) ? $selectedEntity['entity_ref'] : [];
        $selectedObject = is_array($selectedEntity['object'] ?? null) ? $selectedEntity['object'] : [];

        $scope = [
            'app_slug' => $selectedRef['app_slug'] ?? $selectedObject['app_slug'] ?? null,
            'scope_type' => $selectedRef['scope_type'] ?? $selectedObject['scope_type'] ?? null,
            'scope_id' => $selectedRef['scope_id'] ?? $selectedObject['scope_id'] ?? null,
            'canonical_user_id' => $selectedRef['canonical_user_id'] ?? $selectedObject['canonical_user_id'] ?? null,
            'user_email_normalized' => $selectedRef['user_email_normalized'] ?? $selectedObject['user_email_normalized'] ?? null,
        ];

        $explicitScope = is_array($options['access_scope'] ?? null) ? $options['access_scope'] : [];
        foreach (['app_slug', 'scope_type', 'scope_id', 'canonical_user_id', 'user_email_normalized'] as $key) {
            if (($scope[$key] ?? null) === null && !empty($explicitScope[$key])) {
                $scope[$key] = (string) $explicitScope[$key];
            }
        }

        $userModelClass = config('ai-engine.user_model');
        if ($userModelClass && class_exists($userModelClass) && $userId !== null) {
            try {
                $user = $userModelClass::query()->find($userId);
                if ($user) {
                    $scope['canonical_user_id'] ??= $user->canonical_user_id ?? (method_exists($user, 'getAuthIdentifier') ? (string) $user->getAuthIdentifier() : null);
                    if (!empty($user->email)) {
                        $scope['user_email_normalized'] ??= Str::lower(trim((string) $user->email));
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('Neo4j user scope resolution failed', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_filter($scope, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @return array<int, string>
     */
    protected function expandQueryTerms(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $terms = [$query];

        $quoted = trim($query, "\"' ");
        if ($quoted !== '' && $quoted !== $query) {
            $terms[] = $quoted;
        }

        $words = preg_split('/\s+/', $query) ?: [];
        if (count($words) > 1) {
            $terms[] = implode(' ', array_slice($words, 0, 2));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($term): string => trim((string) $term),
            $terms
        ))));
    }

    /**
     * @param array<int, array<string, mixed>> $statements
     * @return array<int, array<string, mixed>>
     */
    protected function commit(array $statements): array
    {
        if ($this->transport === null) {
            return [];
        }

        if (count($statements) === 1) {
            $result = $this->transport->executeStatement($statements[0]);

            return $result['success'] ? $result['rows'] : [];
        }

        $result = $this->transport->executeTransaction($statements);

        return $result['success'] ? $result['rows'] : [];
    }

    protected function vectorIndexName(): string
    {
        if ($this->resolvedVectorIndexName !== null) {
            return $this->resolvedVectorIndexName;
        }

        return $this->vectorNaming?->indexName() ?? 'chunk_embedding_index';
    }

    protected function accessPredicate(string $entityAlias): string
    {
        return <<<CYPHER
CASE
  WHEN \$canonical_user_id IS NOT NULL THEN EXISTS {
    MATCH (u:User {canonical_user_id: \$canonical_user_id})-[:CAN_ACCESS]->({$entityAlias})
  } OR EXISTS {
    MATCH (u:User {canonical_user_id: \$canonical_user_id})-[:CAN_ACCESS]->(:Scope)<-[:BELONGS_TO]-({$entityAlias})
  }
  WHEN \$user_email_normalized IS NOT NULL THEN EXISTS {
    MATCH (u:User {user_email_normalized: \$user_email_normalized})-[:CAN_ACCESS]->({$entityAlias})
  } OR EXISTS {
    MATCH (u:User {user_email_normalized: \$user_email_normalized})-[:CAN_ACCESS]->(:Scope)<-[:BELONGS_TO]-({$entityAlias})
  }
  ELSE true
END
CYPHER;
    }

    protected function candidateLimit(int $limit): int
    {
        $multiplier = max(1, (int) config('ai-engine.graph.neo4j.vector_candidate_multiplier', 3));

        return max($limit, $limit * $multiplier);
    }

    protected function isMissingVectorIndexError(?string $error): bool
    {
        $error = Str::lower((string) $error);

        return str_contains($error, 'no such vector schema index');
    }

    protected function isVectorDimensionMismatchError(?string $error): bool
    {
        $error = Str::lower((string) $error);

        return str_contains($error, 'configured dimensionality')
            && str_contains($error, 'provided vector has dimension');
    }

    protected function discoverExistingVectorIndexName(): ?string
    {
        if ($this->transport === null) {
            return null;
        }

        $result = $this->transport->executeStatement([
            'statement' => <<<'CYPHER'
SHOW VECTOR INDEXES
YIELD name, labelsOrTypes, properties, state, options
WHERE 'Chunk' IN labelsOrTypes
  AND $vector_property IN properties
  AND state = 'ONLINE'
RETURN name, options
ORDER BY name ASC
CYPHER,
            'parameters' => [
                'vector_property' => $this->vectorPropertyName(),
            ],
        ]);

        if (!$result['success']) {
            return null;
        }

        $expectedDimensions = $this->expectedEmbeddingDimensions();
        $fallbackName = null;
        foreach ((array) $result['rows'] as $row) {
            $name = $row['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $fallbackName ??= $name;
            if ($expectedDimensions === null) {
                $this->resolvedVectorIndexName = $name;

                return $name;
            }

            $configuredDimensions = $this->extractVectorIndexDimensions($row['options'] ?? null);

            if (is_numeric($configuredDimensions) && (int) $configuredDimensions === $expectedDimensions) {
                $this->resolvedVectorIndexName = $name;

                return $name;
            }
        }

        if ($expectedDimensions === null && $fallbackName !== null) {
            $this->resolvedVectorIndexName = $fallbackName;

            return $fallbackName;
        }

        return null;
    }

    protected function expectedEmbeddingDimensions(): ?int
    {
        $dimensions = config('ai-engine.vector.embedding_dimensions');

        return is_numeric($dimensions) ? (int) $dimensions : null;
    }

    protected function vectorPropertyName(): string
    {
        return $this->vectorNaming?->propertyName() ?? 'embedding';
    }

    protected function recordRankingFeedback(array $plan, Collection $results): void
    {
        if ($this->rankingFeedback === null) {
            return;
        }

        $top = $results->first();
        $queryKind = (string) ($plan['query_kind'] ?? 'generic');
        $rawVectorScore = is_object($top) ? (float) ($top->vector_metadata['raw_vector_score'] ?? 0.0) : 0.0;
        $lexicalScore = is_object($top) ? (float) ($top->vector_metadata['lexical_score'] ?? 0.0) : 0.0;

        $this->rankingFeedback->recordOutcome($queryKind, [
            'vector_dominant' => $rawVectorScore >= $lexicalScore,
            'lexical_dominant' => $lexicalScore > $rawVectorScore,
            'relation_helpful' => $results->contains(fn ($item) => (bool) (($item->vector_metadata['relation_expanded'] ?? false) === true)),
            'selected_seed_helpful' => $results->contains(fn ($item) => (($item->vector_metadata['planner_seed'] ?? null) === 'selected_entity')),
            'empty_results' => $results->isEmpty(),
            'cache_hit' => $results->contains(fn ($item) => (bool) (($item->vector_metadata['graph_kb_cache_hit'] ?? false) === true)),
        ]);
    }

    protected function extractVectorIndexDimensions(mixed $options): ?int
    {
        if (!is_array($options)) {
            return null;
        }

        $indexConfig = is_array($options['indexConfig'] ?? null) ? $options['indexConfig'] : [];
        foreach (['vector.dimensions', 'vector_dimensions', 'dimensions'] as $key) {
            $value = $indexConfig[$key] ?? null;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

}
