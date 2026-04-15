<?php

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class GraphKnowledgeBaseService
{
    public function enabled(): bool
    {
        return (bool) config('ai-engine.graph.knowledge_base.enabled', true);
    }

    public function graphVersion(): int
    {
        return (int) Cache::get($this->graphVersionKey(), 1);
    }

    public function bumpGraphVersion(): int
    {
        if (!$this->enabled()) {
            return 1;
        }

        if (!Cache::has($this->graphVersionKey())) {
            Cache::forever($this->graphVersionKey(), 1);
        }

        return (int) Cache::increment($this->graphVersionKey());
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $signals
     */
    public function rememberPlan(string $query, array $collections, array $scope, array $signals, callable $resolver): array
    {
        if (!$this->enabled()) {
            return $resolver();
        }

        $key = 'ai_engine:graph:plan:' . md5(json_encode([
            'planner_signature' => $this->plannerSignature(),
            'query' => $this->normalizeQuery($query),
            'collections' => $this->normalizeCollections($collections),
            'scope' => $this->versionedScope($scope),
            'signals' => $this->normalizeSignals($signals),
        ]));

        return Cache::remember($key, now()->addSeconds($this->planTtl()), function () use ($resolver, $query, $collections, $scope, $signals) {
            $plan = $resolver();
            $this->recordQueryProfile($query, $plan, $collections, $scope, $signals);

            return $plan;
        });
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $signals
     */
    public function getCachedResults(string $query, array $collections, array $scope, array $signals): ?Collection
    {
        if (!$this->enabled() || !(bool) config('ai-engine.graph.knowledge_base.cache_results', true)) {
            return null;
        }

        $payload = Cache::get($this->resultCacheKey($query, $collections, $scope, $signals));
        if (!is_array($payload)) {
            return null;
        }

        return collect($payload)->map(function ($item) {
            $object = new \stdClass();
            foreach ((array) $item as $key => $value) {
                $object->{$key} = $value;
            }
            $object->vector_metadata = is_array($object->vector_metadata ?? null) ? $object->vector_metadata : [];
            $object->vector_metadata['graph_kb_cache_hit'] = true;

            return $object;
        });
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $signals
     */
    public function cacheResults(string $query, array $collections, array $scope, array $signals, Collection $results): void
    {
        if (!$this->enabled() || !(bool) config('ai-engine.graph.knowledge_base.cache_results', true)) {
            return;
        }

        $payload = $results->map(function ($item): array {
            return $this->sanitizeCachedResult($item);
        })->values()->all();

        Cache::put(
            $this->resultCacheKey($query, $collections, $scope, $signals),
            $payload,
            now()->addSeconds($this->resultTtl())
        );
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>|null
     */
    public function getEntitySnapshot(string $entityKey, array $scope): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $payload = Cache::get($this->entitySnapshotKey($entityKey, $scope));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $snapshot
     */
    public function cacheEntitySnapshot(string $entityKey, array $scope, array $snapshot): void
    {
        if (!$this->enabled()) {
            return;
        }

        Cache::put(
            $this->entitySnapshotKey($entityKey, $scope),
            $snapshot,
            now()->addSeconds($this->resultTtl())
        );
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function bumpAccessVersion(array $scope): int
    {
        if (!$this->enabled()) {
            return 1;
        }

        $normalizedScope = $this->normalizeScope($scope);
        $version = 1;

        foreach ($this->accessVersionKeys($normalizedScope) as $key) {
            if (!Cache::has($key)) {
                Cache::forever($key, 1);
            }

            $version = (int) Cache::increment($key);
        }

        return $version;
    }

    public function getQueryProfile(string $query): array
    {
        $profile = Cache::get($this->profileCacheKey($query), []);

        return is_array($profile) ? $profile : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listQueryProfiles(int $limit = 25): array
    {
        $queries = Cache::get($this->profileIndexKey(), []);
        if (!is_array($queries) || $queries === []) {
            return [];
        }

        $queries = array_slice($queries, 0, max(1, $limit));
        $profiles = [];

        foreach ($queries as $query) {
            if (!is_string($query) || trim($query) === '') {
                continue;
            }

            $profile = $this->getQueryProfile($query);
            if ($profile === []) {
                continue;
            }

            $profiles[] = $profile;
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function recordQueryProfile(string $query, array $plan, array $collections = [], array $scope = [], array $signals = []): void
    {
        if (!$this->enabled()) {
            return;
        }

        $existing = $this->getQueryProfile($query);
        $normalizedQuery = $this->normalizeQuery($query);
        $payload = [
            'count' => (int) ($existing['count'] ?? 0) + 1,
            'query' => $normalizedQuery,
            'last_strategy' => $plan['strategy'] ?? 'semantic_only',
            'last_query_kind' => $plan['query_kind'] ?? 'generic',
            'collections' => $this->normalizeCollections($collections),
            'scope_fingerprint' => $this->scopeFingerprint($scope),
            'signals' => $this->normalizeSignals($signals),
            'last_seen_at' => now()->toIso8601String(),
        ];

        Cache::put($this->profileCacheKey($query), $payload, now()->addSeconds($this->profileTtl()));
        $this->rememberProfileQuery($normalizedQuery);
    }

    /**
     * @param array<string, mixed> $scope
     * @param array<string, mixed> $signals
     */
    protected function resultCacheKey(string $query, array $collections, array $scope, array $signals): string
    {
        return 'ai_engine:graph:results:' . md5(json_encode([
            'version' => $this->graphVersion(),
            'query' => $this->normalizeQuery($query),
            'collections' => $this->normalizeCollections($collections),
            'scope' => $this->versionedScope($scope),
            'signals' => $this->normalizeSignals($signals),
        ]));
    }

    /**
     * @param array<string, mixed> $scope
     */
    protected function entitySnapshotKey(string $entityKey, array $scope): string
    {
        return 'ai_engine:graph:entity_snapshot:' . md5(json_encode([
            'version' => $this->graphVersion(),
            'entity_key' => trim($entityKey),
            'scope' => $this->versionedScope($scope),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function profileCacheKey(string $query): string
    {
        return 'ai_engine:graph:profile:' . md5($this->normalizeQuery($query));
    }

    protected function graphVersionKey(): string
    {
        return 'ai_engine:graph:version';
    }

    protected function profileIndexKey(): string
    {
        return 'ai_engine:graph:profile:index';
    }

    protected function normalizeQuery(string $query): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $query) ?? ''));
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    protected function normalizeCollections(array $collections): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($collection): string => trim((string) $collection),
            $collections
        ))));

        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, string>
     */
    protected function normalizeScope(array $scope): array
    {
        $normalized = [];
        foreach ($scope as $key => $value) {
            if (!is_string($key) || $key === '' || is_array($value) || is_object($value) || $value === null || $value === '') {
                continue;
            }

            $normalized[$key] = trim((string) $value);
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    protected function versionedScope(array $scope): array
    {
        $normalized = $this->normalizeScope($scope);
        $versions = [];

        foreach ($this->accessVersionKeys($normalized) as $key) {
            $versions[$key] = (int) Cache::get($key, 1);
        }

        return [
            'scope' => $normalized,
            'versions' => $versions,
        ];
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<int, string>
     */
    protected function accessVersionKeys(array $scope): array
    {
        $keys = [];

        if (!empty($scope['canonical_user_id'])) {
            $keys[] = 'ai_engine:graph:access:user:' . md5('canonical:' . $scope['canonical_user_id']);
        }

        if (!empty($scope['user_email_normalized'])) {
            $keys[] = 'ai_engine:graph:access:user:' . md5('email:' . strtolower($scope['user_email_normalized']));
        }

        if (!empty($scope['scope_type']) && !empty($scope['scope_id'])) {
            $keys[] = 'ai_engine:graph:access:scope:' . md5(strtolower($scope['scope_type']) . ':' . $scope['scope_id']);
        }

        if (!empty($scope['app_slug'])) {
            $keys[] = 'ai_engine:graph:access:app:' . md5(strtolower($scope['app_slug']));
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, mixed> $signals
     * @return array<string, mixed>
     */
    protected function normalizeSignals(array $signals): array
    {
        ksort($signals);

        return array_map(function ($value) {
            if (is_array($value)) {
                $items = array_values(array_unique(array_map(
                    static fn ($item): string => trim((string) $item),
                    $value
                )));
                sort($items);

                return $items;
            }

            return $value === null ? null : trim((string) $value);
        }, $signals);
    }

    /**
     * @param array<string, mixed> $scope
     */
    protected function scopeFingerprint(array $scope): string
    {
        return sha1(json_encode($this->normalizeScope($scope), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
    }

    protected function rememberProfileQuery(string $query): void
    {
        $queries = Cache::get($this->profileIndexKey(), []);
        if (!is_array($queries)) {
            $queries = [];
        }

        $queries = array_values(array_filter($queries, static fn ($existing): bool => is_string($existing) && $existing !== $query));
        array_unshift($queries, $query);
        $queries = array_slice($queries, 0, $this->profileIndexLimit());

        Cache::put($this->profileIndexKey(), $queries, now()->addSeconds($this->profileTtl()));
    }

    /**
     * @param object|array<string, mixed> $item
     * @return array<string, mixed>
     */
    protected function sanitizeCachedResult(object|array $item): array
    {
        $payload = json_decode(json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true) ?: [];
        $allowed = [
            'id',
            'entity_key',
            'model_type',
            'title',
            'name',
            'subject',
            'content',
            'matched_chunk_text',
            'matched_chunk_index',
            'vector_score',
            'source_node',
            'source_node_name',
            'entity_ref',
            'graph_object',
            'vector_metadata',
        ];

        $sanitized = array_intersect_key($payload, array_flip($allowed));
        $sanitized['vector_metadata'] = $this->sanitizeVectorMetadata((array) ($payload['vector_metadata'] ?? []));
        $sanitized['entity_ref'] = is_array($payload['entity_ref'] ?? null) ? $payload['entity_ref'] : ($sanitized['vector_metadata']['entity_ref'] ?? []);
        $sanitized['graph_object'] = is_array($payload['graph_object'] ?? null) ? $payload['graph_object'] : ($sanitized['vector_metadata']['object'] ?? []);

        return $sanitized;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function sanitizeVectorMetadata(array $metadata): array
    {
        $allowed = [
            'model_class',
            'model_id',
            'model_type',
            'entity_ref',
            'object',
            'chunk_text',
            'chunk_index',
            'chunk_preview',
            'lexical_score',
            'raw_vector_score',
            'app_slug',
            'source_node',
            'scope_type',
            'scope_id',
            'scope_label',
            'graph_planned',
            'planner_strategy',
            'planner_query_kind',
            'planner_score',
            'planner_score_breakdown',
            'planner_seed',
            'planner_filters',
            'path_length',
            'relation_path',
            'relation_expanded',
            'cypher_plan_signature',
            'cypher_plan_explanation',
            'neighbor_context',
            'related_chunk_text',
            'related_object',
            'graph_kb_cache_hit',
        ];

        return array_intersect_key($metadata, array_flip($allowed));
    }

    protected function planTtl(): int
    {
        return max(30, (int) config('ai-engine.graph.knowledge_base.plan_cache_ttl', 1800));
    }

    protected function plannerSignature(): string
    {
        return (string) config('ai-engine.graph.knowledge_base.planner_signature', 'v3');
    }

    protected function resultTtl(): int
    {
        return max(30, (int) config('ai-engine.graph.knowledge_base.result_cache_ttl', 900));
    }

    protected function profileTtl(): int
    {
        return max(300, (int) config('ai-engine.graph.knowledge_base.profile_ttl', 86400));
    }

    protected function profileIndexLimit(): int
    {
        return max(10, (int) config('ai-engine.graph.knowledge_base.profile_index_limit', 200));
    }
}
