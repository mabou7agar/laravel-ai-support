<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\DTOs\ConversationMemoryQuery;
use LaravelAIEngine\DTOs\ConversationMemoryResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPromptBuilder;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryRetriever;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\RAGDecisionEngine;
use LaravelAIEngine\Services\RAG\RAGExecutionRouter;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentConversationServiceTest extends UnitTestCase
{
    public function test_execute_conversational_injects_authenticated_user_profile_context(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);
        $ragRouter->shouldNotReceive('execute');

        $user = new User();
        $user->forceFill([
            'id' => 42,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret-password',
        ]);
        $user->setRelation('loaded_relation', collect([
            ['name' => 'Large related record', 'body' => str_repeat('x', 10000)],
        ]));
        auth()->setUser($user);

        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request) {
                return str_contains($request->prompt, '"name":"John Doe"')
                    && str_contains($request->prompt, '"email":"john@example.com"')
                    && !str_contains($request->prompt, 'secret-password')
                    && !str_contains($request->prompt, 'Large related record')
                    && strlen($request->prompt) < 3000;
            }))
            ->andReturn(AIResponse::success('Your name is John Doe.', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-user', '42');

        $response = $service->executeConversational('what is my name', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertSame('Your name is John Doe.', $response->message);
    }

    public function test_execute_conversational_uses_configured_recent_history_window(): void
    {
        config()->set('ai-engine.conversation_history.recent_messages', 10);

        $ai = Mockery::mock(AIEngineService::class);
        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);
        $ragRouter->shouldNotReceive('execute');

        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request) {
                return str_contains($request->prompt, 'validating a Laravel AI package')
                    && str_contains($request->prompt, 'list invoices or business records')
                    && str_contains($request->prompt, 'what was the first thing');
            }))
            ->andReturn(AIResponse::success('You asked me to remember that you are validating a Laravel AI package.', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-long-chat', null, conversationHistory: [
            ['role' => 'user', 'content' => 'hello, remember that I am validating a Laravel AI package'],
            ['role' => 'assistant', 'content' => 'I will keep that in mind for this conversation.'],
            ['role' => 'user', 'content' => 'list invoices or business records if any are available'],
            ['role' => 'assistant', 'content' => 'No retrieved records are available.'],
            ['role' => 'user', 'content' => 'what was the first thing I asked you to remember?'],
        ]);

        $response = $service->executeConversational('what was the first thing I asked you to remember?', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertSame(
            'You asked me to remember that you are validating a Laravel AI package.',
            $response->message
        );
    }

    public function test_execute_conversational_injects_retrieved_scoped_memory(): void
    {
        config()->set('ai-agent.conversation_memory.enabled', true);
        config()->set('ai-agent.conversation_memory.scopes.tenant_key', 'tenant_id');
        config()->set('ai-agent.conversation_memory.scopes.workspace_key', 'workspace_id');

        $ai = Mockery::mock(AIEngineService::class);
        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);
        $retriever = Mockery::mock(ConversationMemoryRetriever::class);
        $promptBuilder = Mockery::mock(ConversationMemoryPromptBuilder::class);
        $ragRouter->shouldNotReceive('execute');

        $results = [
            new ConversationMemoryResult(
                item: ConversationMemoryItem::fromArray([
                    'namespace' => 'preferences',
                    'key' => 'reply_language',
                    'summary' => 'User prefers Arabic replies.',
                ]),
                score: 0.91
            ),
        ];

        $retriever->shouldReceive('retrieve')
            ->once()
            ->with(Mockery::on(function (ConversationMemoryQuery $query): bool {
                return $query->message === 'which language should you use?'
                    && $query->userId === '42'
                    && $query->tenantId === 'tenant-a'
                    && $query->workspaceId === 'workspace-a'
                    && $query->sessionId === 'session-memory';
            }))
            ->andReturn($results);

        $promptBuilder->shouldReceive('build')
            ->once()
            ->with($results)
            ->andReturn("Relevant remembered context:\n- [preferences] User prefers Arabic replies.");

        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request): bool {
                return str_contains($request->prompt, 'Relevant remembered context')
                    && str_contains($request->prompt, 'User prefers Arabic replies.');
            }))
            ->andReturn(AIResponse::success('I should reply in Arabic.', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService(
            $ai,
            $ragRouter,
            $selectedEntity,
            $selection,
            null,
            null,
            $retriever,
            $promptBuilder
        );
        $context = new UnifiedActionContext('session-memory', '42', metadata: [
            'tenant_id' => 'tenant-a',
            'workspace_id' => 'workspace-a',
        ]);

        $response = $service->executeConversational('which language should you use?', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertSame('I should reply in Arabic.', $response->message);
        $this->assertSame("Relevant remembered context:\n- [preferences] User prefers Arabic replies.", $context->metadata['retrieved_memory']);
    }

    public function test_execute_conversational_returns_failure_when_ai_engine_fails(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);
        $ragRouter->shouldNotReceive('execute');

        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::error('Provider authentication failed.', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-ai-error', '42');

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('Provider authentication failed.', $response->message);
    }

    public function test_execute_conversational_returns_failure_when_ai_engine_returns_empty_content(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ragRouter = Mockery::mock(RAGExecutionRouter::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);
        $ragRouter->shouldNotReceive('execute');

        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success('', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-empty-ai', '42');

        $response = $service->executeConversational('hi', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertFalse($response->success);
        $this->assertSame('AI engine returned an empty response.', $response->message);
    }

    public function test_execute_structured_rag_maps_remote_failure_to_user_friendly_message(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $rag = Mockery::mock(RAGDecisionEngine::class);
        $ragRouter = new RAGExecutionRouter($rag);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);

        $selectedEntity->shouldReceive('getFromContext')->once()->andReturn(null);
        $rag->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => "I couldn't reach remote node 'billing' (HTTP 500).",
            ]);

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-err', 1);

        $response = $service->executeSearchRAG('list invoices', $context, [
            'preclassified_route_mode' => 'structured_query',
        ], fn () => null);

        $this->assertTrue($response->success);
        $this->assertSame(
            "I couldn't reach the remote node right now. Please try again in a moment.",
            $response->message
        );
    }

    public function test_execute_search_rag_uses_rag_pipeline_without_decision_engine(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $rag = Mockery::mock(RAGDecisionEngine::class);
        $rag->shouldNotReceive('process');
        $pipeline = Mockery::mock(RAGPipelineContract::class);
        $ragRouter = new RAGExecutionRouter($rag, $pipeline);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);

        $selectedEntity->shouldReceive('getFromContext')->once()->andReturn(null);
        $pipeline->shouldReceive('answer')
            ->once()
            ->withArgs(function (string $query, array $options, int|string|null $userId): bool {
                return $query === 'find project launch notes'
                    && ($options['session_id'] ?? null) === 'session-rag-v2'
                    && ($options['preclassified_route_mode'] ?? null) === 'semantic_retrieval'
                    && $userId === 9;
            })
            ->andReturn(AgentResponse::success(
                message: 'Launch notes came from the project document.',
                data: ['rag_context' => ['sources' => []]]
            ));

        $service = new AgentConversationService($ai, $ragRouter, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-rag-v2', 9);

        $response = $service->executeSearchRAG('find project launch notes', $context, [
            'preclassified_route_mode' => 'semantic_retrieval',
        ], fn () => null);

        $this->assertTrue($response->success);
        $this->assertSame('Launch notes came from the project document.', $response->message);
        $this->assertTrue($response->metadata['rag_pipeline']);
        $this->assertSame('rag_pipeline', $context->metadata['tool_used']);
    }
}
