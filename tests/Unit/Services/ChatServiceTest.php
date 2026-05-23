<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\Agent\ChatResponsePresentationService;
use LaravelAIEngine\Services\Agent\StructuredCollectionSessionService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class ChatServiceTest extends UnitTestCase
{
    public function test_process_message_handles_runtime_response_without_context(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::failure('Runtime is blocked by policy.'));

        $service = new ChatService(Mockery::mock(ConversationTranscriptService::class), $runtime);

        $response = $service->processMessage(
            message: 'hello',
            sessionId: 'chat-null-context',
            useMemory: false,
            userId: 9
        );

        $this->assertFalse($response->success);
        $this->assertSame('Runtime is blocked by policy.', $response->content);
        $this->assertFalse($response->metadata['runtime_active']);
        $this->assertTrue($response->metadata['runtime_completed']);
    }

    public function test_process_message_preserves_runtime_actions_and_input_metadata(): void
    {
        $context = new UnifiedActionContext('chat-inputs', 9);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::needsUserInput(
                message: 'Need approval.',
                actions: [['label' => 'Approve', 'value' => 'approve']],
                context: $context,
                nextStep: 'approve',
                requiredInputs: [['name' => 'approved', 'type' => 'boolean']]
            ));

        $service = new ChatService(Mockery::mock(ConversationTranscriptService::class), $runtime);

        $response = $service->processMessage(
            message: 'continue',
            sessionId: 'chat-inputs',
            useMemory: false,
            userId: 9
        );

        $this->assertTrue($response->success);
        $this->assertSame([['label' => 'Approve', 'value' => 'approve']], $response->actions);
        $this->assertTrue($response->metadata['needs_user_input']);
        $this->assertSame('approve', $response->metadata['next_step']);
        $this->assertSame([['name' => 'approved', 'type' => 'boolean']], $response->metadata['required_inputs']);
    }

    public function test_process_message_persists_transcript_turn_after_agent_response(): void
    {
        $transcripts = Mockery::mock(ConversationTranscriptService::class);
        $transcripts->shouldReceive('getOrCreateConversation')
            ->once()
            ->with('chat-persist', 9, 'openai', 'gpt-4o-mini')
            ->andReturn('conversation-123');
        $transcripts->shouldReceive('getConversationHistory')
            ->once()
            ->with('chat-persist', 50, 9)
            ->andReturn([]);
        $transcripts->shouldReceive('saveMessages')
            ->once()
            ->withArgs(function (string $conversationId, string $userMessage, AIResponse $response): bool {
                return $conversationId === 'conversation-123'
                    && $userMessage === 'remember this'
                    && $response->getContent() === 'Stored in the transcript.';
            });

        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::conversational(
                message: 'Stored in the transcript.',
                context: new UnifiedActionContext('chat-persist', 9)
            ));

        $service = new ChatService($transcripts, $runtime);

        $response = $service->processMessage(
            message: 'remember this',
            sessionId: 'chat-persist',
            useMemory: true,
            userId: 9
        );

        $this->assertTrue($response->success);
        $this->assertSame('conversation-123', $response->conversationId);
    }

    public function test_process_message_can_return_response_points_as_array_with_suggestions(): void
    {
        $transcripts = Mockery::mock(ConversationTranscriptService::class);
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldReceive('process')
            ->once()
            ->andReturn(AgentResponse::conversational(
                message: "I can help.\n- Create invoice for Acme\n- Send email reply",
                context: new UnifiedActionContext('chat-points', 9)
            ));

        $presentation = Mockery::mock(ChatResponsePresentationService::class);
        $presentation->shouldReceive('apply')
            ->once()
            ->withArgs(function (AIResponse $response, string $message, array $options): bool {
                return $response->getContent() === "I can help.\n- Create invoice for Acme\n- Send email reply"
                    && $message === 'use this email to create invoice'
                    && ($options['response_points_format'] ?? null) === 'array'
                    && ($options['response_suggestions'] ?? null) === true;
            })
            ->andReturnUsing(fn (AIResponse $response): AIResponse => $response->withMetadata([
                'response_points_format' => 'array',
                'response_points' => [
                    ['text' => 'Create invoice for Acme', 'marker' => '-', 'index' => 1],
                    ['text' => 'Send email reply', 'marker' => '-', 'index' => 2],
                ],
                'suggestions' => [
                    ['type' => 'action', 'id' => 'create_invoice', 'label' => 'Create invoice'],
                ],
            ]));

        $service = new ChatService($transcripts, $runtime, $presentation);

        $response = $service->processMessage(
            message: 'use this email to create invoice',
            sessionId: 'chat-points',
            useMemory: false,
            userId: 9,
            extraOptions: [
                'response_points_format' => 'array',
                'response_suggestions' => true,
            ]
        );

        $this->assertSame('array', $response->metadata['response_points_format']);
        $this->assertCount(2, $response->metadata['response_points']);
        $this->assertSame('create_invoice', $response->metadata['suggestions'][0]['id']);
    }

    public function test_process_message_short_circuits_to_structured_collection_when_enabled(): void
    {
        $runtime = Mockery::mock(AgentRuntimeContract::class);
        $runtime->shouldReceive('name')->andReturn('laravel');
        $runtime->shouldNotReceive('process');

        $collection = Mockery::mock(StructuredCollectionSessionService::class);
        $collection->shouldReceive('handle')
            ->once()
            ->withArgs(function (string $message, string $sessionId, mixed $userId, array $options): bool {
                return $message === 'collect this lead'
                    && $sessionId === 'collection-chat'
                    && $userId === 'user-1'
                    && ($options['collection']['name'] ?? null) === 'lead_capture';
            })
            ->andReturn(AIResponse::success(
                content: 'What email should I use?',
                engine: 'openai',
                model: 'gpt-4o-mini',
                metadata: ['collection' => ['status' => 'collecting']]
            ));

        $service = new ChatService(
            Mockery::mock(ConversationTranscriptService::class),
            $runtime,
            collectionSessions: $collection
        );

        $response = $service->processMessage(
            message: 'collect this lead',
            sessionId: 'collection-chat',
            useMemory: false,
            userId: 'user-1',
            extraOptions: [
                'collection' => [
                    'name' => 'lead_capture',
                    'schema' => [
                        'type' => 'object',
                        'required' => ['email'],
                        'properties' => ['email' => ['type' => 'string']],
                    ],
                ],
            ]
        );

        $this->assertSame('What email should I use?', $response->getContent());
        $this->assertSame('collecting', $response->metadata['collection']['status']);
    }
}
