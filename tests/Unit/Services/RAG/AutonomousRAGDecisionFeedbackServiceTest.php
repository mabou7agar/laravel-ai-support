<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\AutonomousRAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\AutonomousRAGPolicy;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousRAGDecisionFeedbackServiceTest extends UnitTestCase
{
    public function test_feedback_adds_parse_failure_hint_and_business_issue_hints(): void
    {
        config()->set('ai-engine.intelligent_rag.decision.business_context', [
            'known_issues' => ['Do not re-list previous invoices on follow-up'],
        ]);

        $policy = new AutonomousRAGPolicy();
        $service = new AutonomousRAGDecisionFeedbackService($policy);
        $service->recordParseFailure('show details', 'not json response');

        $hints = $service->adaptiveHints($policy->decisionBusinessContext());

        $this->assertTrue(
            collect($hints)->contains(fn (string $hint) => str_contains($hint, 'Known issue to avoid'))
        );
        $this->assertTrue(
            collect($hints)->contains(fn (string $hint) => str_contains($hint, 'strict JSON object'))
        );
    }

    public function test_feedback_detects_relist_risk_on_follow_up_decisions(): void
    {
        $policy = new AutonomousRAGPolicy();
        $service = new AutonomousRAGDecisionFeedbackService($policy);

        $service->recordParsedDecision(
            [
                'tool' => 'db_query',
                'parameters' => ['model' => 'invoice'],
            ],
            'what is its status?',
            [
                'selected_entity' => ['entity_id' => 77, 'entity_type' => 'invoice'],
                'last_entity_list' => null,
            ]
        );

        $hints = $service->adaptiveHints([]);

        $this->assertTrue(
            collect($hints)->contains(fn (string $hint) => str_contains($hint, 'relisting risk'))
        );
    }
}
