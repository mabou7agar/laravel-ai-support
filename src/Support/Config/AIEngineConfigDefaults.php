<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Config;

class AIEngineConfigDefaults
{
    /**
     * Load the package default config tree.
     *
     * Env-backed values live in config files so runtime package classes remain
     * config-cache friendly and never read env directly.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../../../config/ai-engine-defaults.php';

        return $defaults;
    }
}