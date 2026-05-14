<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Tests\UnitTestCase;

class MessageRoutingClassifierTest extends UnitTestCase
{
    public function test_classifies_greeting_as_conversational(): void
    {
        $classifier = new MessageRoutingClassifier();

        $decision = $classifier->classify('hello');

        $this->assertSame('conversational', $decision['route']);
        $this->assertSame('conversational', $decision['mode']);
    }

    public function test_classifies_semantic_question_as_search_rag(): void
    {
        $classifier = new MessageRoutingClassifier();

        $decision = $classifier->classify('What changed on Friday for Apollo and who is it related to?', [
            'rag_collections' => ['App\\Models\\Project', 'App\\Models\\Mail'],
        ]);

        $this->assertSame('search_rag', $decision['route']);
        $this->assertSame('semantic_retrieval', $decision['mode']);
    }

    public function test_classifies_explicit_list_request_as_structured_query(): void
    {
        $classifier = new MessageRoutingClassifier();

        $decision = $classifier->classify('list all open tasks');

        $this->assertSame('ask_ai', $decision['route']);
        $this->assertSame('structured_query', $decision['mode']);
    }

    public function test_classifies_exact_identifier_lookup_for_router_tool_selection(): void
    {
        config()->set('ai-agent.routing_classifier.action_entities', ['invoice']);

        $classifier = new MessageRoutingClassifier();

        $decision = $classifier->classify('please check invoice number AI-E2E-SINV-001');

        $this->assertSame('ask_ai', $decision['route']);
        $this->assertSame('exact_lookup', $decision['mode']);
    }

    public function test_exact_lookup_entity_terms_are_configurable(): void
    {
        config()->set('ai-agent.routing_classifier.action_entities', ['shipment']);

        $classifier = new MessageRoutingClassifier();

        $shipment = $classifier->classify('please check shipment number SHIP-001');
        $invoice = $classifier->classify('please check invoice number INV-001');

        $this->assertSame('exact_lookup', $shipment['mode']);
        $this->assertNotSame('exact_lookup', $invoice['mode']);
    }

    public function test_classifies_selected_entity_follow_up_as_contextual(): void
    {
        $classifier = new MessageRoutingClassifier();

        $decision = $classifier->classify('tell me more about it', [
            'selected_entity' => [
                'entity_id' => 42,
                'entity_type' => 'invoice',
            ],
        ]);

        $this->assertSame('search_rag', $decision['route']);
        $this->assertSame('contextual_follow_up', $decision['mode']);
    }
}
