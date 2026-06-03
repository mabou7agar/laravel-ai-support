<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Tests\TestCase;

class AgentRunContextRestoreTest extends TestCase
{
    public function test_cached_agent_context_is_isolated_by_user_id(): void
    {
        $first = new UnifiedActionContext('shared-session', 'user-a', metadata: [
            'ai_native' => ['pending_tool' => ['name' => 'create_customer']],
        ]);
        $second = new UnifiedActionContext('shared-session', 'user-b', metadata: [
            'ai_native' => ['pending_tool' => ['name' => 'send_email']],
        ]);

        $first->persist();
        $second->persist();

        $this->assertSame('create_customer', UnifiedActionContext::load('shared-session', 'user-a')?->metadata['ai_native']['pending_tool']['name']);
        $this->assertSame('send_email', UnifiedActionContext::load('shared-session', 'user-b')?->metadata['ai_native']['pending_tool']['name']);
        $this->assertNull(UnifiedActionContext::load('shared-session', 'user-c'));
    }

    public function test_context_manager_restores_active_ai_native_state_from_prior_agent_run_when_cache_is_empty(): void
    {
        AIAgentRun::query()->create([
            'uuid' => 'run-waiting-ai-native',
            'session_id' => 'ai-native-restore',
            'user_id' => 'lab-user',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
            'input' => ['message' => 'Create invoice for Ahmed'],
            'final_response' => [
                'success' => true,
                'message' => 'Please review before I run create invoice.',
                'needs_user_input' => true,
                'metadata' => [
                    'ai_native' => [
                        'pending_tool' => [
                            'name' => 'create_invoice',
                            'params' => [
                                'customer_id' => 501,
                                'items' => [['product_id' => 10, 'quantity' => 2]],
                            ],
                        ],
                        'task_frame' => [
                            'active_objective' => 'create_invoice',
                            'status' => 'working',
                        ],
                    ],
                ],
            ],
            'waiting_at' => now(),
        ]);

        $context = app(ContextManager::class)->getOrCreate('ai-native-restore', 'lab-user');

        $this->assertSame('create_invoice', $context->metadata['ai_native']['pending_tool']['name']);
        $this->assertSame('run-waiting-ai-native', $context->metadata['restored_from_agent_run_id']);
    }

    public function test_context_manager_restores_recent_completed_ai_native_state_from_prior_agent_run_when_cache_is_empty(): void
    {
        AIAgentRun::query()->create([
            'uuid' => 'run-completed-ai-native',
            'session_id' => 'ai-native-completed-restore',
            'user_id' => 'lab-user',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'input' => ['message' => 'Create invoice for Ahmed'],
            'final_response' => [
                'success' => true,
                'message' => 'Invoice created.',
                'needs_user_input' => false,
                'metadata' => [
                    'ai_native' => [
                        'task_frame' => [
                            'active_objective' => 'create_invoice',
                            'status' => 'completed',
                            'completed_writes' => [
                                [
                                    'tool' => 'create_invoice',
                                    'label' => 'INV-9001',
                                    'outcome' => 'created',
                                ],
                            ],
                        ],
                        'recent_outcomes' => [
                            [
                                'tool' => 'create_invoice',
                                'outcome' => 'created',
                                'entity_type' => 'invoice',
                                'entity_id' => 9001,
                                'label' => 'INV-9001',
                            ],
                        ],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        $context = app(ContextManager::class)->getOrCreate('ai-native-completed-restore', 'lab-user');

        $this->assertSame('create_invoice', $context->metadata['ai_native']['task_frame']['active_objective']);
        $this->assertSame('completed', $context->metadata['ai_native']['task_frame']['status']);
        $this->assertSame('INV-9001', $context->metadata['ai_native']['recent_outcomes'][0]['label']);
        $this->assertSame('run-completed-ai-native', $context->metadata['restored_from_agent_run_id']);
    }

    public function test_context_manager_restores_history_from_in_flight_run_without_final_response(): void
    {
        AIAgentRun::query()->create([
            'uuid' => 'run-in-flight-no-final',
            'session_id' => 'in-flight-restore',
            'user_id' => 'lab-user',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_RUNNING,
            'input' => ['message' => 'Draft a quote for Sara'],
            'final_response' => null,
        ]);

        $context = app(ContextManager::class)->getOrCreate('in-flight-restore', 'lab-user');

        $this->assertNotEmpty($context->conversationHistory);
        $this->assertSame('user', $context->conversationHistory[0]['role']);
        $this->assertSame('Draft a quote for Sara', $context->conversationHistory[0]['content']);
    }

    public function test_guest_context_restore_does_not_load_authenticated_user_run_with_same_session(): void
    {
        AIAgentRun::query()->create([
            'uuid' => 'run-authenticated-ai-native',
            'session_id' => 'shared-durable-session',
            'user_id' => 'user-a',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_WAITING_INPUT,
            'input' => ['message' => 'Create invoice for Ahmed'],
            'final_response' => [
                'success' => true,
                'message' => 'Please review.',
                'needs_user_input' => true,
                'metadata' => [
                    'ai_native' => [
                        'pending_tool' => ['name' => 'create_invoice', 'params' => ['customer_id' => 501]],
                    ],
                ],
            ],
            'waiting_at' => now(),
        ]);

        $context = app(ContextManager::class)->getOrCreate('shared-durable-session', null);

        $this->assertArrayNotHasKey('ai_native', $context->metadata);
        $this->assertNull($context->metadata['restored_from_agent_run_id'] ?? null);
    }
}
