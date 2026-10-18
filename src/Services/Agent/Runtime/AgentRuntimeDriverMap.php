<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime;

use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiAgentRuntime;
use LaravelAIEngine\Services\Agent\Runtime\LaravelAi\LaravelAiSdk;

/**
 * Normalised view of the `ai-agent.runtime.drivers` class map.
 *
 * The built-in Laravel and LangGraph runtimes are always present so an install
 * that never touches the config keeps exactly the pre-existing runtime set. Host
 * applications add further AgentRuntimeContract implementations by name; the
 * manager resolves them lazily from the container and the validator checks
 * them before boot.
 */
final class AgentRuntimeDriverMap
{
    public const DEFAULT_RUNTIME = 'laravel';

    /**
     * @var array<string, class-string>
     */
    public const BUILT_IN = [
        'laravel' => LaravelAgentRuntime::class,
        'langgraph' => LangGraphAgentRuntime::class,
    ];

    /**
     * Configured runtime drivers merged over the built-ins, keyed by normalised
     * runtime name. Entries that are not a non-empty name => non-empty string
     * are dropped here; the config validator reports them.
     *
     * The optional laravel/ai-backed runtime is appended as `laravel_ai` only
     * when laravel/ai is installed and `ai-agent.runtime.laravel_ai.enabled`
     * is true; a host-configured `laravel_ai` entry wins.
     *
     * @return array<string, string>
     */
    public static function fromConfig(): array
    {
        $drivers = [];

        foreach (self::configuredEntries() as $name => $class) {
            $name = self::normalizeName($name);
            if ($name === '' || !is_string($class) || trim($class) === '') {
                continue;
            }

            $drivers[$name] = ltrim(trim($class), '\\');
        }

        $drivers += self::BUILT_IN;

        if (!isset($drivers[LaravelAiSdk::RUNTIME]) && LaravelAiSdk::shouldRegister()) {
            $drivers[LaravelAiSdk::RUNTIME] = LaravelAiAgentRuntime::class;
        }

        return $drivers;
    }

    /**
     * Raw configured entries, as written in config (not normalised).
     *
     * @return array<array-key, mixed>
     */
    public static function configuredEntries(): array
    {
        $configured = config('ai-agent.runtime.drivers', []);

        return is_array($configured) ? $configured : [];
    }

    /**
     * @return array<int, string>
     */
    public static function names(): array
    {
        return array_keys(self::fromConfig());
    }

    public static function normalizeName(mixed $name): string
    {
        return is_string($name) || is_int($name) ? strtolower(trim((string) $name)) : '';
    }

    public static function defaultName(): string
    {
        $default = self::normalizeName(config('ai-agent.runtime.default', self::DEFAULT_RUNTIME));

        return $default === '' ? self::DEFAULT_RUNTIME : $default;
    }
}
