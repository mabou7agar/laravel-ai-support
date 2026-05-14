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
}
