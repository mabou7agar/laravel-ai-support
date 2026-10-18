<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

/**
 * Conversation participant handed to laravel/ai for a package user id.
 *
 * The SDK accepts any object that exposes an `id`. Using a value object keeps
 * the runtime independent of the host app's user model.
 */
final class LaravelAiParticipant
{
    public function __construct(
        public readonly string|int $id
    ) {
    }
}
