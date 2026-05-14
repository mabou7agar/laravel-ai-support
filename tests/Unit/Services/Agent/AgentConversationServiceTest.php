<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
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
