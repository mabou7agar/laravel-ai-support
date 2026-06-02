<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\MessageRoutingClassifier;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Tests for the backward-compatible hardening of multi-turn conversation
 * history hydration, error handling, and turn deduplication.
 *
 * Findings addressed:
 *   1  — Dedup guard prevents double user-turn when client includes current turn in history
 *   2  — History is hard-capped at compactor.max_messages after hydration
 *   3  — null content is normalised to '' during hydration
 *   4  — Array (multipart/vision) content is flattened to text (hydration + compactor)
 *   5  — routeThroughPipeline catches \Throwable (TypeError, ValueError, etc.)
 *   9  — Invalid (non-lowercase) roles are silently dropped during hydration
 */
class ConversationHistoryHardeningTest extends UnitTestCase
{
    // ------------------------------------------------------------------
    // Finding 1 — Dedup guard
    // ------------------------------------------------------------------

    public function test_dedup_guard_skips_add_user_message_when_already_in_history(): void
    {
        $message = 'Tell me about the project';
        $history = [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello!'],
            ['role' => 'user', 'content' => $message], // current turn already in history
        ];

        $context = $this->hydrateAndProcess($message, $history);

        $userTurns = array_filter(
            $context->conversationHistory,
            static fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'Tell me about the project'
        );
        $this->assertCount(1, $userTurns, 'The current user turn must appear exactly once (dedup guard).');
    }

