<?php

namespace LaravelAIEngine\Services\Memory\Drivers;

use LaravelAIEngine\Services\Memory\Contracts\MemoryDriverInterface;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * MongoDB memory driver for scalable conversation storage
 */
class MongoMemoryDriver implements MemoryDriverInterface
{
    protected Client $client;
    protected Collection $conversations;
    protected Collection $messages;

    public function __construct()
    {
        $connectionString = config('ai-engine.memory.mongodb.connection_string', 'mongodb://localhost:27017');
        $database = config('ai-engine.memory.mongodb.database', 'ai_engine');
        
        $this->client = new Client($connectionString);
        $db = $this->client->selectDatabase($database);
        
        $this->conversations = $db->selectCollection('conversations');
        $this->messages = $db->selectCollection('messages');
        
        // Create indexes for better performance
        $this->createIndexes();
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
        // Ensure conversation exists
        if (!$this->exists($conversationId)) {
            $this->createConversationRecord($conversationId);
        }

        // Insert message
        $this->messages->insertOne([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'metadata' => $metadata,
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime()
        ]);

        // Update conversation last activity
        $this->conversations->updateOne(
            ['conversation_id' => $conversationId],
            [
                '$set' => [
                    'last_activity_at' => new UTCDateTime(),
                    'updated_at' => new UTCDateTime()
                ],
                '$inc' => ['message_count' => 1]
            ]
        );
    }

    /**
     * Get messages from conversation
     */
    public function getMessages(string $conversationId): array
    {
        $cursor = $this->messages->find(
            ['conversation_id' => $conversationId],
            ['sort' => ['created_at' => 1]]
        );

        $messages = [];
        foreach ($cursor as $document) {
            $messages[] = [
                'role' => $document['role'],
                'content' => $document['content'],
                'metadata' => $document['metadata'] ?? [],
                'created_at' => $document['created_at']->toDateTime()->format('Y-m-d H:i:s'),
            ];
        }

        return $messages;
    }

    /**
     * Get conversation context with system prompt
     */
    public function getContext(string $conversationId): array
    {
        $conversation = $this->conversations->findOne(['conversation_id' => $conversationId]);
        
        if (!$conversation) {
            return [];
        }

        $context = [];
        
        // Add system prompt if exists
        if (!empty($conversation['system_prompt'])) {
            $context[] = [
                'role' => 'system',
                'content' => $conversation['system_prompt']
            ];
        }

        // Add conversation messages
        $messages = $this->getMessages($conversationId);
        foreach ($messages as $message) {
            $context[] = [
                'role' => $message['role'],
                'content' => $message['content']
            ];
        }

        return $context;
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
        $conversationId = Str::uuid()->toString();
        
        $this->conversations->insertOne([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'title' => $title ?? 'New Conversation',
            'system_prompt' => $systemPrompt,
            'settings' => $settings,
            'message_count' => 0,
            'created_at' => new UTCDateTime(),
            'updated_at' => new UTCDateTime(),
            'last_activity_at' => new UTCDateTime()
        ]);

        return $conversationId;
    }

    /**
     * Get conversation data
     */
    public function getConversation(string $conversationId): ?array
    {
        $messages = $this->getMessages($conversationId);
        $context = $this->getContext($conversationId);
        
        return [
            'conversation_id' => $conversationId,
            'messages' => $messages,
            'context' => $context,
        ];
    }

    /**
     * Clear conversation history
     */
    public function clearConversation(string $conversationId): void
    {
        // Delete all messages for this conversation
        $this->messages->deleteMany(['conversation_id' => $conversationId]);
        
        // Reset conversation message count
        $this->conversations->updateOne(
            ['conversation_id' => $conversationId],
            [
                '$set' => [
                    'message_count' => 0,
                    'updated_at' => new UTCDateTime(),
                    'last_activity_at' => new UTCDateTime()
                ]
            ]
        );
    }

    /**
     * Delete conversation
     */
    public function deleteConversation(string $conversationId): bool
    {
        // Delete all messages
        $this->messages->deleteMany(['conversation_id' => $conversationId]);
        
        // Delete conversation record
        $result = $this->conversations->deleteOne(['conversation_id' => $conversationId]);
        
        return $result->getDeletedCount() > 0;
    }

    /**
     * Get conversation statistics
     */
    public function getStats(string $conversationId): array
    {
        $conversation = $this->conversations->findOne(['conversation_id' => $conversationId]);
        
        if (!$conversation) {
            return [
                'message_count' => 0,
                'created_at' => null,
                'last_activity' => null,
            ];
        }

        // Get role-based message counts
        $pipeline = [
            ['$match' => ['conversation_id' => $conversationId]],
            ['$group' => [
                '_id' => '$role',
                'count' => ['$sum' => 1]
            ]]
        ];
        
        $roleCounts = [];
        $cursor = $this->messages->aggregate($pipeline);
        foreach ($cursor as $result) {
            $roleCounts[$result['_id']] = $result['count'];
        }

        return [
            'message_count' => $conversation['message_count'] ?? 0,
            'user_messages' => $roleCounts['user'] ?? 0,
            'assistant_messages' => $roleCounts['assistant'] ?? 0,
            'system_messages' => $roleCounts['system'] ?? 0,
            'created_at' => $conversation['created_at']->toDateTime()->format('Y-m-d H:i:s'),
            'last_activity' => $conversation['last_activity_at']->toDateTime()->format('Y-m-d H:i:s'),
            'title' => $conversation['title'],
            'user_id' => $conversation['user_id'],
        ];
    }

