<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * END-TO-END multi-turn memory flow exercised at the CHAT level (ChatService::processMessage).
 *
 * Complements tests/Feature/AgentConversationMemoryEndToEndTest.php (which drives the
 * service-level AgentConversationService) by proving that, when memory is enabled, sequential
 * turns on the same session:
 *   - persist each turn's transcript (user + assistant) across calls, and
 *   - hydrate that growing transcript into the conversation_history options handed to the agent
 *     runtime, which embeds it in the prompt sent to the (mocked) AIEngineService for later turns.
 *
 * Mirrors the runtime-double / mocked-AI idiom from tests/Unit/Services/ChatServiceTest.php.
 */
class FlowMemChatMemoryEndToEndTest extends UnitTestCase
{
    public function test_three_chat_turns_hydrate_prior_history_into_later_prompts_and_persist_transcript(): void
    {
        // In-memory transcript that genuinely persists across turns so later turns can hydrate it.
        $transcripts = new FlowMemInMemoryTranscriptService();

        // Mocked AIEngineService: each generate() captures the prompt it was handed and returns
        // a deterministic reply. The runtime double routes every turn through this engine.
        $prompts = [];
        $replies = [
            'Nice to meet you, Sara.',
            'You told me your name is Sara.',
            'Your favorite color is teal, Sara.',
        ];
        $callIndex = 0;

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->times(3)
            ->with(Mockery::type(AIRequest::class))
            ->andReturnUsing(function (AIRequest $request) use (&$prompts, &$callIndex, $replies): AIResponse {
                $prompts[] = $request->getPrompt();
                $reply = $replies[$callIndex] ?? 'ok';
                $callIndex++;

                return AIResponse::success($reply, 'openai', 'gpt-4o-mini');
            });

        $runtime = new FlowMemHistoryAwareRuntime($ai);

        $service = new ChatService($transcripts, $runtime);

        $sessionId = 'flowmem-memory-session';
        $userId = 7;

        // --- Turn 1 ---
        $first = $service->processMessage(
            message: 'Hi, my name is Sara.',
            sessionId: $sessionId,
            useMemory: true,
            userId: $userId
        );

        $this->assertTrue($first->success);
        $this->assertSame('Nice to meet you, Sara.', $first->getContent());
        $this->assertTrue($first->metadata['memory_enabled']);
        $this->assertTrue($first->metadata['transcript_persisted']);
        // First turn starts with an empty transcript.
        $this->assertSame(0, $first->metadata['conversation_history_count']);

        // --- Turn 2 ---
        $second = $service->processMessage(
            message: 'What is my name?',
            sessionId: $sessionId,
            useMemory: true,
            userId: $userId
        );

        $this->assertTrue($second->success);
        $this->assertSame('You told me your name is Sara.', $second->getContent());
        // Turn 1 produced one user + one assistant message that turn 2 hydrates.
        $this->assertSame(2, $second->metadata['conversation_history_count']);
        $this->assertTrue($second->metadata['transcript_persisted']);

        // --- Turn 3 ---
        $third = $service->processMessage(
            message: 'What is my favorite color? (teal)',
            sessionId: $sessionId,
            useMemory: true,
            userId: $userId
        );

        $this->assertTrue($third->success);
        $this->assertSame('Your favorite color is teal, Sara.', $third->getContent());
        // Two prior turns => four messages hydrated into turn 3.
        $this->assertSame(4, $third->metadata['conversation_history_count']);

        // Three prompts captured, one per turn.
        $this->assertCount(3, $prompts);

        // Turn 1 prompt carries no prior context (empty history section).
        $this->assertStringContainsString("Conversation so far:\n(none)", $prompts[0]);
        $this->assertStringNotContainsString('Nice to meet you', $prompts[0]);
        $this->assertStringContainsString('Hi, my name is Sara.', $prompts[0]);

        // Turn 2 prompt carries turn 1's user message AND assistant reply (history hydrated).
        $this->assertStringContainsString('Hi, my name is Sara.', $prompts[1]);
        $this->assertStringContainsString('Nice to meet you, Sara.', $prompts[1]);
        $this->assertStringContainsString('What is my name?', $prompts[1]);

        // Turn 3 prompt accumulates BOTH earlier turns plus the new message.
        $this->assertStringContainsString('Hi, my name is Sara.', $prompts[2]);
        $this->assertStringContainsString('Nice to meet you, Sara.', $prompts[2]);
        $this->assertStringContainsString('What is my name?', $prompts[2]);
        $this->assertStringContainsString('You told me your name is Sara.', $prompts[2]);
        $this->assertStringContainsString('What is my favorite color? (teal)', $prompts[2]);

        // The transcript store itself accumulated all three turns (6 messages).
        $this->assertCount(6, $transcripts->messagesForSession($sessionId, $userId));
    }

