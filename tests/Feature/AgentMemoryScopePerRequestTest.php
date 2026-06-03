<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentMemoryScopePerRequestTest extends TestCase
{
    /**
     * Regression for bug H3: memoryScope() derived tenant/workspace from the CACHED
     * context.metadata, which is never cleared on a new request under the same session.
     * The current-request scope (passed explicitly via $options) must win so memories
     * are written under the correct scope_hash and never cross-read between workspaces.
     */
    public function test_current_request_scope_overrides_stale_cached_context_metadata(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 3);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.extract_on_compaction', true);
        config()->set('ai-agent.conversation_memory.extractor', 'ai');
        config()->set('ai-agent.conversation_memory.semantic.enabled', false);

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->twice()
            ->with(Mockery::type(AIRequest::class))
            ->andReturn(AIResponse::success(json_encode([[
                'namespace' => 'preferences',
                'key' => 'reply_language',
                'value' => 'Arabic',
                'summary' => 'User prefers Arabic replies.',
                'confidence' => 0.95,
            ]], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o'));

        $policy = app(ConversationMemoryPolicy::class);
        $repository = app(ConversationMemoryRepository::class);
        $extractor = new ConversationMemoryExtractor($policy, $ai);
        $compactor = new ConversationContextCompactor($policy, $extractor, $repository);

        $sessionId = 'shared-session-across-requests';

        // Request 1 arrives for workspace-a. The cached context carries workspace-a.
        $context = new UnifiedActionContext(
            sessionId: $sessionId,
            userId: '550e8400-e29b-41d4-a716-446655440000',
            conversationHistory: $this->history(),
            metadata: ['workspace_id' => 'workspace-a']
        );
        $compactor->compact($context, ['workspace_id' => 'workspace-a']);

        // Request 2 arrives on the SAME (still-cached) context whose metadata STILL says
        // workspace-a, but the live request is for workspace-b. The explicit option must win.
        $context->conversationHistory = $this->history();
        $compactor->compact($context, ['workspace_id' => 'workspace-b']);

        $workspaceA = $repository->search(new ConversationMemoryQuery(
            message: 'what language should you use?',
            scopeType: 'workspace',
            scopeId: 'workspace-a',
            sessionId: $sessionId,
        ));

        $workspaceB = $repository->search(new ConversationMemoryQuery(
            message: 'what language should you use?',
            scopeType: 'workspace',
            scopeId: 'workspace-b',
            sessionId: $sessionId,
        ));

        // Each workspace got exactly its own memory; the stale cached workspace_id did
        // not cause the second write to land under workspace-a.
        $this->assertCount(1, $workspaceA, 'workspace-a should only hold the first request memory');
        $this->assertCount(1, $workspaceB, 'workspace-b memory must be written under the current request scope');

        // The two scopes must hash differently and not cross-read each other.
        $this->assertSame('workspace-a', $workspaceA[0]->item->scopeId);
        $this->assertSame('workspace-b', $workspaceB[0]->item->scopeId);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function history(): array
    {
        return [
            ['role' => 'user', 'content' => 'أفضل الردود العربية'],
            ['role' => 'assistant', 'content' => 'تم.'],
            ['role' => 'user', 'content' => 'also remember we are testing scoped memory'],
            ['role' => 'assistant', 'content' => 'Noted.'],
        ];
    }
}