    /**
     * Check if conversation exists
     */
    public function exists(string $conversationId): bool
    {
        return $this->conversations->countDocuments(['conversation_id' => $conversationId]) > 0;
    }

    /**
     * Get conversations for a user
     */
    public function getUserConversations(string $userId, int $limit = 50, int $offset = 0): array
    {
        $cursor = $this->conversations->find(
            ['user_id' => $userId],
            [
                'sort' => ['last_activity_at' => -1],
                'limit' => $limit,
                'skip' => $offset
            ]
        );

        $conversations = [];
        foreach ($cursor as $document) {
            $conversations[] = [
                'conversation_id' => $document['conversation_id'],
                'title' => $document['title'],
                'message_count' => $document['message_count'] ?? 0,
                'created_at' => $document['created_at']->toDateTime()->format('Y-m-d H:i:s'),
                'last_activity_at' => $document['last_activity_at']->toDateTime()->format('Y-m-d H:i:s'),
            ];
        }

        return $conversations;
    }

    /**
     * Search conversations by content
     */
    public function searchConversations(string $query, ?string $userId = null, int $limit = 20): array
    {
        $filter = [
            '$or' => [
                ['title' => ['$regex' => $query, '$options' => 'i']],
                ['system_prompt' => ['$regex' => $query, '$options' => 'i']]
            ]
        ];

        if ($userId) {
            $filter['user_id'] = $userId;
        }

        $cursor = $this->conversations->find(
            $filter,
            [
                'sort' => ['last_activity_at' => -1],
                'limit' => $limit
            ]
        );

        $conversations = [];
        foreach ($cursor as $document) {
            $conversations[] = [
                'conversation_id' => $document['conversation_id'],
                'title' => $document['title'],
                'message_count' => $document['message_count'] ?? 0,
                'created_at' => $document['created_at']->toDateTime()->format('Y-m-d H:i:s'),
                'last_activity_at' => $document['last_activity_at']->toDateTime()->format('Y-m-d H:i:s'),
            ];
        }

        return $conversations;
    }

    /**
     * Get conversation analytics
     */
    public function getAnalytics(?string $userId = null, int $days = 30): array
    {
        $startDate = new UTCDateTime(Carbon::now()->subDays($days)->getTimestamp() * 1000);
        
        $filter = ['created_at' => ['$gte' => $startDate]];
        if ($userId) {
            $filter['user_id'] = $userId;
        }

        // Total conversations
        $totalConversations = $this->conversations->countDocuments($filter);

        // Messages per day
        $pipeline = [
            ['$match' => array_merge(['created_at' => ['$gte' => $startDate]], $userId ? ['conversation_id' => ['$in' => $this->getUserConversationIds($userId)]] : [])],
            ['$group' => [
                '_id' => [
                    'year' => ['$year' => '$created_at'],
                    'month' => ['$month' => '$created_at'],
                    'day' => ['$dayOfMonth' => '$created_at']
                ],
                'count' => ['$sum' => 1]
            ]],
            ['$sort' => ['_id' => 1]]
        ];

        $dailyMessages = [];
        $cursor = $this->messages->aggregate($pipeline);
        foreach ($cursor as $result) {
            $date = sprintf('%04d-%02d-%02d', $result['_id']['year'], $result['_id']['month'], $result['_id']['day']);
            $dailyMessages[$date] = $result['count'];
        }

        return [
            'total_conversations' => $totalConversations,
            'daily_messages' => $dailyMessages,
            'period_days' => $days,
        ];
    }

    /**
     * Create database indexes for better performance
     */
    protected function createIndexes(): void
    {
        // Conversation indexes
        $this->conversations->createIndex(['conversation_id' => 1], ['unique' => true]);
        $this->conversations->createIndex(['user_id' => 1]);
        $this->conversations->createIndex(['last_activity_at' => -1]);
        $this->conversations->createIndex(['created_at' => -1]);

        // Message indexes
        $this->messages->createIndex(['conversation_id' => 1]);
        $this->messages->createIndex(['conversation_id' => 1, 'created_at' => 1]);
        $this->messages->createIndex(['role' => 1]);
        $this->messages->createIndex(['created_at' => -1]);

        // Text search indexes
        $this->conversations->createIndex(['title' => 'text', 'system_prompt' => 'text']);
        $this->messages->createIndex(['content' => 'text']);
    }

    /**
     * Create conversation record if it doesn't exist
     */
    protected function createConversationRecord(string $conversationId): void
    {
        $this->conversations->updateOne(
            ['conversation_id' => $conversationId],
            [
                '$setOnInsert' => [
                    'conversation_id' => $conversationId,
                    'title' => 'New Conversation',
                    'message_count' => 0,
                    'created_at' => new UTCDateTime(),
                    'updated_at' => new UTCDateTime(),
                    'last_activity_at' => new UTCDateTime()
                ]
            ],
            ['upsert' => true]
        );
    }

    /**
     * Get conversation IDs for a user
     */
    protected function getUserConversationIds(string $userId): array
    {
        $cursor = $this->conversations->find(
            ['user_id' => $userId],
            ['projection' => ['conversation_id' => 1]]
        );

        $ids = [];
        foreach ($cursor as $document) {
            $ids[] = $document['conversation_id'];
        }

        return $ids;
    }
}
