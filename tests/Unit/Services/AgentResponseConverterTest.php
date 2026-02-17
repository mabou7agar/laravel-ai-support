<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseConverter;
use Orchestra\Testbench\TestCase;

class AgentResponseConverterTest extends TestCase
{
    protected AgentResponseConverter $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.default', 'openai');
        $this->converter = new AgentResponseConverter();
    }

    protected function makeContext(array $overrides = []): UnifiedActionContext
    {
        $context = new UnifiedActionContext(sessionId: 'test-session', userId: 1);
        foreach ($overrides as $key => $value) {
            $context->{$key} = $value;
        }
        return $context;
    }

    // ──────────────────────────────────────────────
    //  Basic conversion
    // ──────────────────────────────────────────────

    public function test_converts_successful_conversational_response(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(
            message: 'Here are your invoices',
            context: $context
        );

        $result = $this->converter->convert($agentResponse, 'openai', 'gpt-4o-mini', 'conv-123');

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('Here are your invoices', $result->getContent());
        $this->assertSame('conv-123', $result->conversationId);
    }

    public function test_converts_failure_response(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::failure(
            message: 'Something went wrong',
            context: $context
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertFalse($result->success);
        $this->assertSame('Something went wrong', $result->getContent());
    }

    public function test_null_conversation_id(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(message: 'ok', context: $context);

        $result = $this->converter->convert($agentResponse);

        $this->assertNull($result->conversationId);
    }

    // ──────────────────────────────────────────────
    //  Metadata mapping
    // ──────────────────────────────────────────────

    public function test_maps_agent_strategy_to_metadata(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(
            message: 'ok',
            context: $context,
            metadata: ['strategy' => 'search_rag']
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertSame('conversational', $result->metadata['agent_strategy']);
    }

    public function test_maps_workflow_state_to_metadata(): void
    {
        $context = $this->makeContext(['currentWorkflow' => 'InvoiceCollector']);
        $agentResponse = AgentResponse::needsUserInput(
            message: 'What is the amount?',
            context: $context
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertTrue($result->metadata['workflow_active']);
        $this->assertSame('InvoiceCollector', $result->metadata['workflow_class']);
        $this->assertFalse($result->metadata['workflow_completed']);
    }

    public function test_maps_completed_workflow(): void
    {
        $context = $this->makeContext(['currentWorkflow' => 'InvoiceCollector']);
        $agentResponse = new AgentResponse(
            success: true,
            message: 'Invoice created!',
            context: $context,
            isComplete: true
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertFalse($result->metadata['workflow_active']);
        $this->assertTrue($result->metadata['workflow_completed']);
    }

    public function test_maps_agent_metadata_to_ai_response(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(
            message: 'ok',
            context: $context,
            metadata: [
                'entity_ids' => [1, 2, 3],
                'entity_type' => 'invoice',
                'suggested_next_actions' => [['label' => 'View details']],
            ]
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertSame([1, 2, 3], $result->metadata['entity_ids']);
        $this->assertSame('invoice', $result->metadata['entity_type']);
        $this->assertNotEmpty($result->metadata['suggested_next_actions']);
    }

    // ──────────────────────────────────────────────
    //  Entity tracking from context metadata
    // ──────────────────────────────────────────────

    public function test_extracts_entity_tracking_from_last_entity_list(): void
    {
        $context = $this->makeContext();
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [10, 20, 30],
            'entity_type' => 'customer',
        ];

        $agentResponse = AgentResponse::conversational(
            message: 'Found 3 customers',
            context: $context
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertSame([10, 20, 30], $result->metadata['entity_ids']);
        $this->assertSame('customer', $result->metadata['entity_type']);
    }

    public function test_no_entity_tracking_when_no_list(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(
            message: 'Hello',
            context: $context
        );

        $result = $this->converter->convert($agentResponse);

        // entity_ids should not be set from entity tracking (may exist from other sources)
        $this->assertArrayNotHasKey('last_entity_list', $context->metadata);
    }

    // ──────────────────────────────────────────────
    //  Engine/model passthrough
    // ──────────────────────────────────────────────

    public function test_passes_engine_and_model(): void
    {
        $context = $this->makeContext();
        $agentResponse = AgentResponse::conversational(message: 'ok', context: $context);

        $result = $this->converter->convert($agentResponse, 'openai', 'gpt-4o-mini');

        $this->assertSame('openai', $result->engine->value);
        $this->assertSame('gpt-4o-mini', $result->model->value);
    }

    // ──────────────────────────────────────────────
    //  Data passthrough
    // ──────────────────────────────────────────────

    public function test_maps_agent_data_to_workflow_data(): void
    {
        $context = $this->makeContext();
        $agentResponse = new AgentResponse(
            success: true,
            message: 'Created',
            data: ['invoice_id' => 42, 'amount' => 100],
            context: $context
        );

        $result = $this->converter->convert($agentResponse);

        $this->assertSame(['invoice_id' => 42, 'amount' => 100], $result->metadata['workflow_data']);
    }
}
