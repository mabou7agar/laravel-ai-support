<?php

namespace LaravelAIEngine\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AI Response Chunk Event - Fired when a chunk of AI response is ready
 */
class AIResponseChunk implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $chunk,
        public int $chunkIndex,
        public array $metadata = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.response.chunk';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'chunk' => $this->chunk,
            'chunk_index' => $this->chunkIndex,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Response Complete Event - Fired when AI response is fully generated
 */
class AIResponseComplete implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $fullResponse,
        public array $actions = [],
        public array $metadata = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.response.complete';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'full_response' => $this->fullResponse,
            'actions' => $this->actions,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Action Triggered Event - Fired when an interactive action is triggered
 */
class AIActionTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $actionId,
        public string $actionType,
        public array $payload = [],
        public array $metadata = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.action.triggered';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'action_id' => $this->actionId,
            'action_type' => $this->actionType,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Streaming Error Event - Fired when an error occurs during streaming
 */
class AIStreamingError implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $errorMessage,
        public string $errorCode = 'STREAMING_ERROR',
        public array $context = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.streaming.error';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'error_message' => $this->errorMessage,
            'error_code' => $this->errorCode,
            'context' => $this->context,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Session Started Event - Fired when a new AI session begins
 */
class AISessionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $engine,
        public string $model,
        public array $options = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.session.started';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'engine' => $this->engine,
            'model' => $this->model,
            'options' => $this->options,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Session Ended Event - Fired when an AI session ends
 */
class AISessionEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public float $duration,
        public array $stats = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("ai-session.{$this->sessionId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ai.session.ended';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'duration' => $this->duration,
            'stats' => $this->stats,
            'timestamp' => now()->toISOString(),
        ];
    }
}

/**
 * AI Failover Event - Fired when provider failover occurs
 */
class AIFailoverTriggered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $fromProvider,
        public string $toProvider,
        public string $reason,
        public array $context = []
    ) {}
}

/**
 * AI Provider Health Changed Event - Fired when provider health status changes
 */
class AIProviderHealthChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $provider,
        public string $oldStatus,
        public string $newStatus,
        public array $healthData = []
    ) {}
}
