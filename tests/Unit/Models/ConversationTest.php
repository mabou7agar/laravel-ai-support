<?php

namespace LaravelAIEngine\Tests\Unit\Models;

use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Models\Message;
use LaravelAIEngine\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_creation()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'title' => 'Test Conversation',
            'system_prompt' => 'You are a helpful assistant.',
            'settings' => ['max_messages' => 100],
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Conversation::class, $conversation);
        $this->assertEquals('conv-123', $conversation->conversation_id);
        $this->assertEquals('user-456', $conversation->user_id);
        $this->assertEquals('Test Conversation', $conversation->title);
        $this->assertEquals('You are a helpful assistant.', $conversation->system_prompt);
        $this->assertEquals(['max_messages' => 100], $conversation->settings);
        $this->assertTrue($conversation->is_active);
    }

    public function test_conversation_has_messages_relationship()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
        ]);

        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $conversation->messages);
        $this->assertCount(1, $conversation->messages);
        $this->assertEquals($message->id, $conversation->messages->first()->id);
    }

    public function test_conversation_scope_active()
    {
        $activeConv = Conversation::create([
            'conversation_id' => 'conv-active',
            'user_id' => 'user-456',
            'is_active' => true,
        ]);

        $inactiveConv = Conversation::create([
            'conversation_id' => 'conv-inactive',
            'user_id' => 'user-456',
            'is_active' => false,
        ]);

        $activeConversations = Conversation::active()->get();

        $this->assertCount(1, $activeConversations);
        $this->assertEquals($activeConv->id, $activeConversations->first()->id);
    }

    public function test_conversation_scope_for_user()
    {
        $user1Conv = Conversation::create([
            'conversation_id' => 'conv-user1',
            'user_id' => 'user-1',
        ]);

        $user2Conv = Conversation::create([
            'conversation_id' => 'conv-user2',
            'user_id' => 'user-2',
        ]);

        $user1Conversations = Conversation::forUser('user-1')->get();

        $this->assertCount(1, $user1Conversations);
        $this->assertEquals($user1Conv->id, $user1Conversations->first()->id);
    }

    public function test_conversation_trim_messages()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'settings' => ['max_messages' => 3],
        ]);

        // Create 5 messages
        for ($i = 1; $i <= 5; $i++) {
            Message::create([
                'conversation_id' => 'conv-123',
                'role' => 'user',
                'content' => "Message $i",
                'sent_at' => now()->addSeconds($i),
            ]);
        }

        $this->assertEquals(5, $conversation->messages()->count());

        $conversation->trimMessages();

        $this->assertEquals(3, $conversation->messages()->count());
        
        // Should keep the latest 3 messages
        $messages = $conversation->messages()->orderBy('sent_at')->get();
        $this->assertEquals('Message 3', $messages[0]->content);
        $this->assertEquals('Message 4', $messages[1]->content);
        $this->assertEquals('Message 5', $messages[2]->content);
    }

    public function test_conversation_get_context()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'system_prompt' => 'You are a helpful assistant.',
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
            'sent_at' => now(),
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Hi there!',
            'sent_at' => now()->addSecond(),
        ]);

        $context = $conversation->getContext();

        $this->assertCount(3, $context); // system + 2 messages
        $this->assertEquals('system', $context[0]['role']);
        $this->assertEquals('You are a helpful assistant.', $context[0]['content']);
        $this->assertEquals('user', $context[1]['role']);
        $this->assertEquals('Hello', $context[1]['content']);
        $this->assertEquals('assistant', $context[2]['role']);
        $this->assertEquals('Hi there!', $context[2]['content']);
    }

    public function test_conversation_get_context_without_system_prompt()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
            'sent_at' => now(),
        ]);

        $context = $conversation->getContext();

        $this->assertCount(1, $context); // only 1 message
        $this->assertEquals('user', $context[0]['role']);
        $this->assertEquals('Hello', $context[0]['content']);
    }

    public function test_conversation_update_last_activity()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'last_activity_at' => now()->subHour(),
        ]);

        $oldActivity = $conversation->last_activity_at;

        $conversation->updateLastActivity();

        $this->assertNotEquals($oldActivity, $conversation->fresh()->last_activity_at);
        $this->assertTrue($conversation->fresh()->last_activity_at->greaterThan($oldActivity));
    }

    public function test_conversation_auto_generate_title()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'settings' => ['auto_title' => true],
        ]);

        $this->assertNull($conversation->title);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'What is the capital of France?',
            'sent_at' => now(),
        ]);

        $conversation->autoGenerateTitle();

        $this->assertNotNull($conversation->fresh()->title);
        $this->assertStringContainsString('What is the capital of France', $conversation->fresh()->title);
    }

    public function test_conversation_auto_generate_title_disabled()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'settings' => ['auto_title' => false],
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'What is the capital of France?',
            'sent_at' => now(),
        ]);

        $conversation->autoGenerateTitle();

        $this->assertNull($conversation->fresh()->title);
    }

    public function test_conversation_settings_cast()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'settings' => ['max_messages' => 100, 'temperature' => 0.8],
        ]);

        $this->assertIsArray($conversation->settings);
        $this->assertEquals(100, $conversation->settings['max_messages']);
        $this->assertEquals(0.8, $conversation->settings['temperature']);
    }

    public function test_conversation_fillable_attributes()
    {
        $data = [
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'title' => 'Test Conversation',
            'system_prompt' => 'You are helpful.',
            'settings' => ['max_messages' => 50],
            'is_active' => true,
            'last_activity_at' => now(),
        ];

        $conversation = new Conversation($data);

        $this->assertEquals('conv-123', $conversation->conversation_id);
        $this->assertEquals('user-456', $conversation->user_id);
        $this->assertEquals('Test Conversation', $conversation->title);
        $this->assertEquals('You are helpful.', $conversation->system_prompt);
        $this->assertEquals(['max_messages' => 50], $conversation->settings);
        $this->assertTrue($conversation->is_active);
    }
}
