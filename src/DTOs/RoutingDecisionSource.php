<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

final class RoutingDecisionSource
{
    public const SESSION = 'session';
    public const EXPLICIT = 'explicit';
    public const SELECTION = 'selection';
    public const DETERMINISTIC = 'deterministic';
    public const CLASSIFIER = 'classifier';
    public const AI_ROUTER = 'ai_router';
    public const FALLBACK = 'fallback';
    public const RUNTIME = 'runtime';

    public static function all(): array
    {
        return [
            self::SESSION,
            self::EXPLICIT,
            self::SELECTION,
            self::DETERMINISTIC,
            self::CLASSIFIER,
            self::AI_ROUTER,
            self::FALLBACK,
            self::RUNTIME,
        ];
    }
}
