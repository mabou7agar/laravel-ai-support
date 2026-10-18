<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

/**
 * Feature gate for the optional laravel/ai-backed agent runtime.
 *
 * The package never requires laravel/ai. The `laravel_ai` runtime is only
 * registered when the host app has installed the SDK AND switched the runtime
 * on, and only selected when the app asks for it (`agent_runtime` option or
 * `ai-agent.runtime.default`).
 */
final class LaravelAiSdk
{
    public const RUNTIME = 'laravel_ai';

    public const AGENT_CONTRACT = 'Laravel\\Ai\\Contracts\\Agent';

    public static function installed(): bool
    {
        return interface_exists(self::AGENT_CONTRACT);
    }

    public static function enabled(): bool
    {
        return (bool) config('ai-agent.runtime.laravel_ai.enabled', false);
    }

    /**
     * Whether the runtime should be added to the driver map automatically.
     */
    public static function shouldRegister(): bool
    {
        return self::enabled() && self::installed();
    }
}
