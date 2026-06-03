<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\RoutingDecision;
use LaravelAIEngine\DTOs\RoutingDecisionAction;
use LaravelAIEngine\DTOs\RoutingDecisionSource;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentChatRunService;
use LaravelAIEngine\Services\Agent\AgentPlanner;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\AgentSelectionService;
use LaravelAIEngine\Services\Agent\ChatResponsePresentationService;
use LaravelAIEngine\Services\Agent\ContextManager;
use LaravelAIEngine\Services\Agent\Execution\AgentExecutionDispatcher;
use LaravelAIEngine\Services\Agent\IntentRouter;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Agent\Routing\RoutingPipeline;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentProcessor;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAgentRuntime;
use LaravelAIEngine\Services\Agent\StructuredCollectionSessionService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AgentChatFlowTraceTest extends TestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    public function test_sync_agent_chat_flow_traces_request_chat_runtime_routing_and_response(): void
    {
        $context = new UnifiedActionContext('trace-session', 'trace-user');
        $history = [
            ['role' => 'user', 'content' => 'Earlier question'],
            ['role' => 'assistant', 'content' => 'Earlier answer'],
        ];

        $transcripts = Mockery::mock(ConversationTranscriptService::class);
        $transcripts->shouldReceive('getOrCreateConversation')
            ->once()
            ->with('trace-session', 'trace-user', 'openai', 'gpt-4o-mini')
            ->andReturn('conversation-trace-1');
        $transcripts->shouldReceive('getConversationHistory')
            ->once()
            ->with('trace-session', 50, 'trace-user')
            ->andReturn($history);
        $transcripts->shouldReceive('saveMessages')
            ->once()
            ->withArgs(fn (string $conversationId, string $message, AIResponse $response): bool =>
                $conversationId === 'conversation-trace-1'
                && $message === 'Find Apollo invoice context'
                && $response->getContent() === 'Found 2 relevant invoice records.'
                && ($response->metadata['routing_decision']['action'] ?? null) === RoutingDecisionAction::SEARCH_RAG
            );

        $collections = Mockery::mock(StructuredCollectionSessionService::class);
        $collections->shouldReceive('handle')
            ->once()
            ->withArgs(fn (string $message, string $sessionId, mixed $userId, array $options): bool =>
                $message === 'Find Apollo invoice context'
                && $sessionId === 'trace-session'
                && $userId === 'trace-user'
                && ($options['use_rag'] ?? null) === true
                && ($options['force_rag'] ?? null) === true
            )
            ->andReturnNull();

        $contextManager = Mockery::mock(ContextManager::class);
        $contextManager->shouldReceive('getOrCreate')
            ->once()
            ->with('trace-session', 'trace-user')
            ->andReturn($context);

        $intentRouter = Mockery::mock(IntentRouter::class);
        $intentRouter->shouldNotReceive('route');

        $selection = Mockery::mock(AgentSelectionService::class);
        $nodeSessions = Mockery::mock(NodeSessionManager::class);

        $dispatcher = Mockery::mock(AgentExecutionDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (
                RoutingDecision $decision,
                string $message,
                UnifiedActionContext $dispatchedContext,
                array $options,
                mixed $reroute
            ) use ($context, $history): bool {
                return $message === 'Find Apollo invoice context'
                    && $dispatchedContext === $context
                    && $decision->action === RoutingDecisionAction::SEARCH_RAG
                    && $decision->source === RoutingDecisionSource::EXPLICIT
                    && ($decision->metadata['stage'] ?? null) === 'explicit_mode'
                    && ($options['engine'] ?? null) === 'openai'
                    && ($options['model'] ?? null) === 'gpt-4o-mini'
                    && ($options['use_memory'] ?? null) === true
                    && ($options['use_actions'] ?? null) === true
                    && ($options['use_rag'] ?? null) === true
                    && ($options['force_rag'] ?? null) === true
                    && ($options['rag_collections'] ?? null) === ['invoices', 'customers']
                    && ($options['search_instructions'] ?? null) === 'prefer invoice facts'
                    && ($options['conversation_history'] ?? null) === $history
                    && ($options['execution_mode_resolved'] ?? null) === 'sync'
                    && ($options['execution_mode_reason'] ?? null) === 'explicit_sync'
                    && ($options['decision_path'] ?? null) === 'forced_rag'
                    && ($options['decision_source'] ?? null) === RoutingDecisionSource::EXPLICIT
                    && is_callable($reroute);
            })
            ->andReturnUsing(fn (
                RoutingDecision $decision,
                string $message,
                UnifiedActionContext $dispatchedContext
            ): AgentResponse => AgentResponse::conversational(
                message: 'Found 2 relevant invoice records.',
                context: $dispatchedContext,
                metadata: [
                    'rag_enabled' => true,
                    'context_count' => 2,
                    'sources' => [
                        ['collection' => 'invoices', 'id' => 'inv-1'],
                        ['collection' => 'customers', 'id' => 'cust-1'],
                    ],
                ]
            ));

        $finalizer = Mockery::mock(AgentResponseFinalizer::class);
        $finalizer->shouldReceive('finalize')
            ->once()
            ->with($context, Mockery::type(AgentResponse::class), Mockery::type('array'))
            ->andReturnUsing(fn (UnifiedActionContext $finalContext, AgentResponse $response): AgentResponse => $response);

        $presentation = Mockery::mock(ChatResponsePresentationService::class);
        $presentation->shouldReceive('apply')
            ->once()
            ->withArgs(fn (AIResponse $response, string $message, array $options, ?UnifiedActionContext $responseContext): bool =>
                $message === 'Find Apollo invoice context'
                && $responseContext === $context
                && ($response->metadata['routing_decision']['action'] ?? null) === RoutingDecisionAction::SEARCH_RAG
                && ($options['response_points_format'] ?? null) === 'array'
            )
            ->andReturnUsing(fn (AIResponse $response): AIResponse => $response);

        $processor = new LaravelAgentProcessor(
            $contextManager,
            $intentRouter,
            new AgentPlanner(),
            $finalizer,
            $selection,
            $nodeSessions,
            executionDispatcher: $dispatcher,
            routingPipeline: $this->app->make(RoutingPipeline::class)
        );

        $this->app->instance(ChatService::class, new ChatService(
            $transcripts,
            new LaravelAgentRuntime($processor),
            $presentation,
            $collections
        ));

        $runs = Mockery::mock(AgentChatRunService::class);
        $runs->shouldNotReceive('start');
        $this->app->instance(AgentChatRunService::class, $runs);

        $this->postJson('/api/v1/agent/chat', [
            'message' => 'Find Apollo invoice context',
            'session_id' => 'trace-session',
            'user_id' => 'trace-user',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'memory' => true,
            'actions' => true,
            'use_rag' => true,
            'force_rag' => true,
            'rag_collections' => ['invoices', 'customers'],
            'search_instructions' => 'prefer invoice facts',
            'response_points_format' => 'array',
            'execution_mode' => 'sync',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.response', 'Found 2 relevant invoice records.')
            ->assertJsonPath('data.session_id', 'trace-session')
            ->assertJsonPath('data.execution_mode', 'sync')
            ->assertJsonPath('data.execution_mode_reason', 'explicit_sync')
            ->assertJsonPath('data.rag_enabled', true)
            ->assertJsonPath('data.context_count', 2)
            ->assertJsonPath('data.sources.0.collection', 'invoices')
            ->assertJsonPath('data.metadata.agent_runtime', 'laravel')
            ->assertJsonPath('data.metadata.routing_decision.action', RoutingDecisionAction::SEARCH_RAG)
            ->assertJsonPath('data.metadata.routing_decision.source', RoutingDecisionSource::EXPLICIT)
            ->assertJsonPath('data.metadata.routing_trace.0.action', RoutingDecisionAction::ABSTAIN)
            ->assertJsonPath('data.metadata.routing_trace.0.source', 'active_run_continuation')
            ->assertJsonPath('data.metadata.routing_trace.1.action', RoutingDecisionAction::SEARCH_RAG)
            ->assertJsonPath('data.metadata.routing_trace.1.metadata.stage', 'explicit_mode')
            ->assertJsonPath('data.metadata.route_explanation.action', RoutingDecisionAction::SEARCH_RAG)
            ->assertJsonPath('data.metadata.route_explanation.decision_path', 'forced_rag')
            ->assertJsonPath('data.metadata.route_explanation.skipped_stages.0.stage', 'active_run_continuation');

        $this->assertSame($history[0], $context->conversationHistory[0]);
        $this->assertSame('Find Apollo invoice context', $context->conversationHistory[2]['content']);
    }
}