    public function test_memory_disabled_does_not_hydrate_prior_turns_into_later_prompt(): void
    {
        $transcripts = new FlowMemInMemoryTranscriptService();

        $prompts = [];
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->with(Mockery::type(AIRequest::class))
            ->andReturnUsing(function (AIRequest $request) use (&$prompts): AIResponse {
                $prompts[] = $request->getPrompt();

                return AIResponse::success('acknowledged', 'openai', 'gpt-4o-mini');
            });

        $runtime = new FlowMemHistoryAwareRuntime($ai);
        $service = new ChatService($transcripts, $runtime);

        $sessionId = 'flowmem-memory-off-session';

        $service->processMessage(
            message: 'Remember the secret code is 4242.',
            sessionId: $sessionId,
            useMemory: false,
            userId: 7
        );

        $second = $service->processMessage(
            message: 'What was the secret code?',
            sessionId: $sessionId,
            useMemory: false,
            userId: 7
        );

        // With memory disabled, ChatService never loads/persists transcript, so the second
        // prompt must NOT contain anything from the first turn.
        $this->assertFalse($second->metadata['memory_enabled']);
        $this->assertSame(0, $second->metadata['conversation_history_count']);
        $this->assertNull($second->metadata['conversation_id']);
        $this->assertCount(2, $prompts);
        $this->assertStringNotContainsString('4242', $prompts[1]);
        $this->assertStringNotContainsString('Remember the secret code', $prompts[1]);

        // Nothing was persisted to the transcript store.
        $this->assertSame([], $transcripts->messagesForSession($sessionId, 7));
    }
}

/**
 * Minimal in-memory ConversationTranscriptService that actually persists turns across calls.
 * Keyed by session + user so repeated processMessage() calls hydrate the growing history,
 * exactly like the real DB-backed service does between requests.
 */
class FlowMemInMemoryTranscriptService extends ConversationTranscriptService
{
    /** @var array<string, string> session-key => conversation id */
    private array $conversations = [];

    /** @var array<string, array<int, array{role: string, content: string}>> conversation id => messages */
    private array $store = [];

    public function __construct()
    {
        // Intentionally bypass parent constructor: no ConversationManager needed for the fake.
    }

    public function getOrCreateConversation(
        string $sessionId,
        string|int|null $userId = null,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini'
    ): string {
        $key = $this->key($sessionId, $userId);

        if (!isset($this->conversations[$key])) {
            $conversationId = 'flowmem-conv-' . count($this->conversations);
            $this->conversations[$key] = $conversationId;
            $this->store[$conversationId] = [];
        }

        return $this->conversations[$key];
    }

    public function getConversationHistory(string $sessionId, int $limit = 50, string|int|null $userId = null): array
    {
        $key = $this->key($sessionId, $userId);
        $conversationId = $this->conversations[$key] ?? null;

        if ($conversationId === null) {
            return [];
        }

        return array_slice($this->store[$conversationId] ?? [], -$limit);
    }

    public function saveMessages(string $conversationId, string $userMessage, AIResponse $aiResponse): void
    {
        $this->store[$conversationId][] = ['role' => 'user', 'content' => $userMessage];
        $this->store[$conversationId][] = ['role' => 'assistant', 'content' => $aiResponse->getContent()];
    }

    /**
     * Test accessor: every persisted message for a session.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function messagesForSession(string $sessionId, string|int|null $userId = null): array
    {
        $key = $this->key($sessionId, $userId);
        $conversationId = $this->conversations[$key] ?? null;

        if ($conversationId === null) {
            return [];
        }

        return $this->store[$conversationId] ?? [];
    }

    private function key(string $sessionId, string|int|null $userId): string
    {
        return $sessionId . '|' . ($userId === null ? 'null' : (string) $userId);
    }
}

/**
 * Agent runtime double that builds a prompt from the hydrated conversation_history option plus
 * the current message and routes it through the (mocked) AIEngineService, then returns the
 * engine's reply as a conversational AgentResponse. This is what carries earlier-turn context
 * "into the prompt of a later turn".
 */
class FlowMemHistoryAwareRuntime implements AgentRuntimeContract
{
    public function __construct(private AIEngineService $ai)
    {
    }

    public function name(): string
    {
        return 'flowmem';
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return new AgentRuntimeCapabilities(metadata: ['runtime' => 'flowmem']);
    }

    public function process(string $message, string $sessionId, mixed $userId = null, array $options = []): AgentResponse
    {
        $history = $options['conversation_history'] ?? [];

        $lines = [];
        foreach ($history as $entry) {
            $role = $entry['role'] ?? 'user';
            $content = $entry['content'] ?? '';
            $lines[] = strtoupper($role) . ': ' . $content;
        }

        $prompt = "Conversation so far:\n"
            . (empty($lines) ? '(none)' : implode("\n", $lines))
            . "\n\nUSER: " . $message;

        $request = new AIRequest(
            prompt: $prompt,
            engine: 'openai',
            model: 'gpt-4o-mini'
        );

        $response = $this->ai->generate($request);

        return AgentResponse::conversational(
            message: $response->getContent(),
            context: new UnifiedActionContext($sessionId, $userId)
        );
    }
}
