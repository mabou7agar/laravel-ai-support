<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\RoutingTrace;
use LaravelAIEngine\Tests\UnitTestCase;

class RoutingDecisionTest extends UnitTestCase
{
    public function test_routing_decision_serializes_required_fields(): void
    {
        $decision = new RoutingDecision(
            action: RoutingDecisionAction::SEARCH_RAG,
            source: RoutingDecisionSource::CLASSIFIER,
            confidence: 'high',
            reason: 'Semantic retrieval request',
            payload: ['collections' => ['posts']],
            metadata: ['stage' => 'message_classifier']
        );

        $this->assertSame([
            'action' => RoutingDecisionAction::SEARCH_RAG,
            'source' => RoutingDecisionSource::CLASSIFIER,
            'confidence' => 'high',
            'reason' => 'Semantic retrieval request',
            'payload' => ['collections' => ['posts']],
            'metadata' => ['stage' => 'message_classifier'],
        ], $decision->toArray());
    }

    public function test_routing_action_and_source_vocabularies_are_available(): void
    {
        $this->assertSame([
            RoutingDecisionAction::CONTINUE_RUN,
            RoutingDecisionAction::CONTINUE_COLLECTOR,
            RoutingDecisionAction::CONTINUE_NODE,
            RoutingDecisionAction::HANDLE_SELECTION,
            RoutingDecisionAction::SEARCH_RAG,
            RoutingDecisionAction::USE_TOOL,
            RoutingDecisionAction::RUN_SUB_AGENT,
            RoutingDecisionAction::START_COLLECTOR,
            RoutingDecisionAction::ROUTE_TO_NODE,
            RoutingDecisionAction::PAUSE_AND_HANDLE,
            RoutingDecisionAction::CONVERSATIONAL,
            RoutingDecisionAction::NEED_USER_INPUT,
            RoutingDecisionAction::FAIL,
            RoutingDecisionAction::ABSTAIN,
        ], RoutingDecisionAction::all());

        $this->assertContains(RoutingDecisionSource::AI_ROUTER, RoutingDecisionSource::all());
        $this->assertContains(RoutingDecisionSource::CLASSIFIER, RoutingDecisionSource::all());
    }

    public function test_routing_trace_records_candidates_and_selected_decision(): void
    {
        $candidate = RoutingDecision::abstained('explicit_mode', 'No explicit mode requested');
        $selected = new RoutingDecision(
            action: 'conversational',
            source: 'fallback',
            confidence: 'high',
            reason: 'No other route matched'
        );

        $trace = (new RoutingTrace())
            ->record($candidate)
            ->record($selected)
            ->select($selected);

        $serialized = $trace->toArray();

        $this->assertCount(2, $serialized['decisions']);
        $this->assertSame('abstain', $serialized['decisions'][0]['action']);
        $this->assertSame('conversational', $serialized['selected']['action']);
    }
}
