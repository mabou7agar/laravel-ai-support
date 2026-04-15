<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Services\Graph\GraphKnowledgeBaseService;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphKnowledgeBaseServiceTest extends UnitTestCase
{
    public function test_it_caches_and_restores_graph_results_with_versioning(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        config()->set('ai-engine.graph.knowledge_base.cache_results', true);

        $service = new GraphKnowledgeBaseService();
        $result = new \stdClass();
        $result->id = 7;
        $result->entity_key = 'projects:App\\Models\\Project:7';
        $result->vector_score = 0.88;
        $result->vector_metadata = ['model_class' => 'App\\Models\\Project'];

        $service->cacheResults(
            'what changed on friday',
            ['App\\Models\\Project'],
            ['canonical_user_id' => '1'],
            ['selected_entity_key' => null, 'last_entity_keys' => []],
            new Collection([$result])
        );

        $cached = $service->getCachedResults(
            'what changed on friday',
            ['App\\Models\\Project'],
            ['canonical_user_id' => '1'],
            ['selected_entity_key' => null, 'last_entity_keys' => []]
        );

        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertSame(7, $cached->first()->id);
        $this->assertTrue($cached->first()->vector_metadata['graph_kb_cache_hit'] ?? false);

        $service->bumpGraphVersion();

        $this->assertNull($service->getCachedResults(
            'what changed on friday',
            ['App\\Models\\Project'],
            ['canonical_user_id' => '1'],
            ['selected_entity_key' => null, 'last_entity_keys' => []]
        ));
    }

    public function test_it_records_query_profiles(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        Cache::flush();

        $service = new GraphKnowledgeBaseService();
        $service->recordQueryProfile('who owns Apollo?', [
            'strategy' => 'selected_entity_traversal',
            'query_kind' => 'ownership',
        ]);

        $profile = $service->getQueryProfile('who owns Apollo?');

        $this->assertSame(1, $profile['count'] ?? null);
        $this->assertSame('selected_entity_traversal', $profile['last_strategy'] ?? null);
        $this->assertSame('ownership', $profile['last_query_kind'] ?? null);
        $this->assertSame('who owns apollo?', $profile['query'] ?? null);
        $this->assertCount(1, $service->listQueryProfiles());
    }

    public function test_it_separates_plan_cache_by_scope_fingerprint(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        config()->set('ai-engine.graph.knowledge_base.planner_signature', 'v2');
        Cache::flush();

        $service = new GraphKnowledgeBaseService();
        $invocations = 0;

        $first = $service->rememberPlan('who owns Apollo?', ['App\\Models\\Project'], ['canonical_user_id' => '1'], [], function () use (&$invocations) {
            $invocations++;

            return ['strategy' => 'selected_entity_traversal', 'query_kind' => 'ownership', 'scope' => 'user-1'];
        });

        $second = $service->rememberPlan('who owns Apollo?', ['App\\Models\\Project'], ['canonical_user_id' => '1'], [], function () use (&$invocations) {
            $invocations++;

            return ['strategy' => 'selected_entity_traversal', 'query_kind' => 'ownership', 'scope' => 'user-1-second'];
        });

        $third = $service->rememberPlan('who owns Apollo?', ['App\\Models\\Project'], ['canonical_user_id' => '2'], [], function () use (&$invocations) {
            $invocations++;

            return ['strategy' => 'selected_entity_traversal', 'query_kind' => 'ownership', 'scope' => 'user-2'];
        });

        $this->assertSame(2, $invocations);
        $this->assertSame('user-1', $first['scope']);
        $this->assertSame('user-1', $second['scope']);
        $this->assertSame('user-2', $third['scope']);
    }

    public function test_it_invalidates_plan_cache_when_planner_signature_changes(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        config()->set('ai-engine.graph.knowledge_base.planner_signature', 'v2');
        Cache::flush();

        $service = new GraphKnowledgeBaseService();
        $invocations = 0;

        $service->rememberPlan('who owns Apollo?', ['App\\Models\\Project'], ['canonical_user_id' => '1'], [], function () use (&$invocations) {
            $invocations++;

            return ['strategy' => 'selected_entity_traversal', 'query_kind' => 'ownership', 'signature' => 'v2'];
        });

        config()->set('ai-engine.graph.knowledge_base.planner_signature', 'v3');

        $plan = $service->rememberPlan('who owns Apollo?', ['App\\Models\\Project'], ['canonical_user_id' => '1'], [], function () use (&$invocations) {
            $invocations++;

            return ['strategy' => 'selected_entity_traversal', 'query_kind' => 'ownership', 'signature' => 'v3'];
        });

        $this->assertSame(2, $invocations);
        $this->assertSame('v3', $plan['signature'] ?? null);
    }

    public function test_it_invalidates_scoped_results_when_access_version_changes_and_sanitizes_payload(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        config()->set('ai-engine.graph.knowledge_base.cache_results', true);
        Cache::flush();

        $service = new GraphKnowledgeBaseService();
        $result = new \stdClass();
        $result->id = 9;
        $result->entity_key = 'local:App\\Models\\Task:9';
        $result->vector_score = 0.93;
        $result->vector_metadata = [
            'model_class' => 'App\\Models\\Task',
            'entity_ref' => ['entity_key' => 'local:App\\Models\\Task:9'],
            'object' => ['title' => 'Ops task'],
            'debug_secret' => 'should-not-cache',
        ];
        $result->debug_private = 'nope';

        $scope = [
            'canonical_user_id' => '7',
            'scope_type' => 'project',
            'scope_id' => 'apollo',
            'app_slug' => 'pm',
        ];

        $service->cacheResults('show Apollo tasks', ['App\\Models\\Task'], $scope, [], new Collection([$result]));

        $cached = $service->getCachedResults('show Apollo tasks', ['App\\Models\\Task'], $scope, []);
        $this->assertInstanceOf(Collection::class, $cached);
        $this->assertSame(9, $cached->first()->id);
        $this->assertArrayNotHasKey('debug_secret', $cached->first()->vector_metadata);
        $this->assertFalse(property_exists($cached->first(), 'debug_private'));

        $service->bumpAccessVersion($scope);

        $this->assertNull($service->getCachedResults('show Apollo tasks', ['App\\Models\\Task'], $scope, []));
    }

    public function test_it_caches_entity_snapshots_per_scope(): void
    {
        config()->set('ai-engine.graph.knowledge_base.enabled', true);
        Cache::flush();

        $service = new GraphKnowledgeBaseService();
        $scope = ['canonical_user_id' => '42', 'scope_type' => 'project', 'scope_id' => 'apollo'];

        $service->cacheEntitySnapshot('local:App\\Models\\Project:1', $scope, [
            'neighbors' => [
                ['entity_key' => 'local:App\\Models\\Task:2', 'relation_type' => 'HAS_TASK'],
            ],
        ]);

        $snapshot = $service->getEntitySnapshot('local:App\\Models\\Project:1', $scope);
        $this->assertIsArray($snapshot);
        $this->assertCount(1, $snapshot['neighbors'] ?? []);

        $this->assertNull($service->getEntitySnapshot('local:App\\Models\\Project:1', ['canonical_user_id' => '7']));
    }
}
