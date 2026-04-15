<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphRankingFeedbackService;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphRankingFeedbackServiceTest extends UnitTestCase
{
    public function test_it_records_outcomes_and_adapts_plan_weights(): void
    {
        config()->set('ai-engine.graph.ranking_feedback.enabled', true);
        config()->set('ai-engine.graph.ranking_feedback.min_samples', 3);

        $service = new GraphRankingFeedbackService();

        $service->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
            'selected_seed_helpful' => true,
        ]);
        $service->recordOutcome('relationship', [
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);
        $service->recordOutcome('relationship', [
            'vector_dominant' => false,
            'lexical_dominant' => true,
            'relation_helpful' => true,
        ]);

        $plan = $service->adaptPlan('relationship', [
            'vector_weight' => 0.6,
            'lexical_weight' => 0.4,
            'relationship_bonus' => 0.05,
            'selected_seed_boost' => 0.05,
        ]);

        $this->assertGreaterThan(0.4, $plan['lexical_weight']);
        $this->assertLessThan(0.6, $plan['vector_weight']);
        $this->assertGreaterThan(0.05, $plan['relationship_bonus']);
        $this->assertArrayHasKey('ranking_feedback', $plan);
        $this->assertSame(3, $plan['ranking_feedback']['samples']);
    }
}