    public function test_add_user_message_is_called_when_current_turn_not_in_history(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => 'Hello!'],
        ];

        $context = $this->hydrateAndProcess('New question', $history);

        $last = end($context->conversationHistory);
        $this->assertIsArray($last);
        $this->assertSame('user', $last['role']);
        $this->assertSame('New question', $last['content'], 'The current turn must be appended when not already in history.');
    }

    // ------------------------------------------------------------------
    // Finding 2 — Hard-cap history at compactor max_messages
    // ------------------------------------------------------------------

    public function test_history_is_capped_at_max_messages_during_hydration(): void
    {
        config()->set('ai-agent.context_compaction.max_messages', 4);

        // Build a 10-entry history — should be capped to the last 4.
        $history = [];
        for ($i = 1; $i <= 10; $i++) {
            $history[] = ['role' => $i % 2 !== 0 ? 'user' : 'assistant', 'content' => "msg {$i}"];
        }

        $context = $this->hydrateAndProcess('next question', $history);

        // 4 capped entries + the current user message appended = 5.
        $this->assertCount(5, $context->conversationHistory, 'History must be capped at max_messages + the current turn.');

        $contents = array_column($context->conversationHistory, 'content');
        $this->assertContains('msg 10', $contents, 'The most recent entry must be preserved after capping.');
        $this->assertNotContains('msg 1', $contents, 'Old entries exceeding the cap must be dropped.');
    }

    // ------------------------------------------------------------------
    // Finding 3 — null content normalised to ''
    // ------------------------------------------------------------------

    public function test_null_content_is_normalised_to_empty_string_during_hydration(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Hello'],
            ['role' => 'assistant', 'content' => null], // tool_calls assistant turn
        ];

        $context = $this->hydrateAndProcess('follow up', $history);

        $assistantEntry = collect($context->conversationHistory)
            ->first(static fn (array $m): bool => $m['role'] === 'assistant');

        $this->assertNotNull($assistantEntry);
        $this->assertSame('', $assistantEntry['content'], 'null content must be normalised to empty string.');
    }

    // ------------------------------------------------------------------
    // Finding 4 — Array (multipart) content flattened to text (hydration)
    // ------------------------------------------------------------------

    public function test_multipart_content_is_flattened_to_text_during_hydration(): void
    {
        $history = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Describe this image:'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/img.png']],
                ],
            ],
        ];

        $context = $this->hydrateAndProcess('What do you see?', $history);

        // The first entry (index 0) is the hydrated vision message.
        $entry = $context->conversationHistory[0] ?? null;
        $this->assertIsArray($entry);
        $this->assertIsString($entry['content'], 'Multipart content must be normalised to a string.');
        $this->assertStringContainsString('Describe this image:', $entry['content']);
    }

    // ------------------------------------------------------------------
    // Finding 4 (compactor) — ConversationContextCompactor.sanitizeMessages
    // ------------------------------------------------------------------

    public function test_compactor_sanitise_flattens_array_content_instead_of_casting_to_array_string(): void
    {
        $context = new UnifiedActionContext('session-compact-vision', 6);
        $context->conversationHistory = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Please analyse'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/pic.jpg']],
                ],
            ],
        ];

        $compactor = app(ConversationContextCompactor::class);
        $compactor->compact($context);

        $entry = $context->conversationHistory[0] ?? null;
        $this->assertIsArray($entry);
        $this->assertIsString($entry['content'], 'Compactor must produce a string content, not a PHP array.');
        $this->assertStringContainsString('Please analyse', $entry['content']);
        $this->assertStringNotContainsString('Array', $entry['content'], 'Content must not be cast to the literal string "Array".');
    }

    // ------------------------------------------------------------------
    // Finding 9 — Invalid (non-lowercase) roles are silently dropped
    // ------------------------------------------------------------------

    public function test_invalid_role_entries_are_dropped_during_hydration(): void
    {
        $history = [
            ['role' => 'User', 'content' => 'Hello'],       // uppercase — invalid
            ['role' => 'ASSISTANT', 'content' => 'Hi!'],     // all-caps — invalid
            ['role' => 'user', 'content' => 'Valid entry'],  // valid
        ];

        $context = $this->hydrateAndProcess('next', $history);

        // Only 'Valid entry' + the current 'next' message should remain.
        $this->assertCount(2, $context->conversationHistory, 'Entries with non-lowercase roles must be dropped.');
        $this->assertSame('user', $context->conversationHistory[0]['role']);
        $this->assertSame('Valid entry', $context->conversationHistory[0]['content']);
    }

    // ------------------------------------------------------------------
    // Finding 5 — \Throwable caught in routeThroughPipeline
    // ------------------------------------------------------------------

    public function test_type_error_in_routing_pipeline_falls_back_gracefully(): void
    {
        $context = new UnifiedActionContext('session-throwable', 8);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->andReturn([
                'action' => 'conversational',
                'reasoning' => 'fallback',
                'decision_source' => 'fallback',
            ]);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturn(AgentResponse::conversational(message: 'graceful', context: $context));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $r) => $r);

        // RoutingPipeline subclass that throws a TypeError (not \Exception).
        $pipeline = new class extends \LaravelAIEngine\Services\Agent\Routing\RoutingPipeline {
            public function decide(string $message, UnifiedActionContext $context, array $options = []): \LaravelAIEngine\DTOs\RoutingTrace
            {
                throw new \TypeError('Simulated TypeError from pipeline');
            }
        };

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher,
            $pipeline
        );

        // Must not throw; should fall through to heuristic route.
        $response = $processor->process('test throwable', 'session-throwable', 8, [
            'use_rag' => false,
        ]);

        $this->assertInstanceOf(AgentResponse::class, $response);
    }

    // ------------------------------------------------------------------
    // Edge cases — empty / non-array / missing history
    // ------------------------------------------------------------------

    public function test_missing_conversation_history_option_is_handled_gracefully(): void
    {
        $context = $this->hydrateAndProcess('hello', null, []);
        $this->assertCount(1, $context->conversationHistory, 'The current user turn must be present even without prior history.');
    }

    public function test_empty_array_conversation_history_is_handled_gracefully(): void
    {
        $context = $this->hydrateAndProcess('hello', []);
        $this->assertCount(1, $context->conversationHistory);
    }

    public function test_non_array_conversation_history_is_handled_gracefully(): void
    {
        // Should not throw even if caller accidentally passes a string.
        $context = $this->hydrateAndProcess('hello', null, ['conversation_history' => 'not-an-array']);
        $this->assertCount(1, $context->conversationHistory);
    }

    public function test_history_entry_missing_content_key_is_dropped(): void
    {
        $history = [
            ['role' => 'user'],                             // missing 'content' — should be dropped
            ['role' => 'user', 'content' => 'Valid turn'],
        ];

        $context = $this->hydrateAndProcess('new msg', $history);

        // 'Valid turn' + 'new msg' — the entry without 'content' was dropped.
        $this->assertCount(2, $context->conversationHistory);
    }

    // ------------------------------------------------------------------
    // Bug H1 — fresh client history must override stale cached context history
    // ------------------------------------------------------------------

    public function test_fresh_supplied_history_overrides_existing_cached_context_history(): void
    {
        // Simulate a re-entrant process() (RAG -> orchestrator reroute): the cached
        // context already carries an older snapshot of the history, but the client
        // supplies a FRESH conversation_history with updated turns.
        $context = new UnifiedActionContext('session-h1-' . uniqid(), 99);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'stale turn one'],
            ['role' => 'assistant', 'content' => 'stale answer one'],
        ];

        $freshHistory = [
            ['role' => 'user', 'content' => 'stale turn one'],
            ['role' => 'assistant', 'content' => 'stale answer one'],
            ['role' => 'user', 'content' => 'fresh follow up'],
            ['role' => 'assistant', 'content' => 'fresh answer'],
        ];

        $result = $this->processWithContext($context, 'next question', [
            'conversation_history' => $freshHistory,
        ]);

        $contents = array_column($result->conversationHistory, 'content');

        // The fresh turns must now be present (previously silently ignored by the
        // early-return guard).
        $this->assertContains('fresh follow up', $contents, 'Fresh client history must be applied to the context.');
        $this->assertContains('fresh answer', $contents);

        // Current user turn appended exactly once (not duplicated by the dedup guard).
        $currentTurns = array_filter(
            $result->conversationHistory,
            static fn (array $m): bool => $m['role'] === 'user' && $m['content'] === 'next question'
        );
        $this->assertCount(1, $currentTurns, 'The current user turn must appear exactly once.');
    }

    public function test_dedup_guard_holds_when_fresh_history_already_includes_current_turn(): void
    {
        $context = new UnifiedActionContext('session-h1b-' . uniqid(), 99);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'old turn'],
        ];

        $message = 'the new turn';
        $freshHistory = [
            ['role' => 'user', 'content' => 'old turn'],
            ['role' => 'assistant', 'content' => 'old answer'],
            ['role' => 'user', 'content' => $message], // current turn already supplied
        ];

        $result = $this->processWithContext($context, $message, [
            'conversation_history' => $freshHistory,
        ]);

        $currentTurns = array_filter(
            $result->conversationHistory,
            static fn (array $m): bool => $m['role'] === 'user' && $m['content'] === $message
        );
        $this->assertCount(1, $currentTurns, 'Dedup guard must prevent duplicating the current turn after re-hydration.');
    }

    public function test_reroute_without_supplied_history_preserves_existing_context_history(): void
    {
        // Mirrors the strip-history reroute path: no conversation_history supplied,
        // so the existing context history must be left intact.
        $context = new UnifiedActionContext('session-h1c-' . uniqid(), 99);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'preserved turn'],
            ['role' => 'assistant', 'content' => 'preserved answer'],
        ];

        $result = $this->processWithContext($context, 'reroute message', []);

        $contents = array_column($result->conversationHistory, 'content');
        $this->assertContains('preserved turn', $contents, 'Existing history must survive a reroute without supplied history.');
        $this->assertContains('preserved answer', $contents);
        $this->assertContains('reroute message', $contents, 'The current turn is still appended.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Run process() against a caller-supplied (pre-seeded) context so tests can
     * exercise the re-entrant / cached-history paths.
     *
     * @param array<string, mixed> $opts
     */
    private function processWithContext(
        UnifiedActionContext $context,
        string $message,
        array $opts
    ): UnifiedActionContext {
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(static fn (RoutingDecision $d, string $m, UnifiedActionContext $c): AgentResponse
                => AgentResponse::conversational(message: 'ok', context: $c));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $r) => $r);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->andReturn([
                'action'          => 'conversational',
                'reasoning'       => 'test fallback',
                'decision_source' => 'fallback',
            ]);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $processor->process($message, $context->sessionId, 99, $opts);

        return $context;
    }

    /**
     * Build a processor, run process(), and return the context populated by hydration.
     *
     * We bypass the routing layer entirely by making the context return its own state
     * after hydration; the dispatcher returns a no-op response.
     *
     * @param array<int, array<string, mixed>>|null $history   Pass null to omit the key entirely.
     * @param array<string, mixed>                  $extraOpts Merged on top of (or instead of) history.
     */
    private function hydrateAndProcess(
        string $message,
        ?array $history = null,
        array $extraOpts = []
    ): UnifiedActionContext {
        $sessionId = 'session-hydrate-' . uniqid();
        $context   = new UnifiedActionContext($sessionId, 99);

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->andReturn($context);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->andReturnUsing(static fn (RoutingDecision $d, string $m, UnifiedActionContext $c): AgentResponse
                => AgentResponse::conversational(message: 'ok', context: $c));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $r) => $r);

        $selection = Mockery::mock(AgentSelectionService::class);
        $selection->shouldReceive('detectsOptionSelection')->andReturnFalse();
        $selection->shouldReceive('detectsPositionalReference')->andReturnFalse();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldReceive('route')
            ->andReturn([
                'action'          => 'conversational',
                'reasoning'       => 'test fallback',
                'decision_source' => 'fallback',
            ]);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            Mockery::mock(NodeSessionManager::class),
            new MessageRoutingClassifier(),
            null,
            null,
            $dispatcher
        );

        $opts = $extraOpts;
        if ($history !== null && !isset($opts['conversation_history'])) {
            $opts['conversation_history'] = $history;
        }

        $processor->process($message, $sessionId, 99, $opts);

        return $context;
    }
}
