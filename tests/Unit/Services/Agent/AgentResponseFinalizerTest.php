<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\ContextManager;
use Mockery;
use PHPUnit\Framework\TestCase;

class AgentResponseFinalizerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function test_finalize_clears_stale_selected_entity_context_when_new_entity_list_arrives(): void
    {
        $manager = Mockery::mock(ContextManager::class);
        $manager->shouldReceive('save')->once();

        $finalizer = new AgentResponseFinalizer($manager);
        $context = new UnifiedActionContext('session-1', null, metadata: [
            'selected_entity_context' => ['entity_id' => 42],
        ]);

        $response = AgentResponse::conversational(
            'Here are invoices',
            $context,
            ['entity_ids' => [1, 2], 'entity_type' => 'invoice']
        );

        $finalizer->finalize($context, $response);

        $this->assertArrayNotHasKey('selected_entity_context', $context->metadata);
        $this->assertSame([1, 2], $context->conversationHistory[0]['metadata']['entity_ids']);
    }

    public function test_persist_message_avoids_duplicate_assistant_message(): void
    {
        $manager = Mockery::mock(ContextManager::class);
        $manager->shouldReceive('save')->once();

        $finalizer = new AgentResponseFinalizer($manager);
        $context = new UnifiedActionContext('session-2', null, conversationHistory: [
            ['role' => 'assistant', 'content' => 'same'],
        ]);

        $finalizer->persistMessage($context, 'same');

        $this->assertCount(1, $context->conversationHistory);
    }

    public function test_finalize_adds_trace_metadata_to_response_and_message(): void
    {
        $manager = Mockery::mock(ContextManager::class);
        $manager->shouldReceive('save')->once();

        $finalizer = new AgentResponseFinalizer($manager);
        $context = new UnifiedActionContext('session-3', null, metadata: [
            'trace_id' => 'trace-1',
            'agent_run_id' => 'run-1',
            'agent_run_step_id' => 'step-1',
            'runtime' => 'laravel',
            'decision_source' => 'classifier',
        ]);

        $response = AgentResponse::success('Done.', context: $context);

        $finalized = $finalizer->finalize($context, $response);

        $this->assertSame('trace-1', $finalized->metadata['trace_id']);
        $this->assertSame('run-1', $finalized->metadata['agent_run_id']);
        $this->assertSame('step-1', $finalized->metadata['agent_run_step_id']);
        $this->assertSame('trace-1', $context->conversationHistory[0]['metadata']['trace_id']);
    }

    public function test_finalize_persists_active_skill_flow(): void
    {
        $manager = Mockery::mock(ContextManager::class);
        $manager->shouldReceive('save')->once();

        $finalizer = new AgentResponseFinalizer($manager);
        $context = new UnifiedActionContext('session-4');
        $response = AgentResponse::needsUserInput(
            message: 'What email should I use?',
            data: [
                'skill_id' => 'create_invoice',
                'skill_name' => 'Create Invoice',
                'status' => 'collecting',
                'payload' => ['customer_name' => 'Acme'],
            ],
            context: $context
        );
        $response->strategy = 'skill_tool';
        $response->metadata = [
            'flow_data' => [
                'success' => false,
                'message' => 'What email should I use?',
                'data' => $response->data,
                'metadata' => ['agent_strategy' => 'skill_tool', 'needs_user_input' => true],
            ],
        ];

        $finalizer->finalize($context, $response);

        $this->assertSame('create_invoice', $context->metadata['last_skill_flow']['skill_id']);
        $this->assertSame('collecting', $context->metadata['last_skill_flow']['status']);
        $this->assertSame('Acme', $context->metadata['last_skill_flow']['payload']['customer_name']);
        $this->assertSame('create_invoice', $context->conversationHistory[0]['metadata']['skill_flow']['skill_id']);
    }

    public function test_finalize_clears_completed_skill_flow(): void
    {
        $manager = Mockery::mock(ContextManager::class);
        $manager->shouldReceive('save')->once();

        $finalizer = new AgentResponseFinalizer($manager);
        $context = new UnifiedActionContext('session-5', metadata: [
            'last_skill_flow' => ['skill_id' => 'create_invoice', 'status' => 'collecting'],
        ]);
        $response = AgentResponse::success('Done.', data: [
            'skill_id' => 'create_invoice',
            'status' => 'completed',
        ], context: $context);
        $response->strategy = 'skill_tool';
        $response->metadata = [
            'flow_data' => [
                'success' => true,
                'data' => $response->data,
                'metadata' => ['agent_strategy' => 'skill_tool'],
            ],
        ];

        $finalizer->finalize($context, $response);

        $this->assertArrayNotHasKey('last_skill_flow', $context->metadata);
    }
}
