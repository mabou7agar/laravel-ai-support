<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

enum ActionOperation: string
{
    case CREATE = 'create';
    case UPDATE = 'update';
    case DELETE = 'delete';
    case STATUS = 'status';
    case CONVERT = 'convert';
    case CUSTOM = 'custom';

    public static function normalize(string $operation): string
    {
        return self::tryFrom($operation)?->value ?? self::CUSTOM->value;
    }
}
