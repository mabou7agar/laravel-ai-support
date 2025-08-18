<?php

namespace LaravelAIEngine\Services\Streaming\Contracts;

/**
 * Interface for streaming implementations
 */
interface StreamingInterface
{
    /**
     * Stream AI response in real-time
     */
    public function streamResponse(string $sessionId, callable $generator, array $options = []): void;

    /**
     * Broadcast message to specific session
     */
    public function broadcastToSession(string $sessionId, array $data): void;

    /**
     * Get streaming statistics
     */
    public function getStats(): array;
}
