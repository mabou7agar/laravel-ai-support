<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphCypherPlanCompiler;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphCypherPlanCompilerTest extends UnitTestCase
{
    public function test_it_compiles_timeline_filters_and_focus_terms(): void
    {
        $compiled = (new GraphCypherPlanCompiler())->compileTraversal([
            'query_kind' => 'timeline',
            'cypher_template' => 'timeline_neighborhood',
            'traversal_direction' => 'any',
            'relation_types' => ['IN_PROJECT', 'HAS_MAIL'],
            'preferred_model_types' => ['mail', 'project'],
            'lexical_focus_terms' => ['latest'],
        ], 'What changed around "Apollo" this week?', 2, 'true');

        $this->assertStringContainsString('updated_after_ts', $compiled['statement']);
        $this->assertStringContainsString('focus_terms', $compiled['statement']);
        $this->assertSame('last_7_days', $compiled['filters']['temporal_label'] ?? null);
        $this->assertContains('apollo', $compiled['filters']['focus_terms'] ?? []);
        $this->assertSame('desc', $compiled['filters']['temporal_sort'] ?? null);
        $this->assertNotSame('', $compiled['signature']);
    }

    public function test_it_compiles_ownership_chain_with_preferred_model_filter(): void
    {
        $compiled = (new GraphCypherPlanCompiler())->compileTraversal([
            'query_kind' => 'ownership',
            'cypher_template' => 'ownership_chain',
            'traversal_direction' => 'outbound',
            'relation_types' => ['OWNED_BY', 'MANAGED_BY'],
            'preferred_model_types' => ['user'],
            'lexical_focus_terms' => ['owner'],
        ], 'who owns apollo project?', 1, 'true');

        $this->assertStringContainsString('toLower(n.model_type) IN $preferred_model_types', $compiled['statement']);
        $this->assertStringContainsString('OWNED_BY', $compiled['explanation'] . json_encode($compiled['filters']));
        $this->assertSame('outbound', $compiled['filters']['direction'] ?? null);
        $this->assertSame('ownership_chain', $compiled['filters']['template'] ?? null);
    }

    public function test_it_requires_non_zero_path_when_nl_plan_requests_path_explanation(): void
    {
        $compiled = (new GraphCypherPlanCompiler())->compileTraversal([
            'query_kind' => 'relationship',
            'cypher_template' => 'relationship_neighborhood',
            'traversal_direction' => 'any',
            'relation_types' => ['RELATED_TO'],
            'preferred_model_types' => [],
            'lexical_focus_terms' => ['apollo'],
            'natural_language_plan' => [
                'requires_path_explanation' => true,
                'focus_terms' => ['apollo'],
            ],
        ], 'Explain the path through Apollo', 2, 'true');

        $this->assertStringContainsString('length(path) > 0', $compiled['statement']);
        $this->assertSame(['apollo'], $compiled['filters']['natural_language_plan']['focus_terms'] ?? []);
    }
}
