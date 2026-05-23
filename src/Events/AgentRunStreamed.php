<?php

declare(strict_types=1);

namespace LaravelAIEngine\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class AgentRunStreamed implements ShouldBroadcastNow
{
    public function __construct(
        public readonly array $event
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $runId = (string) ($this->event['run_id'] ?? '');
        if ($runId === '') {
            return [];
        }

        $prefix = trim((string) config('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run'), '.');
        $channel = "{$prefix}.{$runId}";

        if (config('ai-agent.event_stream.broadcast.private', true)) {
            return [new PrivateChannel($channel)];
        }

        return [new Channel($channel)];
    }

    public function broadcastAs(): string
    {
        return (string) ($this->event['name'] ?? 'agent.run.streamed');
    }

    public function broadcastWith(): array
    {
        return $this->event;
    }

    public function broadcastWhen(): bool
    {
        return (bool) config('ai-agent.event_stream.broadcast.enabled', false)
            && (string) ($this->event['run_id'] ?? '') !== '';
    }

    public function broadcastConnection(): ?string
    {
        $connection = config('ai-agent.event_stream.broadcast.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function broadcastQueue(): string
    {
        return (string) config('ai-agent.event_stream.broadcast.queue', 'ai-agent-events');
    }
}
