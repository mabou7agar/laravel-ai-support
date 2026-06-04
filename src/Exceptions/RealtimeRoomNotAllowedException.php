<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

/**
 * Thrown when a realtime session requests a LiveKit room that is not permitted by the
 * `ai-engine.realtime.livekit.allowed_rooms` allow-list. This is a client-input deny (a
 * caller trying to mint a token for a room it may not join), so the HTTP boundary renders
 * it as 422 rather than a 500.
 */
class RealtimeRoomNotAllowedException extends \RuntimeException
{
    public function __construct(public readonly string $room)
    {
        parent::__construct("LiveKit room [{$room}] is not allowed.");
    }
}
