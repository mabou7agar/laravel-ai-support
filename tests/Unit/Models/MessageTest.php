<?php

namespace LaravelAIEngine\Tests\Unit\Models;

use LaravelAIEngine\Models\Message;
use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_creation()
    {
        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello, how are you?',
            'metadata' => ['test' => 'value'],
            'tokens_used' => 10,
            'credits_used' => 0.5,
        ]);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertEquals('conv-123', $message->conversation_id);
        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello, how are you?', $message->content);
        $this->assertEquals(['test' => 'value'], $message->metadata);
        $this->assertEquals(10, $message->tokens_used);
        $this->assertEquals(0.5, $message->credits_used);
        $this->assertNotNull($message->sent_at);
    }

    public function test_message_belongs_to_conversation()
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

        $this->assertInstanceOf(Conversation::class, $message->conversation);
        $this->assertEquals($conversation->id, $message->conversation->id);
    }

    public function test_message_is_user_role()
    {
        $userMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $assistantMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Hi there!',
        ]);

        $this->assertTrue($userMessage->isUser());
        $this->assertFalse($assistantMessage->isUser());
    }

    public function test_message_is_assistant_role()
    {
        $userMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $assistantMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Hi there!',
        ]);

        $this->assertFalse($userMessage->isAssistant());
        $this->assertTrue($assistantMessage->isAssistant());
    }

    public function test_message_is_system_role()
    {
        $systemMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'system',
            'content' => 'You are a helpful assistant.',
        ]);

        $userMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello',
        ]);

        $this->assertTrue($systemMessage->isSystem());
        $this->assertFalse($userMessage->isSystem());
    }

    public function test_message_scope_for_conversation()
    {
        $conv1Message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello from conv 1',
        ]);

        $conv2Message = Message::create([
            'conversation_id' => 'conv-456',
            'role' => 'user',
            'content' => 'Hello from conv 2',
        ]);

        $conv1Messages = Message::forConversation('conv-123')->get();

        $this->assertCount(1, $conv1Messages);
        $this->assertEquals($conv1Message->id, $conv1Messages->first()->id);
    }

    public function test_message_scope_by_role()
    {
        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'User message',
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Assistant message',
        ]);

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'system',
            'content' => 'System message',
        ]);

        $userMessages = Message::byRole('user')->get();
        $assistantMessages = Message::byRole('assistant')->get();
        $systemMessages = Message::byRole('system')->get();

        $this->assertCount(1, $userMessages);
        $this->assertCount(1, $assistantMessages);
        $this->assertCount(1, $systemMessages);
        $this->assertEquals('user', $userMessages->first()->role);
        $this->assertEquals('assistant', $assistantMessages->first()->role);
        $this->assertEquals('system', $systemMessages->first()->role);
    }

    public function test_message_scope_recent()
    {
        // Create messages with different timestamps
        $oldMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Old message',
            'sent_at' => now()->subHours(2),
        ]);

        $recentMessage = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Recent message',
            'sent_at' => now(),
        ]);

        $recentMessages = Message::recent()->get();

        // Should be ordered by sent_at desc
        $this->assertEquals($recentMessage->id, $recentMessages->first()->id);
        $this->assertEquals($oldMessage->id, $recentMessages->last()->id);
    }

    public function test_message_update_usage_stats()
    {
        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Hello!',
        ]);

        $this->assertNull($message->tokens_used);
        $this->assertNull($message->credits_used);

        $message->updateUsageStats(tokensUsed: 15, creditsUsed: 0.75);

        $this->assertEquals(15, $message->fresh()->tokens_used);
        $this->assertEquals(0.75, $message->fresh()->credits_used);
    }

    public function test_message_to_context_array()
    {
        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello, how are you?',
            'metadata' => ['timestamp' => '2025-01-18T12:00:00Z'],
        ]);

        $context = $message->toContextArray();

        $this->assertIsArray($context);
        $this->assertEquals('user', $context['role']);
        $this->assertEquals('Hello, how are you?', $context['content']);
        $this->assertArrayNotHasKey('metadata', $context);
        $this->assertArrayNotHasKey('id', $context);
        $this->assertArrayNotHasKey('conversation_id', $context);
    }

    public function test_message_metadata_cast()
    {
        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'assistant',
            'content' => 'Hello!',
            'metadata' => ['engine' => 'openai', 'model' => 'gpt-4o', 'tokens' => 15],
        ]);

        $this->assertIsArray($message->metadata);
        $this->assertEquals('openai', $message->metadata['engine']);
        $this->assertEquals('gpt-4o', $message->metadata['model']);
        $this->assertEquals(15, $message->metadata['tokens']);
    }

    public function test_message_fillable_attributes()
    {
        $data = [
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello!',
            'metadata' => ['test' => 'value'],
            'tokens_used' => 10,
            'credits_used' => 0.5,
            'sent_at' => now(),
        ];

        $message = new Message($data);

        $this->assertEquals('conv-123', $message->conversation_id);
        $this->assertEquals('user', $message->role);
        $this->assertEquals('Hello!', $message->content);
        $this->assertEquals(['test' => 'value'], $message->metadata);
        $this->assertEquals(10, $message->tokens_used);
        $this->assertEquals(0.5, $message->credits_used);
    }

    public function test_message_sent_at_default()
    {
        $message = Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello!',
        ]);

        $this->assertNotNull($message->sent_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $message->sent_at);
    }

    public function test_message_conversation_updates_last_activity()
    {
        $conversation = Conversation::create([
            'conversation_id' => 'conv-123',
            'user_id' => 'user-456',
            'last_activity_at' => now()->subHour(),
        ]);

        $oldActivity = $conversation->last_activity_at;

        Message::create([
            'conversation_id' => 'conv-123',
            'role' => 'user',
            'content' => 'Hello!',
        ]);

        // The message creation should trigger conversation last activity update
        $this->assertTrue($conversation->fresh()->last_activity_at->greaterThan($oldActivity));
    }
}
