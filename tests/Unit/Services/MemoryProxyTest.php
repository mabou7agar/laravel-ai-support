<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\MemoryProxy;
use LaravelAIEngine\Services\Memory\MemoryManager;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class MemoryProxyTest extends TestCase
{
    protected MemoryProxy $proxy;
    protected MemoryManager $memoryManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->memoryManager = Mockery::mock(MemoryManager::class);
        $this->proxy = new MemoryProxy($this->memoryManager);
    }

    public function test_conversation_method_sets_conversation_id()
    {
        $result = $this->proxy->conversation('conv-123');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_driver_method_sets_driver()
    {
        $result = $this->proxy->driver('redis');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_add_user_message()
    {
        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'user', 'Hello!', [])
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->addUserMessage('Hello!');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_add_assistant_message()
    {
        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'assistant', 'Hi there!', [])
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->addAssistantMessage('Hi there!');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_add_system_message()
    {
        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'system', 'You are a helpful assistant', [])
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->addSystemMessage('You are a helpful assistant');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_add_message_with_metadata()
    {
        $metadata = ['timestamp' => '2024-01-01 12:00:00'];

        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'user', 'Hello!', $metadata)
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->addMessage('user', 'Hello!', $metadata);

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_get_messages()
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello!'],
            ['role' => 'assistant', 'content' => 'Hi there!']
        ];

        $this->memoryManager
            ->shouldReceive('getMessages')
            ->with('conv-123', 50)
            ->once()
            ->andReturn($messages);

        $result = $this->proxy
            ->conversation('conv-123')
            ->getMessages(50);

        $this->assertEquals($messages, $result);
    }

    public function test_get_context()
    {
        $context = [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'Hello!'],
            ['role' => 'assistant', 'content' => 'Hi there!']
        ];

        $this->memoryManager
            ->shouldReceive('getContext')
            ->with('conv-123', 50)
            ->once()
            ->andReturn($context);

        $result = $this->proxy
            ->conversation('conv-123')
            ->getContext(50);

        $this->assertEquals($context, $result);
    }

    public function test_create_conversation()
    {
        $this->memoryManager
            ->shouldReceive('createConversation')
            ->with('conv-123', ['title' => 'Test Conversation'])
            ->once()
            ->andReturn(true);

        $result = $this->proxy->createConversation('conv-123', ['title' => 'Test Conversation']);

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_clear_conversation()
    {
        $this->memoryManager
            ->shouldReceive('clearConversation')
            ->with('conv-123')
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->clear();

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_delete_conversation()
    {
        $this->memoryManager
            ->shouldReceive('deleteConversation')
            ->with('conv-123')
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->delete();

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_exists_conversation()
    {
        $this->memoryManager
            ->shouldReceive('exists')
            ->with('conv-123')
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->exists();

        $this->assertTrue($result);
    }

    public function test_get_stats()
    {
        $stats = [
            'total_messages' => 10,
            'total_tokens' => 500,
            'created_at' => '2024-01-01 12:00:00'
        ];

        $this->memoryManager
            ->shouldReceive('getStats')
            ->with('conv-123')
            ->once()
            ->andReturn($stats);

        $result = $this->proxy
            ->conversation('conv-123')
            ->getStats();

        $this->assertEquals($stats, $result);
    }

    public function test_set_parent_context()
    {
        $this->memoryManager
            ->shouldReceive('setParent')
            ->with('conv-123', 'conversation', 'parent-conv-456')
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->setParent('conversation', 'parent-conv-456');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_method_chaining()
    {
        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'user', 'Hello!', [])
            ->once()
            ->andReturn(true);

        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'assistant', 'Hi there!', [])
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->conversation('conv-123')
            ->driver('redis')
            ->addUserMessage('Hello!')
            ->addAssistantMessage('Hi there!');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_with_specific_driver()
    {
        $this->memoryManager
            ->shouldReceive('driver')
            ->with('redis')
            ->once()
            ->andReturnSelf();

        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'user', 'Hello!', [])
            ->once()
            ->andReturn(true);

        $result = $this->proxy
            ->driver('redis')
            ->conversation('conv-123')
            ->addUserMessage('Hello!');

        $this->assertInstanceOf(MemoryProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
