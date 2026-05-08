<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

enum BusinessActionRisk: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case DESTRUCTIVE = 'destructive';

    public static function normalize(string $risk): string
    {
        return self::tryFrom($risk)?->value ?? self::MEDIUM->value;
    }

    public static function requiresConfirmation(string $risk): bool
    {
        return in_array(self::normalize($risk), [
            self::MEDIUM->value,
            self::HIGH->value,
            self::DESTRUCTIVE->value,
        ], true);
    }
}

