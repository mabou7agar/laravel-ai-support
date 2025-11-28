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
        public ?string $userId = null,
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
            'user_id' => $this->userId,
            'metadata' => $this->metadata,
        ];
    }
}
