<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AIAgentRun;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentFinalResponseStreamingService;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeRuntime;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryScopeResolver;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * Generated coverage for the "Multi-turn chat + memory" surface:
 *   - ContextManager (cache hydration + durable AIAgentRun restore + compaction)
 *   - LaravelAgentProcessor (stateless hydration, dedup guard, idempotency, federation detach, RAG fallback)
 *   - AgentConversationService (memory retrieval policy, prompt assembly, streaming seam)
 *   - ConversationMemoryScopeResolver
 *
 * Self-contained: unique namespace/class, no shared helpers, mocks the AI engine and
 * collaborators (never makes real LLM/network calls). Extends the DB-backed TestCase so the
 * AIAgentRun-restore scenarios can persist real rows.
 */
class ChatMultiturnFlowTest extends TestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    // ---------------------------------------------------------------------
    // Shared test doubles
    // ---------------------------------------------------------------------

    private function passthroughFinalizer(): AgentResponseFinalizer
    {
        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->andReturnUsing(fn (UnifiedActionContext $ctx, AgentResponse $response) => $response);

        return $finalizer;
    }

    /**
     * Build a processor with a mocked ContextManager that always returns $context, and an
     * AiNative double that captures the context it receives so we can assert the hydrated
     * history reached process() verbatim.
     *
     * @return array{0: LaravelAgentProcessor, 1: \Closure(): ?UnifiedActionContext}
     */
    private function processorCapturingNative(
        UnifiedActionContext $context,
        ?int $getOrCreateTimes = 1,
        ?AgentExecutionDispatcher $dispatcher = null,
        ?NodeSessionManager $node = null
    ): array {
        $captured = null;

        $contextManager = Mockery::mock(ContextManager::class);
        $expectation = $contextManager->shouldReceive('getOrCreate')->andReturn($context);
        if ($getOrCreateTimes !== null) {
            $expectation->times($getOrCreateTimes);
        }

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->andReturnUsing(function (string $message, UnifiedActionContext $ctx, array $options) use (&$captured) {
                // Snapshot the history at call time (the array is value-copied here).
                $captured = $ctx->conversationHistory;

                return AgentResponse::conversational(message: 'native-reply', context: $ctx);
            });

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer(),
            $node ?? Mockery::mock(NodeSessionManager::class),
            $dispatcher ?? Mockery::mock(AgentExecutionDispatcher::class),
            $native
        );

        return [$processor, function () use (&$captured) {
            return $captured;
        }];
    }

    private function makeConversationService(
        AIEngineService $ai,
        ?ConversationMemoryRetriever $retriever = null,
        ?ConversationMemoryPromptBuilder $promptBuilder = null,
        ?AgentFinalResponseStreamingService $streaming = null
    ): AgentConversationService {
        $rag = Mockery::mock(RAGDecisionEngine::class);
        $rag->shouldNotReceive('process');
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);

        return new AgentConversationService(
            $ai,
            $rag,
            $selectedEntity,
            $selection,
            null,           // ragPipeline
            null,           // localeResources
            null,           // routingContextResolver
            $retriever,
            $promptBuilder,
            null,           // memoryScopeResolver (resolved from container)
            $streaming
        );
    }

    // =====================================================================
    // ContextManager: cache-HIT + restoreMissingDurableState (priority 5)
    // =====================================================================

    public function test_cache_hit_backfills_durable_ai_native_without_clobbering_history_then_preserves_active_state(): void
    {
        // --- Seed cache: non-empty history, NO ai_native ---
        $cached = new UnifiedActionContext('session-s1', 7, conversationHistory: [
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'first answer'],
        ]);
        $cached->persist();

        // --- Seed DB: a COMPLETED run carrying durable (not active) ai_native ---
        AIAgentRun::query()->create([
            'uuid' => 'run-durable-s1',
            'session_id' => 'session-s1',
            'user_id' => '7',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'input' => ['message' => 'older durable turn'],
            'final_response' => [
                'success' => true,
                'message' => 'durable done',
                'metadata' => [
                    'ai_native' => [
                        'recent_outcomes' => [
                            ['tool' => 'create_invoice', 'outcome' => 'created', 'label' => 'INV-1'],
                        ],
                        'task_frame' => [
                            'active_objective' => 'create_invoice',
                            'status' => 'completed',
                            'completed_writes' => [
                                ['tool' => 'create_invoice', 'label' => 'INV-1'],
                            ],
                        ],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        $manager = new ContextManager();
        $context = $manager->getOrCreate('session-s1', 7);

        // Original cache history preserved verbatim (=== [] guard did NOT overwrite it).
        $this->assertSame([
            ['role' => 'user', 'content' => 'first question'],
            ['role' => 'assistant', 'content' => 'first answer'],
        ], $context->conversationHistory);

        // Durable ai_native + restore marker backfilled from the completed run.
        $this->assertSame('INV-1', $context->metadata['ai_native']['recent_outcomes'][0]['label']);
        $this->assertSame('run-durable-s1', $context->metadata['restored_from_agent_run_id']);

        // --- Now: cache an ACTIVE pending_tool ai_native; add a DIFFERENT durable run. ---
        $activeCached = new UnifiedActionContext('session-s1', 7, conversationHistory: [
            ['role' => 'user', 'content' => 'kept history'],
        ], metadata: [
            'ai_native' => [
                'pending_tool' => ['name' => 'create_customer', 'params' => ['name' => 'ACME']],
            ],
        ]);
        $activeCached->persist();

        AIAgentRun::query()->create([
            'uuid' => 'run-would-clobber-s1',
            'session_id' => 'session-s1',
            'user_id' => '7',
            'runtime' => 'laravel',
            'status' => AIAgentRun::STATUS_COMPLETED,
            'input' => ['message' => 'newer durable turn'],
            'final_response' => [
                'success' => true,
                'message' => 'newer durable',
                'metadata' => [
                    'ai_native' => [
                        'recent_outcomes' => [
                            ['tool' => 'send_email', 'outcome' => 'sent', 'label' => 'MAIL-9'],
                        ],
                    ],
                ],
            ],
            'completed_at' => now(),
        ]);

        $context2 = $manager->getOrCreate('session-s1', 7);

        // The ACTIVE pending_tool state is preserved untouched: restoreFromAgentRuns was
        // never consulted (isDurableAiNativeState true -> early return), so the new run's
        // durable state did NOT replace it and no restore marker was stamped.
        $this->assertSame('create_customer', $context2->metadata['ai_native']['pending_tool']['name']);
        $this->assertArrayNotHasKey('recent_outcomes', $context2->metadata['ai_native']);
        $this->assertArrayNotHasKey('restored_from_agent_run_id', $context2->metadata);
    }

    public function test_compactor_compact_invoked_inside_get_or_create(): void
    {
        $context = new UnifiedActionContext('session-compact', 1, conversationHistory: [
            ['role' => 'user', 'content' => 'hi'],
        ], metadata: [
            // Active state so restoreMissingDurableState early-returns (no DB hit).
            'ai_native' => ['pending_tool' => ['name' => 'noop']],
        ]);
        $context->persist();

        $compactor = Mockery::mock(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class);
        $compactor->shouldReceive('compact')->once()->with(Mockery::type(UnifiedActionContext::class));

        $manager = new ContextManager($compactor);
        $returned = $manager->getOrCreate('session-compact', 1);

        // The (single) compact() expectation is verified on teardown; assert the cache-hit
        // context flowed through with its active state intact.
        $this->assertSame('noop', $returned->metadata['ai_native']['pending_tool']['name']);
    }

    // =====================================================================
    // ContextManager: save/clear/exists/load + dual cache-key (priority 4)
    // =====================================================================

    public function test_save_clear_exists_load_public_api_and_dual_cache_key_forgetting(): void
    {
        $context = new UnifiedActionContext('session-s2', 'u9');

        $capturedOptions = null;
        $compactor = Mockery::mock(\LaravelAIEngine\Services\Agent\ConversationContextCompactor::class);
        $compactor->shouldReceive('compact')
            ->once()
            ->with(Mockery::type(UnifiedActionContext::class), Mockery::on(function (array $options) use (&$capturedOptions): bool {
                $capturedOptions = $options;

                return true;
            }));

        $manager = new ContextManager($compactor);
        $manager->save($context, ['workspace_id' => 'wsX']);

        // compact() received the request-scope options.
        $this->assertSame(['workspace_id' => 'wsX'], $capturedOptions);

        // exists()/load() round-trip the persisted, scoped context.
        $this->assertTrue($manager->exists('session-s2', 'u9'));
        $loaded = $manager->load('session-s2', 'u9');
        $this->assertInstanceOf(UnifiedActionContext::class, $loaded);
        $this->assertSame('session-s2', $loaded->sessionId);
        $this->assertSame('u9', (string) $loaded->userId);

        // Write a legacy-cache-key entry directly.
        Cache::put(UnifiedActionContext::legacyCacheKey('session-s2'), ['session_id' => 'session-s2', 'user_id' => 'u9'], now()->addHour());
        $this->assertNotNull(Cache::get(UnifiedActionContext::legacyCacheKey('session-s2')));

        // clear() forgets BOTH the namespaced AND legacy keys.
        $manager->clear('session-s2', 'u9');
        $this->assertFalse($manager->exists('session-s2', 'u9'));
        $this->assertNull(Cache::get(UnifiedActionContext::cacheKey('session-s2', 'u9')));
        $this->assertNull(Cache::get(UnifiedActionContext::legacyCacheKey('session-s2')));
    }

    // =====================================================================
    // restoreFromAgentRuns resilience + 12-run window cap (priority 4)
    // =====================================================================

    public function test_restore_from_agent_runs_swallows_db_throwable_and_returns_fresh_context(): void
    {
        // No cache. Force the AIAgentRun query to throw by dropping the table.
        $this->app['db']->getSchemaBuilder()->drop('ai_agent_runs');

        $manager = new ContextManager();
        $context = $manager->getOrCreate('session-s3', 'u3');

        // Brand-new empty context; no exception bubbled, no restore marker.
        $this->assertInstanceOf(UnifiedActionContext::class, $context);
        $this->assertSame([], $context->conversationHistory);
        $this->assertArrayNotHasKey('restored_from_agent_run_id', $context->metadata);
        $this->assertArrayNotHasKey('ai_native', $context->metadata);
    }

    public function test_restore_from_agent_runs_caps_history_to_twelve_most_recent_runs(): void
    {
        // Keep the compactor a no-op so we can observe the raw 12-run hydration window
        // (24 messages) rather than its post-compaction tail.
        config()->set('ai-agent.context_compaction.enabled', false);
        config()->set('ai-agent.context_compaction.max_messages', 200);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 200);

        // Seed 15 runs with distinct user messages and assistant replies, no cache.
        for ($i = 1; $i <= 15; $i++) {
            AIAgentRun::query()->create([
                'uuid' => "run-window-{$i}",
                'session_id' => 'session-s4',
                'user_id' => 'u4',
                'runtime' => 'laravel',
                'status' => AIAgentRun::STATUS_COMPLETED,
                'input' => ['message' => "user-msg-{$i}"],
                'final_response' => ['message' => "assistant-msg-{$i}"],
                'completed_at' => now(),
            ]);
        }

        $manager = new ContextManager();
        $context = $manager->getOrCreate('session-s4', 'u4');

        $contents = array_column($context->conversationHistory, 'content');

        // Only the 12 most-recent runs (4..15) contribute; older runs 1..3 excluded.
        $this->assertContains('user-msg-4', $contents);
        $this->assertContains('user-msg-15', $contents);
        $this->assertNotContains('user-msg-1', $contents);
        $this->assertNotContains('user-msg-3', $contents);

        // user/assistant turns interleaved, oldest-to-newest within the window.
        $this->assertSame('user-msg-4', $contents[0]);
        $this->assertSame('assistant-msg-4', $contents[1]);
        // Last entry is the newest run's assistant turn (user precedes assistant per run).
        $this->assertSame('assistant-msg-15', $contents[array_key_last($contents)]);

        // 12 runs * 2 turns each.
        $this->assertCount(24, $context->conversationHistory);
    }

    // =====================================================================
    // Stateless multi-turn dedup guard (priority 5)
    // =====================================================================

    public function test_stateless_history_hydrated_and_dedup_guard_appends_or_skips_current_turn(): void
    {
        // --- Call 1: last supplied entry is a DIFFERENT user turn -> append current ---
        $context1 = new UnifiedActionContext('sess-stateless', 5);
        [$processor1, $captured1] = $this->processorCapturingNative($context1);

        $history = [
            ['role' => 'user', 'content' => 'what is the first one?'],
            ['role' => 'assistant', 'content' => 'The first one is A.'],
            ['role' => 'user', 'content' => 'tell me more about A'],
        ];

        $processor1->process('and the second one?', 'sess-stateless', 5, [
            'conversation_history' => $history,
        ]);

        $reached1 = $captured1();
        $this->assertCount(4, $reached1);
        $this->assertSame('tell me more about A', $reached1[2]['content']);
        $this->assertSame('user', $reached1[3]['role']);
        $this->assertSame('and the second one?', $reached1[3]['content']);

        // --- Call 2: last supplied entry IS the current user turn -> skip addUserMessage ---
        $context2 = new UnifiedActionContext('sess-stateless', 5);
        [$processor2, $captured2] = $this->processorCapturingNative($context2);

        $historyEndingWithCurrent = [
            ['role' => 'user', 'content' => 'what is the first one?'],
            ['role' => 'assistant', 'content' => 'The first one is A.'],
            ['role' => 'user', 'content' => 'and the second one?'],
        ];

        $processor2->process('and the second one?', 'sess-stateless', 5, [
            'conversation_history' => $historyEndingWithCurrent,
        ]);

        $reached2 = $captured2();
        // Exactly ONE copy of the current turn — no double-append.
        $this->assertCount(3, $reached2);
        $userTurns = array_filter($reached2, fn ($m) => $m['role'] === 'user' && $m['content'] === 'and the second one?');
        $this->assertCount(1, $userTurns);
    }

    // =====================================================================
    // hydrateConversationHistory: ALL entries invalid -> intact (priority 5)
    // =====================================================================

    public function test_all_invalid_supplied_history_leaves_existing_context_history_intact(): void
    {
        $preseeded = [
            ['role' => 'user', 'content' => 'prior one'],
            ['role' => 'assistant', 'content' => 'prior reply'],
        ];
        $context = new UnifiedActionContext('sess-invalid', 11, conversationHistory: $preseeded);

        [$processor, $captured] = $this->processorCapturingNative($context);

        $processor->process('next', 'sess-invalid', 11, [
            'conversation_history' => [
                ['role' => 'USER', 'content' => 'uppercase role'], // invalid role
                ['role' => 'bogus', 'content' => 'bad role'],       // invalid role
                'not-an-array',                                      // not an array
                ['role' => 'user'],                                  // missing content key
            ],
        ]);

        $reached = $captured();

        // The 2 pre-seeded valid turns survive ($filtered === [] early return), and the
        // current message is appended via addUserMessage (preserved last != current).
        $this->assertCount(3, $reached);
        $this->assertSame('prior one', $reached[0]['content']);
        $this->assertSame('prior reply', $reached[1]['content']);
        $this->assertSame('user', $reached[2]['role']);
        $this->assertSame('next', $reached[2]['content']);
    }

    // =====================================================================
    // Idempotency: TTL + session namespacing + full-field replay (priority 5)
    // =====================================================================

    public function test_idempotency_full_field_replay_ttl_and_session_namespacing(): void
    {
        Cache::flush();
        config()->set('ai-agent.idempotency.ttl_seconds', 120);

        $contextA = new UnifiedActionContext('sess-A', 1);
        $contextB = new UnifiedActionContext('sess-B', 1);

        $contextManager = Mockery::mock(ContextManager::class);
        // sess-A: getOrCreate once (first call only; retry replays from cache).
        $contextManager->shouldReceive('getOrCreate')->with('sess-A', 1)->once()->andReturn($contextA);
        // sess-B: fresh route once.
        $contextManager->shouldReceive('getOrCreate')->with('sess-B', 1)->once()->andReturn($contextB);

        $rich = new AgentResponse(
            success: true,
            message: 'Proceed to checkout?',
            data: ['cart_total' => 4200],
            strategy: 'quick_action',
            context: $contextA,
            needsUserInput: true,
            actions: [['label' => 'Confirm', 'value' => 'yes']],
            metadata: ['tool_used' => 'checkout'],
            isComplete: false,
            nextStep: 'collect_payment',
            requiredInputs: [['name' => 'card', 'type' => 'text']]
        );

        $native = Mockery::mock(AiNativeRuntime::class);
        // process() once for sess-A, once for sess-B.
        $native->shouldReceive('process')->with('checkout', $contextA, Mockery::any())->once()->andReturn($rich);
        $native->shouldReceive('process')->with('checkout', $contextB, Mockery::any())
            ->once()
            ->andReturn(AgentResponse::conversational(message: 'fresh route', context: $contextB));

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer(),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native
        );

        $opts = ['idempotency_key' => 'K1'];

        $first = $processor->process('checkout', 'sess-A', 1, $opts);
        $this->assertSame('Proceed to checkout?', $first->message);

        // TTL within tolerance of 120s.
        $key = 'ai-agent:processor:idempotency:' . sha1('sess-A|K1');
        $store = Cache::getStore();
        if (method_exists($store, 'getExpiration')) {
            // Not available on array store; skip.
        }
        $this->assertIsArray(Cache::get($key));

        // Retry on sess-A: ALL fields replay, context dropped, replay flag set.
        $replay = $processor->process('checkout', 'sess-A', 1, $opts);
        $this->assertSame('Proceed to checkout?', $replay->message);
        $this->assertSame(['cart_total' => 4200], $replay->data);
        $this->assertSame('quick_action', $replay->strategy);
        $this->assertTrue($replay->needsUserInput);
        $this->assertSame([['label' => 'Confirm', 'value' => 'yes']], $replay->actions);
        $this->assertSame('collect_payment', $replay->nextStep);
        $this->assertSame([['name' => 'card', 'type' => 'text']], $replay->requiredInputs);
        $this->assertTrue($replay->metadata['idempotent_replay']);
        $this->assertNull($replay->context);

        // Different session, SAME key: no cross-session replay -> AiNative runs again.
        $other = $processor->process('checkout', 'sess-B', 1, $opts);
        $this->assertSame('fresh route', $other->message);
        $this->assertNull($other->metadata['idempotent_replay'] ?? null);
    }

    public function test_idempotency_cache_entry_honours_configured_ttl_window(): void
    {
        Cache::flush();
        config()->set('ai-agent.idempotency.ttl_seconds', 120);

        $context = new UnifiedActionContext('sess-ttl', 1);
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')->once()->andReturn(AgentResponse::conversational(message: 'ok', context: $context));

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer(),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native
        );

        // Freeze time, then assert the entry has not expired within the TTL window and
        // is gone after it (proves the configured 120s TTL was applied).
        $this->travelTo(now());
        $processor->process('go', 'sess-ttl', 1, ['idempotency_key' => 'TTLKEY']);
        $key = 'ai-agent:processor:idempotency:' . sha1('sess-ttl|TTLKEY');

        $this->assertIsArray(Cache::get($key)); // present now

        $this->travel(119)->seconds();
        $this->assertIsArray(Cache::get($key)); // still present just before TTL

        $this->travel(2)->seconds();
        $this->assertNull(Cache::get($key));    // expired just after 120s
        $this->travelBack();
    }

    // =====================================================================
    // Federation new-topic detach (priority 5)
    // =====================================================================

    public function test_new_topic_detach_forgets_node_state_and_falls_through_to_ai_native(): void
    {
        $context = new UnifiedActionContext('sess-detach', 9);
        $context->set('routed_to_node', ['node_slug' => 'billing']);
        $context->set('remote_pending_action', ['x' => 1]);
        $context->pendingAction = ['type' => 'remote_node_session'];

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldReceive('shouldContinueSession')->once()->andReturnFalse();

        // The execution dispatcher must NEVER be called on the detach path.
        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');

        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::conversational(message: 'Here are your account settings.', context: $context));

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer(),
            $node,
            $dispatcher,
            $native
        );

        $response = $processor->process('actually, summarize my account settings', 'sess-detach', 9);

        $this->assertSame('Here are your account settings.', $response->message);
        $this->assertFalse($context->has('routed_to_node'));
        $this->assertFalse($context->has('remote_pending_action'));
        $this->assertNull($context->pendingAction);
    }

    // =====================================================================
    // shouldFallbackToLocalRag gating matrix (priority 4)
    // =====================================================================

    private function fallbackProcessor(UnifiedActionContext $context, AgentExecutionDispatcher $dispatcher): LaravelAgentProcessor
    {
        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')->once()->andReturn($context);

        $node = Mockery::mock(NodeSessionManager::class);
        $node->shouldReceive('shouldContinueSession')->once()->andReturnTrue();

        return new LaravelAgentProcessor(
            $contextManager,
            $this->passthroughFinalizer(),
            $node,
            $dispatcher,
            Mockery::mock(AiNativeRuntime::class)
        );
    }

    public function test_continue_node_success_does_not_trigger_rag_fallback(): void
    {
        $context = new UnifiedActionContext('sess-fb1', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::CONTINUE_NODE)
            ->andReturn(AgentResponse::conversational(message: 'Remote handled it.', context: $context));
        $dispatcher->shouldNotReceive('dispatch')
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::SEARCH_RAG);

        $response = $this->fallbackProcessor($context, $dispatcher)->process('next', 'sess-fb1', 1);
        $this->assertSame('Remote handled it.', $response->message);
    }

    public function test_continue_node_failure_with_fallback_disabled_does_not_fall_back(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_on_failure', false);

        $context = new UnifiedActionContext('sess-fb2', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::CONTINUE_NODE)
            ->andReturn(AgentResponse::failure(message: "I couldn't reach remote node 'x' (HTTP 500 boom)", context: $context));

        // allow_local_fallback_on_node_failure=false + config false -> no SEARCH_RAG.
        $response = $this->fallbackProcessor($context, $dispatcher)
            ->process('next', 'sess-fb2', 1, ['allow_local_fallback_on_node_failure' => false]);

        $this->assertFalse($response->success);
        $this->assertStringContainsString("couldn't reach remote node", $response->message);
    }

    public function test_continue_node_failure_without_matching_substring_does_not_fall_back(): void
    {
        $context = new UnifiedActionContext('sess-fb3', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::CONTINUE_NODE)
            ->andReturn(AgentResponse::failure(message: 'some other error', context: $context));

        // Fallback enabled, but the required substring is absent -> no SEARCH_RAG dispatch.
        $response = $this->fallbackProcessor($context, $dispatcher)
            ->process('next', 'sess-fb3', 1, ['allow_local_fallback_on_node_failure' => true]);

        $this->assertFalse($response->success);
        $this->assertSame('some other error', $response->message);
    }

    public function test_continue_node_failure_with_matching_substring_and_fallback_enabled_fires_rag(): void
    {
        config()->set('ai-engine.nodes.routing.local_fallback_notice', 'Showing local results only.');

        $context = new UnifiedActionContext('sess-fb4', 1);
        $context->set('routed_to_node', ['node_slug' => 'billing']);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::CONTINUE_NODE)
            ->andReturn(AgentResponse::failure(message: "I couldn't reach remote node 'x' (HTTP 503).", context: $context));
        $dispatcher->shouldReceive('dispatch')->once()
            ->withArgs(fn (RoutingDecision $d) => $d->action === RoutingDecisionAction::SEARCH_RAG)
            ->andReturn(AgentResponse::conversational(message: 'Local invoices listed.', context: $context));

        $response = $this->fallbackProcessor($context, $dispatcher)
            ->process('next', 'sess-fb4', 1, ['allow_local_fallback_on_node_failure' => true]);

        $this->assertTrue($response->success);
        $this->assertStringContainsString('Showing local results only.', $response->message);
        $this->assertStringContainsString('Local invoices listed.', $response->message);
        $this->assertSame("Showing local results only.\n\nLocal invoices listed.", $response->message);

        $trace = $response->metadata['routing_trace'] ?? [];
        $actions = array_map(static fn (array $d): string => $d['action'], $trace);
        $this->assertContains('continue_node', $actions);
        $this->assertContains('search_rag', $actions);
        $continue = collect($trace)->firstWhere('action', 'continue_node');
        $this->assertTrue($continue['metadata']['failed'] ?? false);
    }

    // =====================================================================
    // AgentConversationService: memory retrieval error path (priority 5)
    // =====================================================================

    public function test_memory_retrieval_error_stamps_error_metadata_and_still_generates(): void
    {
        config()->set('ai-agent.conversation_memory.enabled', true);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('We decided to ship Friday.', 'openai', 'gpt-4o-mini')
        );

        $retriever = Mockery::mock(ConversationMemoryRetriever::class);
        $retriever->shouldReceive('retrieve')->once()->andThrow(new \RuntimeException('vector store down'));

        $promptBuilder = Mockery::mock(ConversationMemoryPromptBuilder::class);
        $promptBuilder->shouldNotReceive('build');

        $service = $this->makeConversationService($ai, $retriever, $promptBuilder);
        $context = new UnifiedActionContext('sess-mem-err', 7);

        $response = $service->executeConversational('what did we decide?', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('We decided to ship Friday.', $response->message);
        $this->assertSame('vector store down', $context->metadata['retrieved_memory_error']);
        $this->assertArrayNotHasKey('retrieved_memory', $context->metadata);
    }

    // =====================================================================
    // AgentConversationService: memory.enabled=false branch (priority 4)
    // =====================================================================

    public function test_memory_disabled_unsets_stale_memory_and_injects_conversation_summary(): void
    {
        config()->set('ai-agent.conversation_memory.enabled', false);

        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request) use (&$capturedPrompt): bool {
                $capturedPrompt = $request->prompt;

                return true;
            }))
            ->andReturn(AIResponse::success('Continuing.', 'openai', 'gpt-4o-mini'));

        $retriever = Mockery::mock(ConversationMemoryRetriever::class);
        $retriever->shouldNotReceive('retrieve');

        $service = $this->makeConversationService($ai, $retriever);
        $context = new UnifiedActionContext('sess-mem-off', 7, metadata: [
            'retrieved_memory' => 'STALE',
            'conversation_summary' => 'User is migrating to plan Pro.',
        ]);

        $response = $service->executeConversational('continue', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertTrue($response->success);
        // retrieved_memory was unset by the enabled=false short-circuit.
        $this->assertArrayNotHasKey('retrieved_memory', $context->metadata);
        // conversation_summary injected; no retrieved-memory block.
        $this->assertStringContainsString('Earlier conversation summary:', $capturedPrompt);
        $this->assertStringContainsString('User is migrating to plan Pro.', $capturedPrompt);
        $this->assertStringNotContainsString('STALE', $capturedPrompt);
    }

    public function test_conversation_summary_falls_back_to_options_when_absent_on_context(): void
    {
        config()->set('ai-agent.conversation_memory.enabled', false);

        $capturedPrompt = null;
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request) use (&$capturedPrompt): bool {
                $capturedPrompt = $request->prompt;

                return true;
            }))
            ->andReturn(AIResponse::success('ok', 'openai', 'gpt-4o-mini'));

        $service = $this->makeConversationService($ai, Mockery::mock(ConversationMemoryRetriever::class));
        $context = new UnifiedActionContext('sess-summary-opt', 7);

        $service->executeConversational('continue', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'conversation_summary' => 'Summary from options.',
        ]);

        $this->assertStringContainsString('Earlier conversation summary:', $capturedPrompt);
        $this->assertStringContainsString('Summary from options.', $capturedPrompt);
    }

    // =====================================================================
    // ConversationMemoryScopeResolver::fromContext (priority 4)
    // =====================================================================

    public function test_scope_resolver_explicit_scope_is_normalized(): void
    {
        $resolver = new ConversationMemoryScopeResolver();
        $context = new UnifiedActionContext('sess-scope-1', 7);

        $scope = $resolver->fromContext($context, [
            'scope_type' => 'Team Alpha!!',
            'scope_id' => '42',
        ]);

        $this->assertSame('team_alpha', $scope['scope_type']);
        $this->assertSame('42', $scope['scope_id']);
        $this->assertSame('sess-scope-1', $scope['session_id']);
    }

    public function test_scope_resolver_fallback_precedence_picks_workspace_first(): void
    {
        $resolver = new ConversationMemoryScopeResolver();
        $context = new UnifiedActionContext('sess-scope-2', 7, metadata: [
            'workspace_id' => 'w1',
            'tenant_id' => 't1',
        ]);

        $scope = $resolver->fromContext($context, []);

        $this->assertSame('workspace', $scope['scope_type']);
        $this->assertSame('w1', $scope['scope_id']);
        $this->assertSame('sess-scope-2', $scope['session_id']);
    }

    public function test_scope_resolver_defaults_to_global_when_nothing_matches(): void
    {
        $resolver = new ConversationMemoryScopeResolver();
        // userId null so the 'user' fallback field does not match.
        $context = new UnifiedActionContext('sess-scope-3', null);

        $scope = $resolver->fromContext($context, []);

        $this->assertSame('global', $scope['scope_type']);
        $this->assertNull($scope['scope_id']);
        $this->assertSame('sess-scope-3', $scope['session_id']);
    }

    public function test_scope_resolver_config_override_chooses_tenant_over_workspace(): void
    {
        config()->set('ai-agent.conversation_memory.scope.fallback_fields', ['tenant' => 'tenant_id']);

        $resolver = new ConversationMemoryScopeResolver();
        $context = new UnifiedActionContext('sess-scope-4', 7, metadata: [
            'workspace_id' => 'w1',
            'tenant_id' => 't1',
        ]);

        $scope = $resolver->fromContext($context, []);

        $this->assertSame('tenant', $scope['scope_type']);
        $this->assertSame('t1', $scope['scope_id']);
        $this->assertSame('sess-scope-4', $scope['session_id']);
    }

    // =====================================================================
    // Final-response streaming seam (priority 4)
    // =====================================================================

    public function test_streaming_success_reassembles_tokens(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldNotReceive('generate'); // streaming path: synchronous generate not used.

        $streaming = Mockery::mock(AgentFinalResponseStreamingService::class);
        $streaming->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function () {
                yield 'Hel';
                yield 'lo ';
                yield 'world';
            });

        $service = $this->makeConversationService($ai, null, null, $streaming);
        $context = new UnifiedActionContext('sess-stream-ok', 7);

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'agent_run_id' => 'run-1',
            'streaming' => true,
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('Hello world', $response->message);
    }

    public function test_streaming_failure_falls_back_to_synchronous_generate(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('sync answer', 'openai', 'gpt-4o-mini')
        );

        $streaming = Mockery::mock(AgentFinalResponseStreamingService::class);
        $streaming->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function () {
                yield 'partial';
                throw new \RuntimeException('stream broke mid-iteration');
            });

        $service = $this->makeConversationService($ai, null, null, $streaming);
        $context = new UnifiedActionContext('sess-stream-fail', 7);

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'agent_run_id' => 'run-2',
            'streaming' => true,
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('sync answer', $response->message);
    }

    public function test_streaming_failure_then_empty_sync_content_yields_failure(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('', 'openai', 'gpt-4o-mini')
        );

        $streaming = Mockery::mock(AgentFinalResponseStreamingService::class);
        $streaming->shouldReceive('stream')
            ->once()
            ->andReturnUsing(function () {
                if (false) {
                    yield '';
                }
                throw new \RuntimeException('stream broke immediately');
            });

        $service = $this->makeConversationService($ai, null, null, $streaming);
        $context = new UnifiedActionContext('sess-stream-empty', 7);

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'agent_run_id' => 'run-3',
            'streaming' => true,
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('AI engine returned an empty response.', $response->message);
    }

    public function test_streaming_not_used_when_agent_run_id_absent_control(): void
    {
        // Control: no agent_run_id -> shouldStreamFinalResponse false -> synchronous generate.
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')->once()->andReturn(
            AIResponse::success('sync only', 'openai', 'gpt-4o-mini')
        );

        $streaming = Mockery::mock(AgentFinalResponseStreamingService::class);
        $streaming->shouldNotReceive('stream');

        $service = $this->makeConversationService($ai, null, null, $streaming);
        $context = new UnifiedActionContext('sess-stream-control', 7);

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'streaming' => true, // present but no agent_run_id -> still synchronous
        ]);

        $this->assertTrue($response->success);
        $this->assertSame('sync only', $response->message);
    }

    // =====================================================================
    // Processor cap then ContextManager compaction ordering (priority 4)
    // =====================================================================

    public function test_processor_cap_and_compaction_preserve_newest_turns_in_order(): void
    {
        config()->set('ai-agent.context_compaction.max_messages', 6);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 4);
        config()->set('ai-agent.context_compaction.enabled', true);

        $context = new UnifiedActionContext('sess-cap', 7, metadata: [
            // active state -> ContextManager restoreMissingDurableState early-returns (no DB hit)
            'ai_native' => ['pending_tool' => ['name' => 'noop']],
        ]);
        $context->persist();

        // Real ContextManager (real compactor) returns this cached context.
        $realManager = new ContextManager();

        $captured = null;
        $native = Mockery::mock(AiNativeRuntime::class);
        $native->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (string $m, UnifiedActionContext $ctx, array $o) use (&$captured) {
                $captured = $ctx->conversationHistory;

                return AgentResponse::conversational(message: 'ok', context: $ctx);
            });

        $processor = new LaravelAgentProcessor(
            $realManager,
            $this->passthroughFinalizer(),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AgentExecutionDispatcher::class),
            $native
        );

        $tenTurns = [];
        for ($i = 1; $i <= 10; $i++) {
            $tenTurns[] = ['role' => 'user', 'content' => "t{$i}"];
        }

        $processor->process('newest', 'sess-cap', 7, [
            'conversation_history' => $tenTurns,
        ]);

        $contents = array_column($captured, 'content');

        // Newest turn present exactly once, last.
        $this->assertSame('newest', $contents[array_key_last($contents)]);
        $this->assertCount(1, array_filter($contents, fn ($c) => $c === 'newest'));

        // Oldest turns dropped; the most-recent supplied turns survive in order.
        $this->assertNotContains('t1', $contents);
        $this->assertContains('t10', $contents);

        // Order preserved (no reorder): the surviving supplied turns appear ascending,
        // immediately followed by 'newest'.
        $supplied = array_values(array_filter($contents, fn ($c) => $c !== 'newest'));
        $sorted = $supplied;
        usort($sorted, fn ($a, $b) => (int) substr($a, 1) <=> (int) substr($b, 1));
        $this->assertSame($sorted, $supplied);
    }
}
