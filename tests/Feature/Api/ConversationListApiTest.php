<?php

namespace LaravelAIEngine\Tests\Feature\Api;

use LaravelAIEngine\Models\Conversation;
use LaravelAIEngine\Models\Message;
use LaravelAIEngine\Tests\TestCase;

class ConversationListApiTest extends TestCase
{
    public function test_authenticated_user_conversations_include_summary_and_are_user_scoped(): void
    {
        $user = $this->createTestUser();
        $otherUser = $this->createTestUser();

        $conversation = Conversation::create([
            'conversation_id' => 'conv-auth-001',
            'user_id' => (string) $user->id,
            'title' => 'Invoice follow-up',
            'settings' => ['engine' => 'openai'],
            'is_active' => true,
            'last_activity_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'user',
            'content' => 'Show me invoices due this week and explain the most urgent ones.',
            'sent_at' => now()->subMinute(),
        ]);

        Message::create([
            'conversation_id' => $conversation->conversation_id,
            'role' => 'assistant',
            'content' => 'You have three invoices due this week. The most urgent is ACME Trading because it is overdue and has the highest balance.',
            'sent_at' => now(),
        ]);

        $otherConversation = Conversation::create([
            'conversation_id' => 'conv-other-001',
            'user_id' => (string) $otherUser->id,
            'title' => 'Other user conversation',
            'settings' => ['engine' => 'openai'],
            'is_active' => true,
            'last_activity_at' => now()->subHour(),
        ]);

        Message::create([
            'conversation_id' => $otherConversation->conversation_id,
            'role' => 'assistant',
            'content' => 'This conversation should not be visible to another user.',
            'sent_at' => now()->subHour(),
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/v1/rag/conversations');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.conversations')
            ->assertJsonPath('data.conversations.0.conversation_id', 'conv-auth-001')
            ->assertJsonPath(
                'data.conversations.0.summary',
                'You have three invoices due this week. The most urgent is ACME Trading because it is overdue and has the highest balance.'
            )
            ->assertJsonPath('data.conversations.0.message_count', 2);
    }
}
