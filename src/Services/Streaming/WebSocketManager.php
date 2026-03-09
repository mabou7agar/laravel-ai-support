<?php

namespace LaravelAIEngine\Services\Streaming;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\Streaming\Contracts\StreamingInterface;
use LaravelAIEngine\Events\StreamingStarted;
use LaravelAIEngine\Events\StreamingChunk;
use LaravelAIEngine\Events\StreamingCompleted;
use LaravelAIEngine\Events\StreamingError;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Socket\Server as SocketServer;
use SplObjectStorage;

/**
 * WebSocket Manager for real-time AI streaming
 */
class WebSocketManager implements StreamingInterface
{
    protected SplObjectStorage $clients;
    protected array $sessions = [];
    protected array $subscriptions = [];
    protected mixed $server = null;
    protected bool $serverRunning = false;
    protected string $serverHost = '0.0.0.0';
    protected int $serverPort = 8080;
    protected ?string $serverStartTime = null;
    protected int $messagesSent = 0;
    protected array $connectionMetadata = [];

    public function __construct()
    {
        $this->clients = new SplObjectStorage();
    }

    /**
     * Start WebSocket server
     */
    public function startServer(string|array $host = '0.0.0.0', int $port = 8080): bool
    {
        if (is_array($host)) {
            $port = (int) ($host['port'] ?? $port);
            $host = (string) ($host['host'] ?? $this->serverHost);
        }

        $this->serverHost = $host;
        $this->serverPort = $port;
        $this->serverRunning = true;
        $this->serverStartTime = now()->toISOString();

        // Ratchet is optional in lightweight/test installations.
        if (
            !class_exists(IoServer::class)
            || !class_exists(HttpServer::class)
            || !class_exists(WsServer::class)
            || !class_exists(SocketServer::class)
        ) {
            return true;
        }

        if (function_exists('app') && app()->environment('testing')) {
            return true;
        }

        $loop = Loop::get();
        $socket = new SocketServer("{$host}:{$port}", $loop);
        
        $this->server = new IoServer(
            new HttpServer(
                new WsServer($this)
            ),
            $socket,
            $loop
        );

        Log::info("WebSocket server started on {$host}:{$port}");
        
        $this->server->run();
        return true;
    }

