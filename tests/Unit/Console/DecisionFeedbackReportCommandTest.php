<?php

namespace LaravelAIEngine\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Tests\UnitTestCase;

class DecisionFeedbackReportCommandTest extends UnitTestCase
{
    public function test_json_report_includes_metrics_and_adaptive_hints(): void
    {
        config()->set('ai-engine.rag.decision.business_context', [
            'domain' => 'ecommerce',
            'known_issues' => ['follow-up relisting'],
        ]);

        /** @var RAGDecisionFeedbackService $feedback */
        $feedback = $this->app->make(RAGDecisionFeedbackService::class);
        $feedback->recordParseFailure('what is its status?', 'not-json');
        $feedback->recordParsedDecision(
            ['tool' => 'db_query', 'parameters' => ['model' => 'invoice']],
            'show details',
            ['selected_entity' => ['entity_id' => 9, 'entity_type' => 'invoice']]
        );

        Artisan::call('ai:decision-feedback:report', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['reset']);
        $this->assertGreaterThanOrEqual(1, $payload['report']['parse_failures']);
        $this->assertGreaterThanOrEqual(1, $payload['report']['fallback_count']);
        $this->assertGreaterThanOrEqual(1, $payload['report']['total_decisions']);
        $this->assertNotEmpty($payload['report']['adaptive_hints']);
    }

    public function test_reset_option_clears_feedback_state(): void
    {
        /** @var RAGDecisionFeedbackService $feedback */
        $feedback = $this->app->make(RAGDecisionFeedbackService::class);
        $feedback->recordParseFailure('next', 'invalid');

        Artisan::call('ai:decision-feedback:report', ['--json' => true, '--reset' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['reset']);
        $this->assertGreaterThanOrEqual(1, $payload['report']['parse_failures']);

        $snapshot = $feedback->snapshot();
        $this->assertSame(0, $snapshot['total_decisions']);
        $this->assertSame(0, $snapshot['parse_failures']);
        $this->assertSame(0, $snapshot['fallback_count']);
    }
}
