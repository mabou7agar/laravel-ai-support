<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Events;

use Illuminate\Broadcasting\PrivateChannel;
use LaravelAIEngine\Events\AgentRunStreamed;
use LaravelAIEngine\Tests\TestCase;

class AgentRunStreamedBroadcastTest extends TestCase
{
    public function test_agent_run_streamed_event_can_broadcast_on_configured_channel(): void
    {
        config()->set('ai-agent.event_stream.broadcast.enabled', true);
        config()->set('ai-agent.event_stream.broadcast.private', true);
        config()->set('ai-agent.event_stream.broadcast.channel_prefix', 'agent-run');
        config()->set('ai-agent.event_stream.broadcast.queue', 'agent-events');

        $event = new AgentRunStreamed([
            'id' => 'event-1',
            'name' => 'run.completed',
            'run_id' => 'run-uuid',
            'payload' => ['success' => true],
        ]);

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-agent-run.run-uuid', (string) $channels[0]);
        $this->assertSame('run.completed', $event->broadcastAs());
        $this->assertSame('agent-events', $event->broadcastQueue());
        $this->assertTrue($event->broadcastWhen());
        $this->assertSame([
            'id' => 'event-1',
            'name' => 'run.completed',
            'run_id' => 'run-uuid',
            'payload' => ['success' => true],
        ], $event->broadcastWith());
    }

    public function test_agent_run_streamed_event_respects_broadcast_toggle(): void
    {
        config()->set('ai-agent.event_stream.broadcast.enabled', false);

        $event = new AgentRunStreamed([
            'name' => 'run.completed',
            'run_id' => 'run-uuid',
        ]);

        $this->assertFalse($event->broadcastWhen());
    }
}
