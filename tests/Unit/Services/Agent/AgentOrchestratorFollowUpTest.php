<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentActionExecutionService;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\AgentExecutionFacade;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\AgentOrchestrator;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AgentOrchestratorFollowUpTest extends UnitTestCase
{
    public function test_follow_up_uses_selected_entity_context_without_relisting(): void
    {
        Cache::forget('agent_context:session-follow-up');

        $existingContext = new UnifiedActionContext(
            sessionId: 'session-follow-up',
            userId: null,
            conversationHistory: [
                [
                    'role' => 'assistant',
                    'content' => "1. Invoice INV-42\n2. Invoice INV-43",
                    'metadata' => [
                        'entity_ids' => [42, 43],
                        'entity_type' => 'invoice',
                    ],
                ],
            ],
            metadata: [
                'selected_entity_context' => [
                    'entity_id' => 42,
                    'entity_type' => 'invoice',
                    'model_class' => 'App\\Models\\Invoice',
                ],
            ]
        );
        $existingContext->persist();

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $ragAgent = Mockery::mock(AutonomousRAGAgent::class);
        $ragAgent->shouldReceive('process')
            ->once()
            ->withArgs(function (string $message, string $sessionId, $userId, array $history, array $options) {
                return $message === 'tell me more about it'
                    && $sessionId === 'session-follow-up'
                    && ($options['selected_entity']['entity_id'] ?? null) === 42
                    && ($options['selected_entity']['entity_type'] ?? null) === 'invoice';
            })
            ->andReturn([
                'success' => true,
                'response' => 'Invoice INV-42 is overdue by 3 days.',
                'metadata' => [],
                'tool' => 'invoice_lookup',
                'fast_path' => true,
            ]);
        $this->app->instance(AutonomousRAGAgent::class, $ragAgent);

        $execution = new AgentExecutionFacade(
            $this->app->make(AgentActionExecutionService::class),
            $this->app->make(AgentConversationService::class),
            Mockery::mock(NodeSessionManager::class),
            Mockery::mock(AutonomousCollectorRegistry::class),
            Mockery::mock(AutonomousCollectorHandler::class)
        );

        $orchestrator = new AgentOrchestrator(
            new ContextManager(),
            $intentRouter,
            new AgentPlanner(),
            new AgentResponseFinalizer(new ContextManager()),
            new AgentSelectionService(new AgentResponseFinalizer(new ContextManager())),
            $execution
        );

        $response = $orchestrator->process('tell me more about it', 'session-follow-up', null);

        $this->assertSame('Invoice INV-42 is overdue by 3 days.', $response->message);

        $savedContext = UnifiedActionContext::load('session-follow-up', null);
        $this->assertNotNull($savedContext);
        $this->assertSame(42, $savedContext->metadata['selected_entity_context']['entity_id']);
        $this->assertSame(
            'Invoice INV-42 is overdue by 3 days.',
            $savedContext->conversationHistory[array_key_last($savedContext->conversationHistory)]['content']
        );
    }
}
