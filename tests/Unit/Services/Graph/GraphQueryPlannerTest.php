<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphQueryPlanner;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphQueryPlannerTest extends UnitTestCase
{
    public function test_it_uses_selected_entity_traversal_for_relationship_follow_up(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);

        $plan = (new GraphQueryPlanner())->plan(
            'who is it related to?',
            ['App\\Models\\Project', 'App\\Models\\Task'],
            [
                'selected_entity_context' => [
                    'entity_ref' => [
                        'entity_key' => 'projects:App\\Models\\Project:7',
                    ],
                ],
                'last_entity_list' => [
                    'entity_refs' => [[
                        'entity_key' => 'projects:App\\Models\\Project:7',
                    ]],
                ],
            ],
            5
        );

        $this->assertSame('selected_entity_traversal', $plan['strategy']);
        $this->assertTrue($plan['relationship_query']);
        $this->assertTrue($plan['contextual_follow_up']);
        $this->assertTrue($plan['use_selected_entity_seed']);
        $this->assertTrue($plan['use_visible_list_seeds']);
        $this->assertTrue($plan['use_semantic_seeds']);
        $this->assertTrue($plan['traversal_enabled']);
        $this->assertGreaterThanOrEqual(5, $plan['seed_limit']);
        $this->assertGreaterThan(0.4, $plan['lexical_weight']);
        $this->assertSame('relationship_neighborhood', $plan['cypher_template']);
        $this->assertSame('any', $plan['traversal_direction']);
    }

    public function test_it_keeps_simple_semantic_queries_on_semantic_only_strategy(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);

        $plan = (new GraphQueryPlanner())->plan(
            'what is the latest launch status?',
            ['App\\Models\\Mail'],
            [],
            5
        );

        $this->assertSame('semantic_only', $plan['strategy']);
        $this->assertFalse($plan['relationship_query']);
        $this->assertFalse($plan['contextual_follow_up']);
        $this->assertTrue($plan['use_semantic_seeds']);
        $this->assertFalse($plan['traversal_enabled']);
        $this->assertSame('timeline_neighborhood', $plan['cypher_template']);
    }

    public function test_it_detects_ownership_and_dependency_query_kinds(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);

        $ownership = (new GraphQueryPlanner())->plan(
            'who owns Apollo project?',
            ['App\\Models\\Project', 'App\\Models\\User'],
            [
                'selected_entity_context' => [
                    'entity_ref' => ['entity_key' => 'projects:App\\Models\\Project:7'],
                ],
            ],
            5
        );

        $dependency = (new GraphQueryPlanner())->plan(
            'what dependencies are linked to Apollo?',
            ['App\\Models\\Project', 'App\\Models\\Task'],
            [],
            5
        );

        $this->assertSame('ownership', $ownership['query_kind']);
        $this->assertContains('OWNED_BY', $ownership['relation_types']);
        $this->assertContains('user', $ownership['preferred_model_types']);
        $this->assertSame('ownership_chain', $ownership['cypher_template']);
        $this->assertSame('outbound', $ownership['traversal_direction']);
        $this->assertSame('dependency', $dependency['query_kind']);
        $this->assertContains('DEPENDS_ON', $dependency['relation_types']);
        $this->assertContains('task', $dependency['preferred_model_types']);
        $this->assertSame('dependency_chain', $dependency['cypher_template']);
        $this->assertSame('outbound', $dependency['traversal_direction']);
    }

    public function test_it_detects_communication_queries_and_uses_relationship_neighborhood(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);

        $plan = (new GraphQueryPlanner())->plan(
            'who replied to the launch email thread?',
            ['App\\Models\\Mail', 'App\\Models\\User'],
            [],
            5
        );

        $this->assertSame('communication', $plan['query_kind']);
        $this->assertContains('REPLIED_TO', $plan['relation_types']);
        $this->assertContains('HAS_ATTACHMENT', $plan['relation_types']);
        $this->assertSame('relationship_neighborhood', $plan['cypher_template']);
        $this->assertSame('any', $plan['traversal_direction']);
        $this->assertContains('mail', $plan['preferred_model_types']);
    }

    public function test_it_merges_collection_ontology_aliases_and_relation_hints(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);

        $plan = (new GraphQueryPlanner())->plan(
            'show apollo context',
            ['App\\Models\\Project', 'App\\Models\\Mail'],
            [],
            5
        );

        $this->assertContains('initiative', $plan['preferred_model_types']);
        $this->assertContains('thread', $plan['preferred_model_types']);
        $this->assertContains('HAS_PROJECT', $plan['relation_types']);
        $this->assertContains('SENT_TO', $plan['relation_types']);
    }

    public function test_it_uses_natural_language_plan_hints_and_ranking_feedback(): void
    {
        config()->set('ai-engine.graph.planner_enabled', true);
        config()->set('ai-engine.graph.ranking_feedback.enabled', true);
        config()->set('ai-engine.graph.ranking_feedback.min_samples', 1);

        $feedback = app(\LaravelAIEngine\Services\Graph\GraphRankingFeedbackService::class);
        $feedback->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);
        $feedback->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);
        $feedback->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);

        $plan = new GraphQueryPlanner(
            app(\LaravelAIEngine\Services\Graph\GraphOntologyService::class),
            app(\LaravelAIEngine\Services\Graph\GraphNaturalLanguagePlanService::class),
            $feedback
        );

        $plan = $plan->plan(
            'Explain how "Apollo" thread context is related to attachments',
            ['App\\Models\\Mail', 'App\\Models\\Project'],
            [],
            5
        );

        $this->assertSame('relationship', $plan['query_kind']);
        $this->assertSame('relationship_neighborhood', $plan['cypher_template']);
        $this->assertContains('apollo', $plan['lexical_focus_terms']);
        $this->assertContains('HAS_ATTACHMENT', $plan['relation_types']);
        $this->assertGreaterThan(0.4, $plan['lexical_weight']);
        $this->assertArrayHasKey('ranking_feedback', $plan);
        $this->assertArrayHasKey('natural_language_plan', $plan);
        $this->assertTrue((bool) ($plan['natural_language_plan']['requires_path_explanation'] ?? false));
    }
}
