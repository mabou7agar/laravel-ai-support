<?php

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * Integration tests for ChatService using REAL OpenAI API calls.
 *
 * These tests exercise the full pipeline:
 *   ChatService → MinimalAIOrchestrator → AIEngineService → OpenAI API
 *
 * Each test uses a unique session ID to avoid cross-test context pollution.
 * Credits are disabled so tests don't fail on credit checks.
 *
 * Requires: OPENAI_API_KEY in parent project .env
 */
class ChatServiceIntegrationTest extends TestCase
{
    protected ChatService $chatService;
    protected ContextManager $contextManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->hasRealApiKey('openai')) {
            $this->markTestSkipped('ChatServiceIntegrationTest requires a real OPENAI_API_KEY');
        }

        // Disable credits — we're testing orchestration, not billing
        Config::set('ai-engine.credits.enabled', false);

        // Use a fast, cheap model for orchestration decisions
        Config::set('ai-engine.default', 'openai');
        Config::set('ai-engine.orchestration_model', 'gpt-4o-mini');

        // Disable nodes — package test env has no remote nodes
        Config::set('ai-engine.nodes.enabled', false);

        $this->chatService = app(ChatService::class);
        $this->contextManager = app(ContextManager::class);
    }

    protected function uniqueSession(): string
    {
        return 'test-session-' . uniqid();
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 1: Simple conversational greeting
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_simple_greeting_and_returns_ai_response(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Hello, how are you?',
            sessionId: $sessionId,
            useMemory: false,
            useActions: false,
            useIntelligentRAG: false,
        );

        $this->assertInstanceOf(AIResponse::class, $response);
        $this->assertTrue($response->success, 'Response should be successful: ' . ($response->error ?? $response->getContent()));
        $this->assertNotEmpty($response->getContent(), 'AI should return non-empty content');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 2: Multi-turn conversation retains context
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_retains_context_across_multiple_turns(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Introduce a topic
        $r1 = $this->chatService->processMessage(
            message: 'My name is Alexander and I live in Berlin.',
            sessionId: $sessionId,
            useMemory: false,
            useActions: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($r1->success, 'Turn 1 failed: ' . ($r1->error ?? $r1->getContent()));

        // Build conversation history manually for turn 2
        $history = [
            ['role' => 'user', 'content' => 'My name is Alexander and I live in Berlin.'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Ask about the previously stated info
        $r2 = $this->chatService->processMessage(
            message: 'What is my name and where do I live?',
            sessionId: $sessionId,
            useMemory: false,
            useActions: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success, 'Turn 2 failed: ' . ($r2->error ?? $r2->getContent()));

        $content = strtolower($r2->getContent());
        $this->assertStringContainsString('alexander', $content, 'AI should recall the name from context');
        $this->assertStringContainsString('berlin', $content, 'AI should recall the city from context');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 3: Orchestrator routes to conversational path
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_routes_general_question_through_orchestrator(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'What is the capital of France?',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success, 'Response failed: ' . ($response->error ?? $response->getContent()));

        $content = strtolower($response->getContent());
        $this->assertStringContainsString('paris', $content, 'AI should know the capital of France');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 4: Response metadata structure is correct
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_returns_proper_metadata_structure(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Tell me a fun fact about cats.',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $this->assertIsArray($response->metadata);

        // These keys are always set by AgentResponseConverter
        $this->assertArrayHasKey('workflow_active', $response->metadata);
        $this->assertArrayHasKey('workflow_completed', $response->metadata);
        $this->assertArrayHasKey('agent_strategy', $response->metadata);
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 5: Conversation memory integration
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_creates_conversation_when_memory_enabled(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Remember that my favorite color is blue.',
            sessionId: $sessionId,
            useMemory: true,
        );

        $this->assertTrue($response->success, 'Response failed: ' . ($response->error ?? $response->getContent()));
        // When memory is enabled, conversationId should be set
        $this->assertNotNull($response->conversationId, 'conversationId should be set when memory is enabled');
    }

    /** @test */
    public function it_skips_conversation_when_memory_disabled(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Just a quick question.',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $this->assertNull($response->conversationId, 'conversationId should be null when memory is disabled');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 6: Multi-turn with topic switch
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_topic_switch_in_conversation(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Talk about programming
        $r1 = $this->chatService->processMessage(
            message: 'What is the difference between PHP and Python?',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'What is the difference between PHP and Python?'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Abrupt topic switch to cooking
        $r2 = $this->chatService->processMessage(
            message: 'Actually, forget that. How do I make scrambled eggs?',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success, 'Topic switch failed: ' . ($r2->error ?? $r2->getContent()));

        $content = strtolower($r2->getContent());
        $this->assertTrue(
            str_contains($content, 'egg') || str_contains($content, 'scrambl') || str_contains($content, 'cook') || str_contains($content, 'pan'),
            'AI should respond about cooking, not programming. Got: ' . substr($r2->getContent(), 0, 200)
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 7: Orchestrator context persists in cache
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_persists_orchestrator_context_in_cache(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Hello there!',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);

        // The ContextManager should have saved context to cache
        $context = UnifiedActionContext::load($sessionId, null);
        $this->assertNotNull($context, 'Context should be persisted in cache after processMessage');
        $this->assertEquals($sessionId, $context->sessionId);

        // Conversation history should have at least the user message and assistant response
        $this->assertNotEmpty($context->conversationHistory, 'Conversation history should not be empty');
        $this->assertGreaterThanOrEqual(2, count($context->conversationHistory), 'Should have at least user + assistant messages');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 8: Structured reasoning — math question
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_math_reasoning_question(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'What is 17 multiplied by 23?',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($response->success, 'Math question failed: ' . ($response->error ?? $response->getContent()));
        $this->assertStringContainsString('391', $response->getContent(), 'AI should compute 17*23=391');
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 9: Three-turn conversation with recall
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_three_turn_conversation_with_recall(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1
        $r1 = $this->chatService->processMessage(
            message: 'I have a dog named Max and a cat named Luna.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'I have a dog named Max and a cat named Luna.'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Add more context
        $r2 = $this->chatService->processMessage(
            message: 'Max is a golden retriever and Luna is a siamese cat.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r2->success);

        $history[] = ['role' => 'user', 'content' => 'Max is a golden retriever and Luna is a siamese cat.'];
        $history[] = ['role' => 'assistant', 'content' => $r2->getContent()];

        // Turn 3: Ask about previously stated info
        $r3 = $this->chatService->processMessage(
            message: 'What breed is my dog?',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r3->success);

        $content = strtolower($r3->getContent());
        $this->assertTrue(
            str_contains($content, 'golden') || str_contains($content, 'retriever'),
            'AI should recall the dog breed from conversation. Got: ' . substr($r3->getContent(), 0, 200)
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 10: Parallel independent sessions don't leak
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_isolates_parallel_sessions(): void
    {
        $sessionA = $this->uniqueSession();
        $sessionB = $this->uniqueSession();

        // Session A: Talk about cats
        $rA = $this->chatService->processMessage(
            message: 'I love cats. My cat is named Whiskers.',
            sessionId: $sessionA,
            useMemory: false,
            useIntelligentRAG: false,
        );
        $this->assertTrue($rA->success);

        // Session B: Talk about cars
        $rB = $this->chatService->processMessage(
            message: 'I drive a Tesla Model 3.',
            sessionId: $sessionB,
            useMemory: false,
            useIntelligentRAG: false,
        );
        $this->assertTrue($rB->success);

        // Verify contexts are separate
        $ctxA = UnifiedActionContext::load($sessionA, null);
        $ctxB = UnifiedActionContext::load($sessionB, null);

        $this->assertNotNull($ctxA);
        $this->assertNotNull($ctxB);
        $this->assertNotEquals($ctxA->sessionId, $ctxB->sessionId);

        // Session A history should mention cats, not cars
        $historyA = collect($ctxA->conversationHistory)->pluck('content')->implode(' ');
        $this->assertStringContainsString('cat', strtolower($historyA));
        $this->assertStringNotContainsString('tesla', strtolower($historyA));
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 11: Orchestrator decision metadata is populated
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_populates_agent_strategy_in_metadata(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Hi there!',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        $this->assertArrayHasKey('agent_strategy', $response->metadata);
        // Strategy should be one of the known types
        $this->assertContains(
            $response->metadata['agent_strategy'],
            ['conversational', 'search_rag', 'use_tool', 'start_collector', 'needs_user_input', 'failure'],
            'agent_strategy should be a known type, got: ' . ($response->metadata['agent_strategy'] ?? 'null')
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 12: Long message handling
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_long_user_message(): void
    {
        $sessionId = $this->uniqueSession();

        $longMessage = 'I need help understanding the following concept. '
            . str_repeat('Machine learning is a subset of artificial intelligence. ', 20)
            . 'Can you summarize what machine learning is in one sentence?';

        $response = $this->chatService->processMessage(
            message: $longMessage,
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($response->success, 'Long message failed: ' . ($response->error ?? $response->getContent()));
        $this->assertNotEmpty($response->getContent());
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 13: RAG search path is exercised
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_exercises_rag_search_path_without_error(): void
    {
        $sessionId = $this->uniqueSession();

        // This message should trigger the orchestrator to try search_rag
        // Even without real RAG data, it should not error — it should fallback gracefully
        $response = $this->chatService->processMessage(
            message: 'Search for all invoices from last month',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: true,
            ragCollections: [],
        );

        $this->assertInstanceOf(AIResponse::class, $response);
        $this->assertTrue($response->success, 'RAG path should not error: ' . ($response->error ?? $response->getContent()));
        $this->assertNotEmpty($response->getContent());
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 14: Workflow flags when no workflow is active
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_reports_no_active_workflow_for_simple_chat(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'What time is it?',
            sessionId: $sessionId,
            useMemory: false,
        );

        $this->assertTrue($response->success);
        // No workflow should be active for a simple question
        $this->assertTrue(
            $response->metadata['workflow_completed'] ?? true,
            'workflow_completed should be true for simple chat'
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 15: Conversation with explicit engine/model
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_respects_explicit_engine_and_model_parameters(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Say the word "pineapple" and nothing else.',
            sessionId: $sessionId,
            engine: 'openai',
            model: 'gpt-4o-mini',
            useMemory: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($response->success, 'Explicit engine/model failed: ' . ($response->error ?? $response->getContent()));
        $this->assertStringContainsString(
            'pineapple',
            strtolower($response->getContent()),
            'AI should follow the instruction. Got: ' . $response->getContent()
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 16: Multi-turn with action-like request
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_action_like_request_after_listing(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Ask to list something (triggers orchestrator routing)
        $r1 = $this->chatService->processMessage(
            message: 'List 3 popular programming languages and their main use cases.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );

        $this->assertTrue($r1->success, 'Listing failed: ' . ($r1->error ?? $r1->getContent()));

        $history = [
            ['role' => 'user', 'content' => 'List 3 popular programming languages and their main use cases.'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Follow up with an action-like request referencing the list
        $r2 = $this->chatService->processMessage(
            message: 'Now compare the first two you mentioned in a table format.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );

        $this->assertTrue($r2->success, 'Follow-up action failed: ' . ($r2->error ?? $r2->getContent()));
        $this->assertNotEmpty($r2->getContent());
        // The response should reference programming languages, not be a generic error
        $content = strtolower($r2->getContent());
        $this->assertTrue(
            str_contains($content, 'python') || str_contains($content, 'javascript') || str_contains($content, 'java')
            || str_contains($content, 'language') || str_contains($content, 'comparison') || str_contains($content, '|'),
            'Follow-up should reference programming languages. Got: ' . substr($r2->getContent(), 0, 300)
        );
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 17: Error resilience — empty message
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_empty_message_gracefully(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: '',
            sessionId: $sessionId,
            useMemory: false,
        );

        // Should not throw — either succeeds with some response or fails gracefully
        $this->assertInstanceOf(AIResponse::class, $response);
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 18: Search instructions are passed through
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_passes_search_instructions_to_orchestrator(): void
    {
        $sessionId = $this->uniqueSession();

        $response = $this->chatService->processMessage(
            message: 'Find relevant information',
            sessionId: $sessionId,
            useMemory: false,
            searchInstructions: 'Focus only on recent items from 2024',
        );

        $this->assertInstanceOf(AIResponse::class, $response);
        $this->assertTrue($response->success, 'Search instructions test failed: ' . ($response->error ?? $response->getContent()));
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 19: Conversation with user ID
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_authenticated_user_context(): void
    {
        $sessionId = $this->uniqueSession();
        $user = $this->createTestUser();

        $response = $this->chatService->processMessage(
            message: 'Hello, who am I?',
            sessionId: $sessionId,
            useMemory: false,
            userId: $user->id,
        );

        $this->assertTrue($response->success, 'Authenticated user test failed: ' . ($response->error ?? $response->getContent()));

        // Verify context was created with user ID
        $context = UnifiedActionContext::load($sessionId, $user->id);
        if ($context) {
            $this->assertEquals($user->id, $context->userId);
        }
    }

    // ──────────────────────────────────────────────────────────
    //  Scenario 20: Full pipeline — list, follow-up, topic switch
    // ──────────────────────────────────────────────────────────

    /** @test */
    public function it_handles_full_conversation_flow_list_followup_switch(): void
    {
        $sessionId = $this->uniqueSession();

        // Turn 1: Ask for a list
        $r1 = $this->chatService->processMessage(
            message: 'Name 3 famous scientists and what they are known for.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
        );
        $this->assertTrue($r1->success);

        $history = [
            ['role' => 'user', 'content' => 'Name 3 famous scientists and what they are known for.'],
            ['role' => 'assistant', 'content' => $r1->getContent()],
        ];

        // Turn 2: Follow-up about one of them
        $r2 = $this->chatService->processMessage(
            message: 'Tell me more about the first one you mentioned.',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r2->success);
        $this->assertNotEmpty($r2->getContent());

        $history[] = ['role' => 'user', 'content' => 'Tell me more about the first one you mentioned.'];
        $history[] = ['role' => 'assistant', 'content' => $r2->getContent()];

        // Turn 3: Complete topic switch
        $r3 = $this->chatService->processMessage(
            message: 'Now, what is the recipe for chocolate cake?',
            sessionId: $sessionId,
            useMemory: false,
            useIntelligentRAG: false,
            conversationHistory: $history,
        );
        $this->assertTrue($r3->success);

        $content = strtolower($r3->getContent());
        $this->assertTrue(
            str_contains($content, 'chocolate') || str_contains($content, 'cake')
            || str_contains($content, 'flour') || str_contains($content, 'bake'),
            'Topic switch should produce cooking-related response. Got: ' . substr($r3->getContent(), 0, 300)
        );
    }

    protected function tearDown(): void
    {
        // Clean up any cached contexts from this test run
        Cache::flush();
        \Mockery::close();
        parent::tearDown();
    }
}
