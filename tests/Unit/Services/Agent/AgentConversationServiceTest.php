<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Tests\Models\User;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentConversationServiceTest extends UnitTestCase
{
    public function test_execute_conversational_injects_authenticated_user_profile_context(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $rag = Mockery::mock(AutonomousRAGAgent::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);

        $user = new User();
        $user->forceFill([
            'id' => 42,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'secret-password',
        ]);
        auth()->setUser($user);

        $ai->shouldReceive('generate')
            ->once()
            ->with(Mockery::on(function ($request) {
                return str_contains($request->prompt, '"name":"John Doe"')
                    && str_contains($request->prompt, '"email":"john@example.com"')
                    && !str_contains($request->prompt, 'secret-password');
            }))
            ->andReturn(AIResponse::success('Your name is John Doe.', 'openai', 'gpt-4o-mini'));

        $service = new AgentConversationService($ai, $rag, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-user', '42');

        $response = $service->executeConversational('what is my name', $context, [
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->assertSame('Your name is John Doe.', $response->message);
    }

    public function test_execute_search_rag_maps_remote_failure_to_user_friendly_message(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $rag = Mockery::mock(AutonomousRAGAgent::class);
        $selectedEntity = Mockery::mock(SelectedEntityContextService::class);
        $selection = Mockery::mock(AgentSelectionService::class);

        $selectedEntity->shouldReceive('getFromContext')->once()->andReturn(null);
        $rag->shouldReceive('process')
            ->once()
            ->andReturn([
                'success' => false,
                'error' => "I couldn't reach remote node 'billing' (HTTP 500).",
            ]);

        $service = new AgentConversationService($ai, $rag, $selectedEntity, $selection);
        $context = new UnifiedActionContext('session-err', 1);

        $response = $service->executeSearchRAG('list invoices', $context, [], fn () => null);

        $this->assertTrue($response->success);
        $this->assertSame(
            "I couldn't reach the remote node right now. Please try again in a moment.",
            $response->message
        );
    }
}
