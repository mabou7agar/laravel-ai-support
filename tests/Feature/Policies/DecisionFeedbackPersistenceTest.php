<?php

namespace LaravelAIEngine\Tests\Feature\Policies;

use LaravelAIEngine\Models\AIPromptFeedbackEvent;
use LaravelAIEngine\Services\RAG\RAGDecisionFeedbackService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Tests\TestCase;

class DecisionFeedbackPersistenceTest extends TestCase
{
    public function test_it_persists_feedback_events_and_reports_from_database(): void
    {
        config()->set('ai-engine.rag.decision.adaptive_feedback.enabled', true);
        config()->set('ai-engine.rag.decision.adaptive_feedback.persistence.enabled', true);

        $policy = new RAGDecisionPolicy();
        $service = new RAGDecisionFeedbackService($policy);

        $runtime = [
            'session_id' => 'session-a',
            'user_id' => '1',
            'policy' => [
                'id' => 100,
                'policy_key' => 'decision',
                'version' => 1,
                'status' => 'active',
            ],
            'latency_ms' => 32,
            'tokens_used' => 70,
            'token_cost' => 0.0025,
        ];

        $service->recordParseFailure('show invoice status', 'invalid json', $runtime);
        $service->recordParsedDecision(
            [
                'tool' => 'db_query',
                'reasoning' => 'Need exact record',
                'parameters' => ['model' => 'invoice', 'filters' => ['id' => 7]],
            ],
            'show invoice #7 status',
            ['selected_entity' => ['entity_id' => 7, 'entity_type' => 'invoice']],
            $runtime
        );

        $this->assertGreaterThanOrEqual(2, AIPromptFeedbackEvent::query()->count());

        $report = $service->report([]);
        $this->assertGreaterThanOrEqual(1, $report['parse_failures']);
        $this->assertGreaterThanOrEqual(1, $report['total_decisions']);
        $this->assertArrayHasKey('db_query', $report['tool_counts']);
    }

    public function test_it_records_execution_outcome_event(): void
    {
        config()->set('ai-engine.rag.decision.adaptive_feedback.enabled', true);
        config()->set('ai-engine.rag.decision.adaptive_feedback.persistence.enabled', true);

        $policy = new RAGDecisionPolicy();
        $service = new RAGDecisionFeedbackService($policy);

        $service->recordExecutionOutcome(
            [
                'tool' => 'db_query',
                'reasoning' => 'fetch data',
                'parameters' => ['model' => 'invoice'],
                'decision_source' => 'ai',
            ],
            [
                'success' => true,
                'response' => 'ok',
            ],
            [
                'session_id' => 'session-b',
                'user_id' => '12',
            ]
        );

        $event = AIPromptFeedbackEvent::query()->where('event_type', 'execution_outcome')->first();

        $this->assertNotNull($event);
        $this->assertTrue((bool) $event->success);
        $this->assertSame('success', $event->outcome);
        $this->assertSame('db_query', $event->decision_tool);
    }
}
