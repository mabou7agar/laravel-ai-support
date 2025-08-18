<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Models\Message;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationManagerTest extends TestCase
{
    use RefreshDatabase;

    private ConversationManager $conversationManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversationManager = app(ConversationManager::class);
    }

    public function test_create_conversation()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            title: 'Test Conversation',
            systemPrompt: 'You are a helpful assistant.',
            settings: ['max_messages' => 100]
        );

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertEquals('user-123', $conversation->user_id);
        $this->assertEquals('Test Conversation', $conversation->title);
        $this->assertEquals('You are a helpful assistant.', $conversation->system_prompt);
        $this->assertEquals(100, $conversation->settings['max_messages']);
        $this->assertTrue($conversation->is_active);
        $this->assertNotNull($conversation->conversation_id);
    }

    public function test_get_conversation()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            title: 'Test Conversation'
        );

        $retrieved = $this->conversationManager->getConversation($conversation->conversation_id);

        $this->assertInstanceOf(Conversation::class, $retrieved);
        $this->assertEquals($conversation->conversation_id, $retrieved->conversation_id);
    }

    public function test_get_nonexistent_conversation_returns_null()
    {
        $result = $this->conversationManager->getConversation('nonexistent-id');
        $this->assertNull($result);
    }

    public function test_add_user_message()
    {
        $conversation = $this->conversationManager->createConversation(userId: 'user-123');

        $message = $this->conversationManager->addUserMessage(
            $conversation->conversation_id,
            'Hello, how are you?',
            ['metadata' => 'test']
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello, how are you?', $message->content);
        $this->assertEquals($conversation->conversation_id, $message->conversation_id);
        $this->assertEquals(['metadata' => 'test'], $message->metadata);
    }

    public function test_add_assistant_message()
    {
        $conversation = $this->conversationManager->createConversation(userId: 'user-123');

        $response = AIResponse::success(
            'Hello! I am doing well, thank you for asking.',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O,
            ['tokens' => 15]
        );

        $message = $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            $response->content,
            $response
        );

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('assistant', $message->role);
        $this->assertEquals('Hello! I am doing well, thank you for asking.', $message->content);
        $this->assertEquals($conversation->conversation_id, $message->conversation_id);
        $this->assertEquals('openai', $message->metadata['engine']);
        $this->assertEquals('gpt-4o', $message->metadata['model']);
    }

    public function test_get_conversation_context()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            systemPrompt: 'You are a helpful assistant.'
        );

        // Add some messages
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Hello');
        $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            'Hi there!',
            AIResponse::success('Hi there!', EngineEnum::OPENAI, EntityEnum::GPT_4O)
        );
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'How are you?');

        $context = $this->conversationManager->getConversationContext($conversation->conversation_id);

        $this->assertCount(4, $context); // system + 3 messages
        $this->assertEquals('system', $context[0]['role']);
        $this->assertEquals('You are a helpful assistant.', $context[0]['content']);
        $this->assertEquals('user', $context[1]['role']);
        $this->assertEquals('Hello', $context[1]['content']);
        $this->assertEquals('assistant', $context[2]['role']);
        $this->assertEquals('Hi there!', $context[2]['content']);
        $this->assertEquals('user', $context[3]['role']);
        $this->assertEquals('How are you?', $context[3]['content']);
    }

    public function test_enhance_request_with_context()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            systemPrompt: 'You are a helpful assistant.'
        );

        // Add a previous message
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Hello');
        $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            'Hi there!',
            AIResponse::success('Hi there!', EngineEnum::OPENAI, EntityEnum::GPT_4O)
        );

        $request = new AIRequest(
            prompt: 'How are you?',
            engine: EngineEnum::OPENAI,
            model: EntityEnum::GPT_4O,
            userId: 'user-123'
        );

        $enhancedRequest = $this->conversationManager->enhanceRequestWithContext(
            $request,
            $conversation->conversation_id
        );

        $this->assertInstanceOf(AIRequest::class, $enhancedRequest);
        $this->assertEquals($conversation->conversation_id, $enhancedRequest->parameters['conversation_id']);
        $this->assertArrayHasKey('messages', $enhancedRequest->parameters);
        $this->assertTrue($enhancedRequest->metadata['has_context']);
    }

    public function test_delete_conversation()
    {
        $conversation = $this->conversationManager->createConversation(userId: 'user-123');

        $result = $this->conversationManager->deleteConversation($conversation->conversation_id);

        $this->assertTrue($result);
        
        // Conversation should be marked as inactive
        $conversation->refresh();
        $this->assertFalse($conversation->is_active);
        
        // Should not be retrievable via getConversation
        $retrieved = $this->conversationManager->getConversation($conversation->conversation_id);
        $this->assertNull($retrieved);
    }

    public function test_clear_conversation_history()
    {
        $conversation = $this->conversationManager->createConversation(userId: 'user-123');

        // Add some messages
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Hello');
        $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            'Hi there!',
            AIResponse::success('Hi there!', EngineEnum::OPENAI, EntityEnum::GPT_4O)
        );

        $this->assertEquals(2, $conversation->messages()->count());

        $result = $this->conversationManager->clearConversationHistory($conversation->conversation_id);

        $this->assertTrue($result);
        $this->assertEquals(0, $conversation->messages()->count());
    }

    public function test_update_conversation_settings()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            settings: ['max_messages' => 50]
        );

        $result = $this->conversationManager->updateConversationSettings(
            $conversation->conversation_id,
            ['max_messages' => 100, 'temperature' => 0.8]
        );

        $this->assertTrue($result);
        
        $conversation->refresh();
        $this->assertEquals(100, $conversation->settings['max_messages']);
        $this->assertEquals(0.8, $conversation->settings['temperature']);
    }

    public function test_get_user_conversations()
    {
        // Create conversations for different users
        $conv1 = $this->conversationManager->createConversation(userId: 'user-123', title: 'Conv 1');
        $conv2 = $this->conversationManager->createConversation(userId: 'user-123', title: 'Conv 2');
        $conv3 = $this->conversationManager->createConversation(userId: 'user-456', title: 'Conv 3');

        $userConversations = $this->conversationManager->getUserConversations('user-123');

        $this->assertCount(2, $userConversations);
        $this->assertTrue($userConversations->contains('conversation_id', $conv1->conversation_id));
        $this->assertTrue($userConversations->contains('conversation_id', $conv2->conversation_id));
        $this->assertFalse($userConversations->contains('conversation_id', $conv3->conversation_id));
    }

    public function test_get_conversation_stats()
    {
        $conversation = $this->conversationManager->createConversation(userId: 'user-123');

        // Add some messages with usage stats
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Hello');
        
        $response = AIResponse::success('Hi there!', EngineEnum::OPENAI, EntityEnum::GPT_4O)
            ->withUsage(tokensUsed: 15, creditsUsed: 0.5);
        
        $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            $response->content,
            $response
        );

        $stats = $this->conversationManager->getConversationStats($conversation->conversation_id);

        $this->assertEquals(2, $stats['total_messages']);
        $this->assertEquals(1, $stats['user_messages']);
        $this->assertEquals(1, $stats['assistant_messages']);
        $this->assertArrayHasKey('created_at', $stats);
        $this->assertArrayHasKey('last_activity', $stats);
    }

    public function test_conversation_message_trimming()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            settings: ['max_messages' => 3]
        );

        // Add more messages than the limit
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Message 1');
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Message 2');
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Message 3');
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Message 4');
        $this->conversationManager->addUserMessage($conversation->conversation_id, 'Message 5');

        // Should only have the last 3 messages
        $this->assertEquals(3, $conversation->messages()->count());
        
        $messages = $conversation->messages()->orderBy('sent_at')->get();
        $this->assertEquals('Message 3', $messages[0]->content);
        $this->assertEquals('Message 4', $messages[1]->content);
        $this->assertEquals('Message 5', $messages[2]->content);
    }

    public function test_auto_title_generation()
    {
        $conversation = $this->conversationManager->createConversation(
            userId: 'user-123',
            settings: ['auto_title' => true]
        );

        $this->assertNull($conversation->title);

        // Add user and assistant messages
        $this->conversationManager->addUserMessage(
            $conversation->conversation_id,
            'What is the capital of France?'
        );
        
        $this->conversationManager->addAssistantMessage(
            $conversation->conversation_id,
            'The capital of France is Paris.',
            AIResponse::success('The capital of France is Paris.', EngineEnum::OPENAI, EntityEnum::GPT_4O)
        );

        $conversation->refresh();
        $this->assertNotNull($conversation->title);
        $this->assertStringContainsString('What is the capital of France', $conversation->title);
    }
}
