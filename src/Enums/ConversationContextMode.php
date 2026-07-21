<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

enum ConversationContextMode: string
{
    /** Preserve the historical client-replay contract. */
    case CLIENT_REPLAY = 'client_replay';

    /** Keep the server-owned context and treat the current request as the only delta. */
    case SERVER_DELTA = 'server_delta';

    public static function resolve(mixed $value): self
    {
        if (!is_string($value)) {
            return self::CLIENT_REPLAY;
        }

        return match (strtolower(trim($value))) {
            'server', 'server_owned', 'server_delta' => self::SERVER_DELTA,
            default => self::CLIENT_REPLAY,
        };
    }
}