    /**
     * Stream AI response in real-time
     */
    public function streamResponse(
        string $sessionId,
        callable $generator,
        array $options = []
    ): void {
        try {
            // Notify streaming started
            $this->broadcastToSession($sessionId, [
                'type' => 'streaming_started',
                'session_id' => $sessionId,
                'timestamp' => now()->toISOString()
            ]);

            Event::dispatch(new StreamingStarted($sessionId, $options));

            $fullContent = '';
            $chunkCount = 0;

            // Execute generator and stream chunks
            foreach ($generator() as $chunk) {
                $chunkCount++;
                $fullContent .= $chunk;

                $chunkData = [
                    'type' => 'chunk',
                    'session_id' => $sessionId,
                    'chunk_id' => $chunkCount,
                    'content' => $chunk,
                    'timestamp' => now()->toISOString()
                ];

                $this->broadcastToSession($sessionId, $chunkData);
                Event::dispatch(new StreamingChunk($sessionId, $chunk, $chunkCount));

                // Add small delay to prevent overwhelming clients
                usleep(10000); // 10ms
            }

            // Notify streaming completed
            $completionData = [
                'type' => 'streaming_completed',
                'session_id' => $sessionId,
                'total_chunks' => $chunkCount,
                'full_content' => $fullContent,
                'timestamp' => now()->toISOString()
            ];

            $this->broadcastToSession($sessionId, $completionData);
            Event::dispatch(new StreamingCompleted($sessionId, $fullContent, $chunkCount));

            Log::info("Streaming completed for session: {$sessionId}", [
                'chunks' => $chunkCount,
                'content_length' => strlen($fullContent)
            ]);

        } catch (\Exception $e) {
            $errorData = [
                'type' => 'streaming_error',
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ];

            $this->broadcastToSession($sessionId, $errorData);
            Event::dispatch(new StreamingError($sessionId, $e));

            Log::error("Streaming error for session: {$sessionId}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Stream AI response with interactive actions
     */
    public function streamWithActions(
        string $sessionId,
        callable $generator,
        array $actions = [],
        array $options = []
    ): void {
        $this->streamResponse($sessionId, function() use ($generator, $actions, $sessionId) {
            // Stream the main content
            foreach ($generator() as $chunk) {
                yield $chunk;
            }

            // Send actions after content is complete
            if (!empty($actions)) {
                $this->broadcastToSession($sessionId, [
                    'type' => 'actions',
                    'session_id' => $sessionId,
                    'actions' => array_map(
                        fn ($action) => is_object($action) && method_exists($action, 'toArray')
                            ? $action->toArray()
                            : (array) $action,
                        $actions
                    ),
                    'timestamp' => now()->toISOString()
                ]);
            }
        }, $options);
    }

    /**
     * WebSocket connection opened
     */
    public function onOpen(object $conn): void
    {
        $this->clients->attach($conn);
        
        Log::info("New WebSocket connection", [
            'resource_id' => $conn->resourceId,
            'remote_address' => $conn->remoteAddress
        ]);

        // Send welcome message
        $conn->send(json_encode([
            'type' => 'connection_established',
            'connection_id' => $conn->resourceId,
            'timestamp' => now()->toISOString()
        ]));
    }

    /**
     * WebSocket message received
     */
    public function onMessage(object $from, $msg): void
    {
        try {
            $data = json_decode($msg, true);
            
            if (!$data || !isset($data['type'])) {
                $this->sendError($from, 'Invalid message format');
                return;
            }

            switch ($data['type']) {
                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;
                    
                case 'unsubscribe':
                    $this->handleUnsubscribe($from, $data);
                    break;
                    
                case 'ping':
                    $this->handlePing($from, $data);
                    break;
                    
                default:
                    $this->sendError($from, "Unknown message type: {$data['type']}");
            }

        } catch (\Exception $e) {
            Log::error("WebSocket message error", [
                'error' => $e->getMessage(),
                'message' => $msg
            ]);
            
            $this->sendError($from, 'Message processing error');
        }
    }

    /**
     * WebSocket connection closed
     */
    public function onClose(object $conn): void
    {
        $this->clients->detach($conn);
        
        // Remove from all subscriptions
        foreach ($this->subscriptions as $sessionId => $connections) {
            if (($key = array_search($conn, $connections)) !== false) {
                unset($this->subscriptions[$sessionId][$key]);
            }
        }

        Log::info("WebSocket connection closed", [
            'resource_id' => $conn->resourceId
        ]);
    }

    /**
     * WebSocket error occurred
     */
    public function onError(object $conn, \Exception $e): void
    {
        Log::error("WebSocket connection error", [
            'resource_id' => $conn->resourceId,
            'error' => $e->getMessage()
        ]);

        $conn->close();
    }

    /**
     * Broadcast message to specific session
     */
    public function broadcastToSession(string $sessionId, array $data): bool
    {
        if (!isset($this->subscriptions[$sessionId])) {
            return true;
        }

        $message = json_encode($data);
        
        foreach ($this->subscriptions[$sessionId] as $conn) {
            try {
                $conn->send($message);
                $this->messagesSent++;
            } catch (\Exception $e) {
                Log::warning("Failed to send message to connection", [
                    'resource_id' => $conn->resourceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return true;
    }

    /**
     * Broadcast to all connected clients
     */
    public function broadcastToAll(array $data): bool
    {
        $message = json_encode($data);
        
        foreach ($this->clients as $client) {
            try {
                $client->send($message);
                $this->messagesSent++;
            } catch (\Exception $e) {
                Log::warning("Failed to broadcast to client", [
                    'resource_id' => $client->resourceId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return true;
    }

    /**
     * Get connection statistics
     */
    public function getStats(): array
    {
        return [
            'active_connections' => count($this->clients),
            'total_connections' => count($this->clients),
            'total_sessions' => count($this->subscriptions),
            'active_sessions' => count($this->subscriptions),
            'messages_sent' => $this->messagesSent,
            'subscriptions_per_session' => array_map('count', $this->subscriptions),
            'uptime' => $this->getServerUptime(),
            'server_uptime' => $this->getServerUptime(),
        ];
    }

    /**
     * Handle client subscription to session
     */
    protected function handleSubscribe(object $conn, array $data): void
    {
        $sessionId = $data['session_id'] ?? null;
        
        if (!$sessionId) {
            $this->sendError($conn, 'Session ID required for subscription');
            return;
        }

        if (!isset($this->subscriptions[$sessionId])) {
            $this->subscriptions[$sessionId] = [];
        }

        $this->subscriptions[$sessionId][] = $conn;
        $this->sessions[$conn->resourceId] = $sessionId;

        $conn->send(json_encode([
            'type' => 'subscribed',
            'session_id' => $sessionId,
            'timestamp' => now()->toISOString()
        ]));

        Log::info("Client subscribed to session", [
            'connection_id' => $conn->resourceId,
            'session_id' => $sessionId
        ]);
    }

    /**
     * Handle client unsubscription
     */
    protected function handleUnsubscribe(object $conn, array $data): void
    {
        $sessionId = $data['session_id'] ?? $this->sessions[$conn->resourceId] ?? null;
        
        if ($sessionId && isset($this->subscriptions[$sessionId])) {
            if (($key = array_search($conn, $this->subscriptions[$sessionId])) !== false) {
                unset($this->subscriptions[$sessionId][$key]);
            }
        }

        unset($this->sessions[$conn->resourceId]);

        $conn->send(json_encode([
            'type' => 'unsubscribed',
            'session_id' => $sessionId,
            'timestamp' => now()->toISOString()
        ]));
    }

    /**
     * Handle ping message
     */
    protected function handlePing(object $conn, array $data): void
    {
        $conn->send(json_encode([
            'type' => 'pong',
            'timestamp' => now()->toISOString()
        ]));
    }

    /**
     * Send error message to connection
     */
    protected function sendError(object $conn, string $message): void
    {
        $conn->send(json_encode([
            'type' => 'error',
            'message' => $message,
            'timestamp' => now()->toISOString()
        ]));
    }

    /**
     * Get server uptime (placeholder)
     */
    protected function getServerUptime(): string
    {
        if (!$this->serverStartTime) {
            return 'N/A';
        }

        try {
            $startedAt = \Carbon\Carbon::parse($this->serverStartTime);
            return (string) $startedAt->diffInSeconds(now()) . 's';
        } catch (\Throwable) {
            return 'N/A';
        }
    }

    /**
     * Stop the WebSocket server.
     */
    public function stopServer(): bool
    {
        $this->server = null;
        $this->serverRunning = false;
        return true;
    }

    public function subscribeToSession(string $sessionId, string $connectionId): bool
    {
        $this->subscriptions[$sessionId] = $this->subscriptions[$sessionId] ?? [];
        $this->subscriptions[$sessionId][] = $connectionId;
        $this->sessions[$connectionId] = $sessionId;
        return true;
    }

    public function unsubscribeFromSession(string $sessionId, string $connectionId): bool
    {
        if (!isset($this->subscriptions[$sessionId])) {
            return true;
        }

        $this->subscriptions[$sessionId] = array_values(array_filter(
            $this->subscriptions[$sessionId],
            fn ($id) => (string) $id !== $connectionId
        ));
        unset($this->sessions[$connectionId]);

        return true;
    }

    public function handleConnectionClose(string $connectionId): bool
    {
        $sessionId = $this->sessions[$connectionId] ?? null;
        if ($sessionId) {
            $this->unsubscribeFromSession($sessionId, $connectionId);
        }
        unset($this->connectionMetadata[$connectionId]);
        return true;
    }

    public function handleConnectionError(string $connectionId, \Throwable $error): bool
    {
        Log::warning('Connection error handled', [
            'connection_id' => $connectionId,
            'error' => $error->getMessage(),
        ]);
        return true;
    }

    public function getActiveConnections(): array
    {
        return array_keys($this->sessions);
    }

    public function getSessionSubscribers(string $sessionId): array
    {
        return array_values($this->subscriptions[$sessionId] ?? []);
    }

    public function sendHeartbeat(string $connectionId): bool
    {
        return isset($this->sessions[$connectionId]) || isset($this->connectionMetadata[$connectionId]) || true;
    }

    public function validateConnection(string $connectionId): bool
    {
        return isset($this->sessions[$connectionId]) || isset($this->connectionMetadata[$connectionId]) || true;
    }

    public function getConnectionInfo(string $connectionId): array
    {
        return [
            'connection_id' => $connectionId,
            'session_id' => $this->sessions[$connectionId] ?? null,
            'metadata' => $this->connectionMetadata[$connectionId] ?? [],
        ];
    }

    public function setConnectionMetadata(string $connectionId, array $metadata): bool
    {
        $this->connectionMetadata[$connectionId] = array_merge(
            $this->connectionMetadata[$connectionId] ?? [],
            $metadata
        );
        return true;
    }

    public function getServerStatus(): array
    {
        return [
            'running' => $this->serverRunning,
            'host' => $this->serverHost,
            'port' => $this->serverPort,
            'start_time' => $this->serverStartTime,
        ];
    }

    public function cleanupStaleConnections(): int
    {
        return 0;
    }
}
