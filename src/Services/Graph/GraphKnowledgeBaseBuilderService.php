<?php

namespace LaravelAIEngine\Services\Graph;

class GraphKnowledgeBaseBuilderService
{
    public function __construct(
        protected GraphKnowledgeBaseService $knowledgeBase,
        protected Neo4jHttpTransport $transport,
        protected GraphQueryPlanner $planner,
        protected Neo4jRetrievalService $retrieval
    ) {}

    /**
     * @param array<string, mixed> $scope
     * @return array{plans:int,results:int}
     */
    public function warmFromProfiles(array $scope, int $limit = 25, int $maxResults = 5, bool $planOnly = false, ?int $userId = null): array
    {
        $profiles = $this->knowledgeBase->listQueryProfiles(max(1, $limit));
        $planCount = 0;
        $resultCount = 0;

        foreach ($profiles as $profile) {
            $query = trim((string) ($profile['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            $collections = array_values((array) ($profile['collections'] ?? []));
            $signals = (array) ($profile['signals'] ?? []);
            $options = ['access_scope' => $scope];

            $this->knowledgeBase->rememberPlan(
                $query,
                $collections,
                $scope,
                $signals,
                fn (): array => $this->planner->plan($query, $collections, $options, $maxResults)
            );
            $planCount++;

            if ($planOnly || $scope === []) {
                continue;
            }

            $this->retrieval->retrieveRelevantContext([$query], $collections, $maxResults, $options, $userId);
            $resultCount++;
        }

        return ['plans' => $planCount, 'results' => $resultCount];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array{snapshots:int}
     */
    public function buildEntitySnapshots(array $scope, int $limit = 25): array
    {
        $result = $this->transport->executeStatement([
            'statement' => <<<'CYPHER'
MATCH (e:Entity)-[:SOURCE_APP]->(a:App)
OPTIONAL MATCH (e)-[:BELONGS_TO]->(s:Scope)
WHERE ($canonical_user_id IS NULL AND $user_email_normalized IS NULL)
   OR EXISTS { MATCH (:User {canonical_user_id: $canonical_user_id})-[:CAN_ACCESS]->(e) }
   OR EXISTS { MATCH (:User {user_email_normalized: $user_email_normalized})-[:CAN_ACCESS]->(e) }
   OR (
        s IS NOT NULL AND (
            EXISTS { MATCH (:User {canonical_user_id: $canonical_user_id})-[:CAN_ACCESS]->(s) }
            OR EXISTS { MATCH (:User {user_email_normalized: $user_email_normalized})-[:CAN_ACCESS]->(s) }
        )
   )
OPTIONAL MATCH (e)-[r]-(n:Entity)
WITH e, count(DISTINCT r) AS relation_count
ORDER BY relation_count DESC, coalesce(e.updated_at, '') DESC
LIMIT $limit
OPTIONAL MATCH (e)-[r]-(n:Entity)
RETURN e.entity_key AS entity_key,
       e.title AS title,
       e.rag_summary AS rag_summary,
       e.model_class AS model_class,
       collect({
         relation_type: type(r),
         entity_key: n.entity_key,
         title: n.title,
         model_class: n.model_class,
         rag_summary: n.rag_summary
       })[0..5] AS neighbors
CYPHER,
            'parameters' => [
                'canonical_user_id' => $scope['canonical_user_id'] ?? null,
                'user_email_normalized' => $scope['user_email_normalized'] ?? null,
                'limit' => max(1, $limit),
            ],
        ]);

        if (!($result['success'] ?? false)) {
            return ['snapshots' => 0];
        }

        $count = 0;
        foreach ((array) ($result['rows'] ?? []) as $row) {
            $entityKey = trim((string) ($row['entity_key'] ?? ''));
            if ($entityKey === '') {
                continue;
            }

            $snapshot = [
                'title' => $row['title'] ?? null,
                'rag_summary' => $row['rag_summary'] ?? null,
                'model_class' => $row['model_class'] ?? null,
                'neighbors' => array_values(array_filter(
                    (array) ($row['neighbors'] ?? []),
                    static fn ($neighbor): bool => is_array($neighbor) && !empty($neighbor['entity_key'])
                )),
            ];

            $this->knowledgeBase->cacheEntitySnapshot($entityKey, $scope, $snapshot);
            $count++;
        }

        return ['snapshots' => $count];
    }
}
