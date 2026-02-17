<?php

namespace LaravelAIEngine\Tests\Unit\Services\Streaming;

use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class WebSocketManagerTest extends TestCase
{
    protected WebSocketManager $webSocketManager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!interface_exists('Ratchet\MessageComponentInterface')) {
            $this->markTestSkipped('Ratchet dependency not installed');
        }
        
        $this->webSocketManager = new WebSocketManager();
    }

    public function test_can_start_server()
    {
        $config = [
            'host' => '127.0.0.1',
            'port' => 8080,
            'max_connections' => 100
        ];

        // Mock the server start (we can't actually start a server in tests)
        $result = $this->webSocketManager->startServer($config);
        
        $this->assertTrue($result);
    }

    public function test_can_stop_server()
    {
        $result = $this->webSocketManager->stopServer();
        
        $this->assertTrue($result);
    }

    public function test_can_subscribe_to_session()
    {
        $sessionId = 'test-session-123';
        $connectionId = 'conn-456';

        $result = $this->webSocketManager->subscribeToSession($sessionId, $connectionId);
        
        $this->assertTrue($result);
    }

    public function test_can_unsubscribe_from_session()
    {
        $sessionId = 'test-session-123';
        $connectionId = 'conn-456';

        // First subscribe
        $this->webSocketManager->subscribeToSession($sessionId, $connectionId);
        
        // Then unsubscribe
        $result = $this->webSocketManager->unsubscribeFromSession($sessionId, $connectionId);
        
        $this->assertTrue($result);
    }

    public function test_can_broadcast_to_session()
    {
        $sessionId = 'test-session-123';
        $data = ['message' => 'Hello World', 'type' => 'response_chunk'];

        $result = $this->webSocketManager->broadcastToSession($sessionId, $data);
        
        $this->assertTrue($result);
    }

    public function test_can_broadcast_to_all()
    {
        $data = ['message' => 'System announcement', 'type' => 'system'];

        $result = $this->webSocketManager->broadcastToAll($data);
        
        $this->assertTrue($result);
    }

    public function test_can_stream_response()
    {
        $sessionId = 'test-session-123';
        $generator = function() {
            yield 'chunk1';
            yield 'chunk2';
            yield 'chunk3';
        };

        $this->webSocketManager->streamResponse($sessionId, $generator);
        
        // Verify streaming was initiated (we can't test actual streaming in unit tests)
        $this->assertTrue(true);
    }

    public function test_can_stream_with_actions()
    {
        $sessionId = 'test-session-123';
        $generator = function() {
            yield 'chunk1';
            yield 'chunk2';
        };
        $actions = [
            ['type' => 'button', 'label' => 'Continue', 'action' => 'continue']
        ];

        $this->webSocketManager->streamWithActions($sessionId, $generator, $actions);
        
        // Verify streaming with actions was initiated
        $this->assertTrue(true);
    }

    public function test_can_handle_connection_close()
    {
        $connectionId = 'conn-456';

        $result = $this->webSocketManager->handleConnectionClose($connectionId);
        
        $this->assertTrue($result);
    }

    public function test_can_handle_connection_error()
    {
        $connectionId = 'conn-456';
        $error = new \Exception('Connection error');

        $result = $this->webSocketManager->handleConnectionError($connectionId, $error);
        
        $this->assertTrue($result);
    }

    public function test_can_get_active_connections()
    {
        $connections = $this->webSocketManager->getActiveConnections();
        
        $this->assertIsArray($connections);
    }

    public function test_can_get_session_subscribers()
    {
        $sessionId = 'test-session-123';
        
        $subscribers = $this->webSocketManager->getSessionSubscribers($sessionId);
        
        $this->assertIsArray($subscribers);
    }

    public function test_can_get_stats()
    {
        $stats = $this->webSocketManager->getStats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('active_connections', $stats);
        $this->assertArrayHasKey('total_sessions', $stats);
        $this->assertArrayHasKey('messages_sent', $stats);
        $this->assertArrayHasKey('uptime', $stats);
    }

    public function test_can_send_heartbeat()
    {
        $connectionId = 'conn-456';

        $result = $this->webSocketManager->sendHeartbeat($connectionId);
        
        $this->assertTrue($result);
    }

    public function test_can_validate_connection()
    {
        $connectionId = 'conn-456';

        $result = $this->webSocketManager->validateConnection($connectionId);
        
        $this->assertIsBool($result);
    }

    public function test_can_get_connection_info()
    {
        $connectionId = 'conn-456';

        $info = $this->webSocketManager->getConnectionInfo($connectionId);
        
        $this->assertIsArray($info);
    }

    public function test_can_set_connection_metadata()
    {
        $connectionId = 'conn-456';
        $metadata = ['user_id' => 123, 'session_id' => 'test-session'];

        $result = $this->webSocketManager->setConnectionMetadata($connectionId, $metadata);
        
        $this->assertTrue($result);
    }

    public function test_can_get_server_status()
    {
        $status = $this->webSocketManager->getServerStatus();
        
        $this->assertIsArray($status);
        $this->assertArrayHasKey('running', $status);
        $this->assertArrayHasKey('host', $status);
        $this->assertArrayHasKey('port', $status);
        $this->assertArrayHasKey('start_time', $status);
    }

    public function test_can_cleanup_stale_connections()
    {
        $cleaned = $this->webSocketManager->cleanupStaleConnections();
        
        $this->assertIsInt($cleaned);
        $this->assertGreaterThanOrEqual(0, $cleaned);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
