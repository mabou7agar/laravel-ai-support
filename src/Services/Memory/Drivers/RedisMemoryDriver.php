<?php

namespace LaravelAIEngine\Services\Memory\Drivers;

use LaravelAIEngine\Services\Memory\Contracts\MemoryDriverInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Redis memory driver for high-performance conversation storage
 */
class RedisMemoryDriver implements MemoryDriverInterface
{
    protected string $prefix;

    public function __construct()
    {
        $this->prefix = config('ai-engine.memory.redis.prefix', 'ai_engine:');
    }

    /**
     * Add message to conversation
     */
    public function addMessage(
        string $conversationId,
        string $role,
        string $content,
        array $metadata = []
    ): void {
        $message = [
            'id' => 'msg_' . Str::random(16),
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'sent_at' => now()->toISOString(),
        ];

        // Add message to conversation messages list
        Redis::lpush($this->getMessagesKey($conversationId), json_encode($message));

        // Update conversation last activity
        Redis::hset($this->getConversationKey($conversationId), 'last_activity_at', now()->toISOString());

        // Trim messages if max limit is set
        $this->trimMessages($conversationId);
    }

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId): array
    {
        $messages = Redis::lrange($this->getMessagesKey($conversationId), 0, -1);
        
        return array_map(function ($message) {
            $decoded = json_decode($message, true);
            return [
                'role' => $decoded['role'],
                'content' => $decoded['content'],
            ];
        }, array_reverse($messages)); // Reverse to get chronological order
    }

    /**
     * Get conversation context with system prompt
     */
    public function getContext(string $conversationId): array
    {
        $context = [];

        // Get conversation data
        $conversation = Redis::hgetall($this->getConversationKey($conversationId));
        
        // Add system prompt if exists
        if (!empty($conversation['system_prompt'])) {
            $context[] = [
                'role' => 'system',
                'content' => $conversation['system_prompt'],
            ];
        }

        // Add conversation messages
        $messages = $this->getMessages($conversationId);
        
        return array_merge($context, $messages);
    }

    /**
     * Create new conversation
     */
    public function createConversation(
        ?string $userId = null,
        ?string $title = null,
        ?string $systemPrompt = null,
        array $settings = []
    ): string {
        $conversationId = 'conv_' . Str::random(16);
        
        $conversation = [
            'conversation_id' => $conversationId,
            'user_id' => $userId ?? '',
            'title' => $title ?? '',
            'system_prompt' => $systemPrompt ?? '',
            'settings' => json_encode($settings),
            'is_active' => '1',
            'created_at' => now()->toISOString(),
            'last_activity_at' => now()->toISOString(),
        ];

        Redis::hmset($this->getConversationKey($conversationId), $conversation);
        
        // Add to user conversations if user ID provided
        if ($userId) {
            Redis::sadd($this->getUserConversationsKey($userId), $conversationId);
        }

        return $conversationId;
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId): void
    {
        Redis::del($this->getMessagesKey($conversationId));
        Redis::hset($this->getConversationKey($conversationId), 'last_activity_at', now()->toISOString());
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId): bool
    {
        // Get conversation data to remove from user set
        $conversation = Redis::hgetall($this->getConversationKey($conversationId));
        
        if (!empty($conversation['user_id'])) {
            Redis::srem($this->getUserConversationsKey($conversation['user_id']), $conversationId);
        }

        // Delete conversation and messages
        Redis::del($this->getConversationKey($conversationId));
        Redis::del($this->getMessagesKey($conversationId));

        return true;
    }

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId): array
    {
        $conversation = Redis::hgetall($this->getConversationKey($conversationId));
        $messageCount = Redis::llen($this->getMessagesKey($conversationId));
        
        // Count messages by role
        $messages = Redis::lrange($this->getMessagesKey($conversationId), 0, -1);
        $userMessages = 0;
        $assistantMessages = 0;
        
        foreach ($messages as $message) {
            $decoded = json_decode($message, true);
            if ($decoded['role'] === 'user') {
                $userMessages++;
            } elseif ($decoded['role'] === 'assistant') {
                $assistantMessages++;
            }
        }

        return [
            'total_messages' => $messageCount,
            'user_messages' => $userMessages,
            'assistant_messages' => $assistantMessages,
            'created_at' => $conversation['created_at'] ?? null,
            'last_activity' => $conversation['last_activity_at'] ?? null,
        ];
    }

    /**
     * Check if conversation exists
     */
    public function exists(string $conversationId): bool
    {
        return Redis::exists($this->getConversationKey($conversationId)) > 0;
    }

    /**
     * Trim messages to max limit
     */
    protected function trimMessages(string $conversationId): void
    {
        $conversation = Redis::hgetall($this->getConversationKey($conversationId));
        $settings = json_decode($conversation['settings'] ?? '{}', true);
        $maxMessages = $settings['max_messages'] ?? config('ai-engine.memory.redis.max_messages', 100);

        $currentCount = Redis::llen($this->getMessagesKey($conversationId));
        
        if ($currentCount > $maxMessages) {
            $toRemove = $currentCount - $maxMessages;
            for ($i = 0; $i < $toRemove; $i++) {
                Redis::rpop($this->getMessagesKey($conversationId));
            }
        }
    }

    /**
     * Get Redis key for conversation
     */
    protected function getConversationKey(string $conversationId): string
    {
        return $this->prefix . 'conversations:' . $conversationId;
    }

    /**
     * Get Redis key for conversation messages
     */
    protected function getMessagesKey(string $conversationId): string
    {
        return $this->prefix . 'messages:' . $conversationId;
    }

    /**
     * Get Redis key for user conversations
     */
    protected function getUserConversationsKey(string $userId): string
    {
        return $this->prefix . 'user_conversations:' . $userId;
    }
}
