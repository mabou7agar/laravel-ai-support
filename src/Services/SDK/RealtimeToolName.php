<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

class RealtimeToolName
{
    public static function forProvider(string $name): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($name));
        $safe = trim($safe, '_-');

        if ($safe === '') {
            return 'tool';
        }

        if (preg_match('/^[A-Za-z]/', $safe) !== 1) {
            $safe = 'tool_' . $safe;
        }

        return substr($safe, 0, 64);
    }

    public static function skillDispatchName(string $skillId): string
    {
        return 'skill.' . $skillId;
    }

    public static function skillProviderName(string $skillId): string
    {
        return self::forProvider('skill_' . $skillId);
    }

    public static function skillIdFromName(string $name): ?string
    {
        if (str_starts_with($name, 'skill.')) {
            return substr($name, 6) ?: null;
        }

        if (str_starts_with($name, 'skill_')) {
            return substr($name, 6) ?: null;
        }

        return null;
    }
}
