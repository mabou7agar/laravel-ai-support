<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Exceptions\RealtimeRoomNotAllowedException;
use LaravelAIEngine\Services\SDK\RealtimeSessionService;
use LaravelAIEngine\Tests\UnitTestCase;

class RealtimeSessionServiceTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-engine.realtime.livekit', [
            'url' => 'wss://voice.example.test',
            'api_key' => 'lk_key',
            'api_secret' => 'lk_secret',
        ]);
    }

    /**
     * @return array{room: string, participant_identity: string}
     */
    protected function liveKitDescriptor(array $metadata): array
    {
        $descriptor = (new RealtimeSessionService())->create([
            'provider' => 'livekit',
            'transport' => 'livekit',
            'metadata' => $metadata,
        ]);

        return $descriptor['connect']['livekit'];
    }

    public function test_livekit_preserves_client_room_and_identity_by_default(): void
    {
        // No resolver and no allow-list configured: behaviour is unchanged.
        $livekit = $this->liveKitDescriptor([
            'room' => 'client-room',
            'participant_identity' => 'client-user',
        ]);

        $this->assertSame('client-room', $livekit['room']);
        $this->assertSame('client-user', $livekit['participant_identity']);
    }

    public function test_identity_resolver_overrides_client_supplied_identity(): void
    {
        config()->set('ai-engine.realtime.livekit.identity_resolver', fn (): string => 'server-user-42');

        $livekit = $this->liveKitDescriptor([
            'room' => 'client-room',
            'participant_identity' => 'spoofed-victim',
        ]);

        $this->assertSame('server-user-42', $livekit['participant_identity']);
        // participant_name defaults to the resolved identity, not the spoofed value.
        $this->assertSame('server-user-42', $livekit['participant_name']);
    }

    public function test_room_resolver_overrides_client_supplied_room(): void
    {
        config()->set('ai-engine.realtime.livekit.room_resolver', fn (): string => 'server-room-42');

        $livekit = $this->liveKitDescriptor([
            'room' => 'someone-elses-room',
            'participant_identity' => 'client-user',
        ]);

        $this->assertSame('server-room-42', $livekit['room']);
    }

    public function test_allowed_rooms_permits_a_listed_room(): void
    {
        config()->set('ai-engine.realtime.livekit.allowed_rooms', ['room-a', 'room-b']);

        $livekit = $this->liveKitDescriptor([
            'room' => 'room-b',
            'participant_identity' => 'client-user',
        ]);

        $this->assertSame('room-b', $livekit['room']);
    }

    public function test_allowed_rooms_refuses_a_room_not_on_the_allow_list(): void
    {
        config()->set('ai-engine.realtime.livekit.allowed_rooms', ['room-a', 'room-b']);

        $this->expectException(RealtimeRoomNotAllowedException::class);

        $this->liveKitDescriptor([
            'room' => 'other-users-room',
            'participant_identity' => 'client-user',
        ]);
    }

    public function test_room_resolver_takes_precedence_over_allow_list(): void
    {
        config()->set('ai-engine.realtime.livekit.room_resolver', fn (): string => 'server-room');
        config()->set('ai-engine.realtime.livekit.allowed_rooms', ['room-a']);

        $livekit = $this->liveKitDescriptor([
            'room' => 'not-listed',
            'participant_identity' => 'client-user',
        ]);

        $this->assertSame('server-room', $livekit['room']);
    }
}
