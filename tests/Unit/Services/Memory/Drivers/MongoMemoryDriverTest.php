<?php

namespace LaravelAIEngine\Tests\Unit\Services\Memory\Drivers;

use LaravelAIEngine\Services\Memory\Drivers\MongoMemoryDriver;
use LaravelAIEngine\Tests\TestCase;
use MongoDB\Client;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\BSON\UTCDateTime;
use Mockery;

class MongoMemoryDriverTest extends TestCase
{
    protected MongoMemoryDriver $driver;
    protected $mockClient;
    protected $mockDatabase;
    protected $mockConversations;
    protected $mockMessages;

    protected function setUp(): void
    {
        parent::setUp();

        if (!class_exists(Client::class)) {
            $this->markTestSkipped('MongoDB extension not installed');
        }

        // Mock MongoDB components
        $this->mockClient = Mockery::mock(Client::class);
        $this->mockDatabase = Mockery::mock(Database::class);
        $this->mockConversations = Mockery::mock(Collection::class);
        $this->mockMessages = Mockery::mock(Collection::class);

        // Set up mock chain
        $this->mockClient->shouldReceive('selectDatabase')
            ->with('ai_engine')
            ->andReturn($this->mockDatabase);

        $this->mockDatabase->shouldReceive('selectCollection')
            ->with('conversations')
            ->andReturn($this->mockConversations);

        $this->mockDatabase->shouldReceive('selectCollection')
            ->with('messages')
            ->andReturn($this->mockMessages);

        // Mock index creation
        $this->mockConversations->shouldReceive('createIndex')->andReturn(null);
        $this->mockMessages->shouldReceive('createIndex')->andReturn(null);

        // Create driver instance with mocked dependencies
        $this->driver = new class($this->mockClient) extends MongoMemoryDriver {
            public function __construct($client)
            {
                $this->client = $client;
                $db = $this->client->selectDatabase('ai_engine');
                $this->conversations = $db->selectCollection('conversations');
                $this->messages = $db->selectCollection('messages');
                $this->createIndexes();
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_add_message_to_existing_conversation()
    {
        $conversationId = 'test-conversation-id';
        
        // Mock conversation exists check
        $this->mockConversations->shouldReceive('countDocuments')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn(1);

        // Mock message insertion
        $this->mockMessages->shouldReceive('insertOne')
            ->once()
            ->with(Mockery::on(function ($data) use ($conversationId) {
                return $data['conversation_id'] === $conversationId
                    && $data['role'] === 'user'
                    && $data['content'] === 'Hello'
                    && $data['metadata'] === ['test' => 'data']
                    && $data['created_at'] instanceof UTCDateTime;
            }))
            ->andReturn(null);

        // Mock conversation update
        $this->mockConversations->shouldReceive('updateOne')
            ->once()
            ->with(
                ['conversation_id' => $conversationId],
                Mockery::on(function ($update) {
                    return isset($update['$set']['last_activity_at'])
                        && isset($update['$inc']['message_count'])
                        && $update['$inc']['message_count'] === 1;
                })
            )
            ->andReturn(null);

        $this->driver->addMessage($conversationId, 'user', 'Hello', ['test' => 'data']);
    }

    public function test_add_message_to_new_conversation()
    {
        $conversationId = 'new-conversation-id';
        
        // Mock conversation doesn't exist
        $this->mockConversations->shouldReceive('countDocuments')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn(0);

        // Mock conversation creation
        $this->mockConversations->shouldReceive('updateOne')
            ->once()
            ->with(
                ['conversation_id' => $conversationId],
                Mockery::on(function ($update) {
                    return isset($update['$setOnInsert']['conversation_id'])
                        && $update['$setOnInsert']['conversation_id'] === $conversationId;
                }),
                ['upsert' => true]
            )
            ->andReturn(null);

        // Mock message insertion
        $this->mockMessages->shouldReceive('insertOne')
            ->once()
            ->andReturn(null);

        // Mock conversation update
        $this->mockConversations->shouldReceive('updateOne')
            ->once()
            ->andReturn(null);

        $this->driver->addMessage($conversationId, 'assistant', 'Hi there!');
    }

    public function test_get_messages()
    {
        $conversationId = 'test-conversation-id';
        
        $mockMessages = [
            [
                'role' => 'user',
                'content' => 'Hello',
                'metadata' => ['test' => 'data'],
                'created_at' => new UTCDateTime()
            ],
            [
                'role' => 'assistant',
                'content' => 'Hi there!',
                'metadata' => [],
                'created_at' => new UTCDateTime()
            ]
        ];

        $mockCursor = Mockery::mock();
        $mockCursor->shouldReceive('rewind')->once();
        $mockCursor->shouldReceive('valid')->andReturn(true, true, false);
        $mockCursor->shouldReceive('current')->andReturn($mockMessages[0], $mockMessages[1]);
        $mockCursor->shouldReceive('next')->twice();

        $this->mockMessages->shouldReceive('find')
            ->with(
                ['conversation_id' => $conversationId],
                ['sort' => ['created_at' => 1]]
            )
            ->once()
            ->andReturn($mockCursor);

        $messages = $this->driver->getMessages($conversationId);

        $this->assertCount(2, $messages);
        $this->assertEquals('user', $messages[0]['role']);
        $this->assertEquals('Hello', $messages[0]['content']);
        $this->assertEquals(['test' => 'data'], $messages[0]['metadata']);
    }

    public function test_get_context_with_system_prompt()
    {
        $conversationId = 'test-conversation-id';
        
        $conversationData = [
            'conversation_id' => $conversationId,
            'system_prompt' => 'You are a helpful assistant',
            'title' => 'Test Conversation'
        ];

        $this->mockConversations->shouldReceive('findOne')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn($conversationData);

        // Mock get messages
        $mockCursor = Mockery::mock();
        $mockCursor->shouldReceive('rewind')->once();
        $mockCursor->shouldReceive('valid')->andReturn(false);

        $this->mockMessages->shouldReceive('find')
            ->once()
            ->andReturn($mockCursor);

        $context = $this->driver->getContext($conversationId);

        $this->assertCount(1, $context);
        $this->assertEquals('system', $context[0]['role']);
        $this->assertEquals('You are a helpful assistant', $context[0]['content']);
    }

    public function test_create_conversation()
    {
        $this->mockConversations->shouldReceive('insertOne')
            ->once()
            ->with(Mockery::on(function ($data) {
                return isset($data['conversation_id'])
                    && $data['user_id'] === 'user123'
                    && $data['title'] === 'Test Conversation'
                    && $data['system_prompt'] === 'Test prompt'
                    && $data['message_count'] === 0
                    && $data['created_at'] instanceof UTCDateTime;
            }))
            ->andReturn(null);

        $conversationId = $this->driver->createConversation(
            'user123',
            'Test Conversation',
            'Test prompt',
            ['setting1' => 'value1']
        );

        $this->assertIsString($conversationId);
        $this->assertNotEmpty($conversationId);
    }

    public function test_clear_conversation()
    {
        $conversationId = 'test-conversation-id';

        // Mock delete messages
        $this->mockMessages->shouldReceive('deleteMany')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn(null);

        // Mock update conversation
        $this->mockConversations->shouldReceive('updateOne')
            ->with(
                ['conversation_id' => $conversationId],
                Mockery::on(function ($update) {
                    return $update['$set']['message_count'] === 0
                        && isset($update['$set']['updated_at'])
                        && isset($update['$set']['last_activity_at']);
                })
            )
            ->once()
            ->andReturn(null);

        $this->driver->clearConversation($conversationId);
    }

    public function test_delete_conversation()
    {
        $conversationId = 'test-conversation-id';

        // Mock delete messages
        $this->mockMessages->shouldReceive('deleteMany')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn(null);

        // Mock delete conversation
        $mockResult = Mockery::mock();
        $mockResult->shouldReceive('getDeletedCount')->once()->andReturn(1);

        $this->mockConversations->shouldReceive('deleteOne')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn($mockResult);

        $result = $this->driver->deleteConversation($conversationId);

        $this->assertTrue($result);
    }

    public function test_get_stats()
    {
        $conversationId = 'test-conversation-id';
        
        $conversationData = [
            'conversation_id' => $conversationId,
            'message_count' => 5,
            'title' => 'Test Conversation',
            'user_id' => 'user123',
            'created_at' => new UTCDateTime(),
            'last_activity_at' => new UTCDateTime()
        ];

        $this->mockConversations->shouldReceive('findOne')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn($conversationData);

        // Mock role counts aggregation
        $mockCursor = Mockery::mock();
        $mockCursor->shouldReceive('rewind')->once();
        $mockCursor->shouldReceive('valid')->andReturn(true, true, false);
        $mockCursor->shouldReceive('current')->andReturn(
            ['_id' => 'user', 'count' => 3],
            ['_id' => 'assistant', 'count' => 2]
        );
        $mockCursor->shouldReceive('next')->twice();

        $this->mockMessages->shouldReceive('aggregate')
            ->once()
            ->andReturn($mockCursor);

        $stats = $this->driver->getStats($conversationId);

        $this->assertEquals(5, $stats['message_count']);
        $this->assertEquals(3, $stats['user_messages']);
        $this->assertEquals(2, $stats['assistant_messages']);
        $this->assertEquals(0, $stats['system_messages']);
        $this->assertEquals('Test Conversation', $stats['title']);
        $this->assertEquals('user123', $stats['user_id']);
    }

    public function test_exists()
    {
        $conversationId = 'test-conversation-id';

        $this->mockConversations->shouldReceive('countDocuments')
            ->with(['conversation_id' => $conversationId])
            ->once()
            ->andReturn(1);

        $result = $this->driver->exists($conversationId);

        $this->assertTrue($result);
    }

    public function test_get_user_conversations()
    {
        $userId = 'user123';
        
        $conversationData = [
            [
                'conversation_id' => 'conv1',
                'title' => 'Conversation 1',
                'message_count' => 5,
                'created_at' => new UTCDateTime(),
                'last_activity_at' => new UTCDateTime()
            ],
            [
                'conversation_id' => 'conv2',
                'title' => 'Conversation 2',
                'message_count' => 3,
                'created_at' => new UTCDateTime(),
                'last_activity_at' => new UTCDateTime()
            ]
        ];

        $mockCursor = Mockery::mock();
        $mockCursor->shouldReceive('rewind')->once();
        $mockCursor->shouldReceive('valid')->andReturn(true, true, false);
        $mockCursor->shouldReceive('current')->andReturn($conversationData[0], $conversationData[1]);
        $mockCursor->shouldReceive('next')->twice();

        $this->mockConversations->shouldReceive('find')
            ->with(
                ['user_id' => $userId],
                [
                    'sort' => ['last_activity_at' => -1],
                    'limit' => 50,
                    'skip' => 0
                ]
            )
            ->once()
            ->andReturn($mockCursor);

        $conversations = $this->driver->getUserConversations($userId);

        $this->assertCount(2, $conversations);
        $this->assertEquals('conv1', $conversations[0]['conversation_id']);
        $this->assertEquals('Conversation 1', $conversations[0]['title']);
        $this->assertEquals(5, $conversations[0]['message_count']);
    }

    public function test_search_conversations()
    {
        $query = 'test query';
        $userId = 'user123';
        
        $conversationData = [
            [
                'conversation_id' => 'conv1',
                'title' => 'Test Conversation',
                'message_count' => 5,
                'created_at' => new UTCDateTime(),
                'last_activity_at' => new UTCDateTime()
            ]
        ];

        $mockCursor = Mockery::mock();
        $mockCursor->shouldReceive('rewind')->once();
        $mockCursor->shouldReceive('valid')->andReturn(true, false);
        $mockCursor->shouldReceive('current')->andReturn($conversationData[0]);
        $mockCursor->shouldReceive('next')->once();

        $this->mockConversations->shouldReceive('find')
            ->with(
                Mockery::on(function ($filter) use ($query, $userId) {
                    return isset($filter['$or'])
                        && $filter['user_id'] === $userId;
                }),
                [
                    'sort' => ['last_activity_at' => -1],
                    'limit' => 20
                ]
            )
            ->once()
            ->andReturn($mockCursor);

        $conversations = $this->driver->searchConversations($query, $userId);

        $this->assertCount(1, $conversations);
        $this->assertEquals('conv1', $conversations[0]['conversation_id']);
        $this->assertEquals('Test Conversation', $conversations[0]['title']);
    }
}
