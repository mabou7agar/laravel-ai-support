<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ConversationContextCompactor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGExecutionRouter;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentConversationMemoryEndToEndTest extends TestCase
{
    public function test_real_conversation_compacts_extracts_scoped_memory_and_uses_it_in_next_chat_prompt(): void
    {
        config()->set('ai-agent.context_compaction.enabled', true);
        config()->set('ai-agent.context_compaction.max_messages', 3);
        config()->set('ai-agent.context_compaction.keep_recent_messages', 2);
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.extract_on_compaction', true);
        config()->set('ai-agent.conversation_memory.extractor', 'ai');
        config()->set('ai-agent.conversation_memory.semantic.enabled', false);
        config()->set('ai-agent.conversation_memory.scopes.tenant_key', 'tenant_id');
        config()->set('ai-agent.conversation_memory.scopes.workspace_key', 'workspace_id');

        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(function (AIRequest $request): bool {
                return str_contains($request->getPrompt(), 'Compacted conversation JSON')
                    && str_contains($request->getPrompt(), 'أفضل الردود العربية');
            }))
            ->andReturn(AIResponse::success(json_encode([[
                'namespace' => 'preferences',
                'key' => 'reply_language',
                'value' => 'Arabic',
                'summary' => 'User prefers Arabic replies in this workspace.',
                'confidence' => 0.95,
            ]], JSON_THROW_ON_ERROR), 'openai', 'gpt-4o'));

        $ai->shouldReceive('generate')
            ->once()
            ->ordered()
            ->with(Mockery::on(function (AIRequest $request): bool {
                return str_contains($request->getPrompt(), 'Relevant remembered context')
                    && str_contains($request->getPrompt(), 'User prefers Arabic replies in this workspace.')
                    && str_contains($request->getPrompt(), 'what language should you use in this workspace?');
            }))
            ->andReturn(AIResponse::success('I should reply in Arabic for this workspace.', 'openai', 'gpt-4o'));

        $policy = app(ConversationMemoryPolicy::class);
        $repository = app(ConversationMemoryRepository::class);
        $extractor = new ConversationMemoryExtractor($policy, $ai);
        $compactor = new ConversationContextCompactor($policy, $extractor, $repository);

        $context = new UnifiedActionContext(
            sessionId: 'real-memory-conversation',
            userId: '550e8400-e29b-41d4-a716-446655440000',
            conversationHistory: [
                ['role' => 'user', 'content' => 'أفضل الردود العربية في مساحة العمل هذه'],
                ['role' => 'assistant', 'content' => 'تم. سأراعي ذلك.'],
                ['role' => 'user', 'content' => 'also remember we are testing package memory'],
                ['role' => 'assistant', 'content' => 'Noted.'],
            ],
            metadata: [
                'tenant_id' => 'tenant-a',
                'workspace_id' => 'workspace-a',
            ]
        );

        $compactor->compact($context);

        $matchingResults = $repository->search(new ConversationMemoryQuery(
            message: 'what language should you use in this workspace?',
            userId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-a',
            workspaceId: 'workspace-a',
            sessionId: 'real-memory-conversation',
        ));

        $otherWorkspaceResults = $repository->search(new ConversationMemoryQuery(
            message: 'what language should you use in this workspace?',
            userId: '550e8400-e29b-41d4-a716-446655440000',
            tenantId: 'tenant-a',
            workspaceId: 'workspace-b',
            sessionId: 'real-memory-conversation',
        ));

        $this->assertSame(1, $context->metadata['conversation_memory_extracted']);
        $this->assertCount(1, $matchingResults);
        $this->assertSame('reply_language', $matchingResults[0]->item->key);
        $this->assertSame([], $otherWorkspaceResults);

        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $ragRouter->shouldNotReceive('execute');

        $service = new AgentConversationService(
            ai: $ai,
            ragExecutionRouter: $ragRouter,
            selectedEntityContext: Mockery::mock(SelectedEntityContextService::class),
            selectionService: Mockery::mock(AgentSelectionService::class),
            localeResources: null,
            routingContextResolver: null,
            memoryRetriever: new ConversationMemoryRetriever($repository, $policy),
            memoryPromptBuilder: new ConversationMemoryPromptBuilder($policy)
        );

        $response = $service->executeConversational(
            'what language should you use in this workspace?',
            $context,
            ['engine' => 'openai', 'model' => 'gpt-4o']
        );

        $this->assertTrue($response->success);
        $this->assertSame('I should reply in Arabic for this workspace.', $response->message);
        $this->assertStringContainsString('User prefers Arabic replies in this workspace.', $context->metadata['retrieved_memory']);
    }
}
