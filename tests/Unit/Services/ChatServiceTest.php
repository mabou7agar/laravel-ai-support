<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Agent\MinimalAIOrchestrator;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use Mockery;
use Orchestra\Testbench\TestCase;

class ChatServiceTest extends TestCase
{
    protected $mockConversation;
    protected $mockOrchestrator;
    protected ChatService $chatService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('ai-engine.default', 'openai');
        $this->app['config']->set('ai-engine.orchestration_model', 'gpt-4o-mini');

        $this->mockConversation = Mockery::mock(ConversationService::class);
        $this->mockOrchestrator = Mockery::mock(MinimalAIOrchestrator::class);
        $this->chatService = new ChatService($this->mockConversation, $this->mockOrchestrator);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function makeContext(): UnifiedActionContext
    {
        return new UnifiedActionContext(sessionId: 'test-session', userId: 1);
    }

    // ──────────────────────────────────────────────
    //  Basic flow: processMessage → orchestrator → AIResponse
    // ──────────────────────────────────────────────

    public function test_process_message_delegates_to_orchestrator(): void
    {
        $context = $this->makeContext();

        $this->mockConversation->shouldReceive('getOrCreateConversation')
            ->once()
            ->with('test-session', 1, 'openai', 'gpt-4o-mini')
            ->andReturn('conv-123');

        $this->mockConversation->shouldReceive('getConversationHistory')
            ->once()
            ->andReturn([]);

        $agentResponse = AgentResponse::conversational(
            message: 'Hello! How can I help you?',
            context: $context,
            metadata: ['strategy' => 'conversational']
        );

        $this->mockOrchestrator->shouldReceive('process')
            ->once()
            ->with('hello', 'test-session', 1, Mockery::type('array'))
            ->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'hello',
            sessionId: 'test-session',
            userId: 1
        );

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame('Hello! How can I help you?', $result->getContent());
        $this->assertSame('conv-123', $result->conversationId);
    }

    public function test_process_message_passes_options_to_orchestrator(): void
    {
        $context = $this->makeContext();

        $this->mockConversation->shouldReceive('getOrCreateConversation')
            ->andReturn('conv-123');
        $this->mockConversation->shouldReceive('getConversationHistory')
            ->andReturn([['role' => 'user', 'content' => 'previous']]);

        $agentResponse = AgentResponse::conversational(
            message: 'Search results...',
            context: $context
        );

        $this->mockOrchestrator->shouldReceive('process')
            ->once()
            ->withArgs(function ($msg, $sid, $uid, $opts) {
                return $msg === 'list invoices'
                    && $sid === 'test-session'
                    && $uid === 1
                    && $opts['use_memory'] === true
                    && $opts['use_actions'] === true
                    && $opts['use_intelligent_rag'] === true
                    && $opts['rag_collections'] === ['invoices']
                    && $opts['search_instructions'] === 'focus on overdue'
                    && count($opts['conversation_history']) === 1;
            })
            ->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'list invoices',
            sessionId: 'test-session',
            ragCollections: ['invoices'],
            searchInstructions: 'focus on overdue',
            userId: 1
        );

        $this->assertTrue($result->success);
    }

    // ──────────────────────────────────────────────
    //  Memory disabled: skips conversation loading
    // ──────────────────────────────────────────────

    public function test_skips_conversation_when_memory_disabled(): void
    {
        $context = $this->makeContext();

        // Should NOT call conversation service at all
        $this->mockConversation->shouldNotReceive('getOrCreateConversation');
        $this->mockConversation->shouldNotReceive('getConversationHistory');

        $agentResponse = AgentResponse::conversational(
            message: 'Response without memory',
            context: $context
        );

        $this->mockOrchestrator->shouldReceive('process')
            ->once()
            ->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'hello',
            sessionId: 'test-session',
            useMemory: false,
            userId: 1
        );

        $this->assertTrue($result->success);
        $this->assertNull($result->conversationId);
    }

    // ──────────────────────────────────────────────
    //  Response conversion: AgentResponse → AIResponse
    // ──────────────────────────────────────────────

    public function test_converts_agent_response_metadata_to_ai_response(): void
    {
        $context = $this->makeContext();
        $context->currentWorkflow = 'InvoiceCollector';

        $agentResponse = AgentResponse::conversational(
            message: 'Found 3 invoices',
            context: $context,
            metadata: [
                'entity_ids' => [1, 2, 3],
                'entity_type' => 'invoice',
                'strategy' => 'search_rag',
            ]
        );

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        $this->mockConversation->shouldReceive('getConversationHistory')->andReturn([]);
        $this->mockOrchestrator->shouldReceive('process')->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'list invoices',
            sessionId: 'test-session',
            userId: 1
        );

        $metadata = $result->metadata;
        $this->assertSame([1, 2, 3], $metadata['entity_ids']);
        $this->assertSame('invoice', $metadata['entity_type']);
        $this->assertSame('conversational', $metadata['agent_strategy']);
        $this->assertSame('InvoiceCollector', $metadata['workflow_class']);
    }

    public function test_converts_failure_response(): void
    {
        $context = $this->makeContext();

        $agentResponse = AgentResponse::failure(
            message: 'Something went wrong',
            context: $context
        );

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        $this->mockConversation->shouldReceive('getConversationHistory')->andReturn([]);
        $this->mockOrchestrator->shouldReceive('process')->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'do something',
            sessionId: 'test-session',
            userId: 1
        );

        $this->assertFalse($result->success);
        $this->assertSame('Something went wrong', $result->getContent());
    }

    // ──────────────────────────────────────────────
    //  Entity tracking from context metadata
    // ──────────────────────────────────────────────

    public function test_extracts_entity_tracking_from_context_metadata(): void
    {
        $context = $this->makeContext();
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [10, 20, 30],
            'entity_type' => 'customer',
        ];

        $agentResponse = AgentResponse::conversational(
            message: 'Here are 3 customers',
            context: $context
        );

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        $this->mockConversation->shouldReceive('getConversationHistory')->andReturn([]);
        $this->mockOrchestrator->shouldReceive('process')->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'list customers',
            sessionId: 'test-session',
            userId: 1
        );

        $this->assertSame([10, 20, 30], $result->metadata['entity_ids']);
        $this->assertSame('customer', $result->metadata['entity_type']);
    }

    // ──────────────────────────────────────────────
    //  Workflow state tracking
    // ──────────────────────────────────────────────

    public function test_workflow_active_flag_from_agent_response(): void
    {
        $context = $this->makeContext();
        $context->currentWorkflow = 'InvoiceCollector';

        // isComplete = false means workflow is active
        $agentResponse = AgentResponse::needsUserInput(
            message: 'What is the invoice amount?',
            context: $context
        );

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        $this->mockConversation->shouldReceive('getConversationHistory')->andReturn([]);
        $this->mockOrchestrator->shouldReceive('process')->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'create invoice',
            sessionId: 'test-session',
            userId: 1
        );

        $this->assertTrue($result->metadata['workflow_active']);
        $this->assertSame('InvoiceCollector', $result->metadata['workflow_class']);
    }

    // ──────────────────────────────────────────────
    //  Forwarded request detection
    // ──────────────────────────────────────────────

    public function test_passes_is_forwarded_flag_to_orchestrator(): void
    {
        $context = $this->makeContext();

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        $this->mockConversation->shouldReceive('getConversationHistory')->andReturn([]);

        $agentResponse = AgentResponse::conversational(message: 'ok', context: $context);

        $this->mockOrchestrator->shouldReceive('process')
            ->once()
            ->withArgs(function ($msg, $sid, $uid, $opts) {
                return isset($opts['is_forwarded']) && $opts['is_forwarded'] === false;
            })
            ->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'hello',
            sessionId: 'test-session',
            userId: 1
        );

        $this->assertTrue($result->success);
    }

    // ──────────────────────────────────────────────
    //  Pre-loaded conversation history
    // ──────────────────────────────────────────────

    public function test_uses_passed_conversation_history_over_db(): void
    {
        $context = $this->makeContext();

        $this->mockConversation->shouldReceive('getOrCreateConversation')->andReturn('conv-1');
        // Should NOT call getConversationHistory when history is pre-passed
        $this->mockConversation->shouldNotReceive('getConversationHistory');

        $agentResponse = AgentResponse::conversational(message: 'ok', context: $context);

        $preloadedHistory = [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
        ];

        $this->mockOrchestrator->shouldReceive('process')
            ->once()
            ->withArgs(function ($msg, $sid, $uid, $opts) use ($preloadedHistory) {
                return $opts['conversation_history'] === $preloadedHistory;
            })
            ->andReturn($agentResponse);

        $result = $this->chatService->processMessage(
            message: 'follow up',
            sessionId: 'test-session',
            userId: 1,
            conversationHistory: $preloadedHistory
        );

        $this->assertTrue($result->success);
    }
}
